<?php
declare(strict_types=1);

namespace Crustum\Queue\Panel;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Utility\Text;
use Crustum\Queue\Event\JobDataMutators;
use Throwable;

/**
 * Queue debug panel.
 *
 * Duck-typed third-party panel for the DebugBar toolbar (loaded through its
 * legacy adapter, no dependency on toolbar classes): records pushed jobs
 * from `Crustum/Queue.Job.pushed` in web requests and job executions from
 * `Processor.message.*` in workers into one inbox. Executions correlate
 * with pushes through a `debugbar_key` the panel injects into job payloads
 * via `JobDataMutators` while loaded — the worker reads the key back from
 * message arguments. Sync dispatches arrive flagged from the pushed event
 * itself. The inbox drains on `data()` for long-lived workers.
 *
 * Cake-honest subset of the upstream inspector: only observed statuses
 * (`pushed`, `sync`, `completed`, `failed`, `rejected`, `invalid`) render.
 * Attempt counters are omitted — processor events carry no attempt number.
 */
class QueuePanel implements EventListenerInterface
{
    /**
     * Plugin alias locating the panel element.
     *
     * @var string
     */
    public string $plugin = 'Crustum/Queue';

    /**
     * Payload field carrying the toolbar correlation key.
     *
     * @var string
     */
    public const CORRELATION_FIELD = 'debugbar_key';

    /**
     * Captured jobs and executions for the current request or job run.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $inbox = [];

    /**
     * Resets captured records (long-lived workers and tests).
     *
     * @return void
     */
    public static function clear(): void
    {
        static::$inbox = [];
    }

    /**
     * Reads captured records without draining.
     *
     * Test support for panel assertions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function recorded(): array
    {
        return static::$inbox;
    }

    /**
     * Panel title shown in the inspector navigation.
     *
     * @return string
     */
    public function title(): string
    {
        return 'Queue';
    }

    /**
     * Element rendering the panel, resolved inside this plugin.
     *
     * @return string
     */
    public function elementName(): string
    {
        return 'Crustum/Queue.queue_panel';
    }

    /**
     * Groups captured records for the inspector.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $groups = static::group(static::$inbox);
        static::$inbox = [];

        $attention = 0;
        foreach ($groups as $group) {
            if (!in_array($group['status'] ?? '', ['completed', 'pushed', 'sync'], true)) {
                $attention++;
            }
        }

        return [
            'groups' => $groups,
            'summary' => ['count' => count($groups), 'attention' => $attention],
        ];
    }

    /**
     * Toolbar badge for the panel.
     *
     * @return string
     */
    public function summary(): string
    {
        $count = count(static::$inbox);

        return $count === 0 ? '' : "{$count} jobs";
    }

    /**
     * Injects the toolbar correlation key into pushed payloads.
     *
     * Runs only while the panel is loaded, so payloads stay untouched
     * when the toolbar is absent or the panel disabled.
     *
     * @return void
     */
    public function initialize(): void
    {
        JobDataMutators::register(static function (
            string $jobClass,
            array $data,
            array $config,
        ): array {
            $data[static::CORRELATION_FIELD] ??= Text::uuid();

            return $data;
        });
    }

    /**
     * Subscribes the panel to dispatch and processor events.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        return [
            'Crustum/Queue.Job.pushed' => 'capturePushed',
            'Processor.message.start' => 'captureStart',
            'Processor.message.success' => 'captureSuccess',
            'Processor.message.exception' => 'captureException',
            'Processor.message.reject' => 'captureReject',
            'Processor.message.failure' => 'captureFailure',
            'Processor.message.invalid' => 'captureInvalid',
        ];
    }

    /**
     * Records one pushed job.
     *
     * @param \Cake\Event\EventInterface $event Pushed event.
     * @return void
     */
    public function capturePushed(EventInterface $event): void
    {
        $payload = (array)$event->getData('payload');
        $options = (array)$event->getData('options');
        $job = (string)($payload['job'] ?? '');
        if ($job === '') {
            return;
        }

        $sync = (bool)($payload['sync'] ?? $options['sync'] ?? false);
        $args = static::args($payload);
        $key = (string)($payload[static::CORRELATION_FIELD] ?? $args[static::CORRELATION_FIELD] ?? '');
        if ($key === '') {
            $key = 'pushed-' . (count(static::$inbox) + 1);
        }

        static::$inbox[$key] = [
            'kind' => 'push',
            'job' => $job,
            'connection' => (string)$event->getData('connection'),
            'queue' => (string)$event->getData('queue'),
            'job_id' => static::scalar($payload['id'] ?? $payload['uuid'] ?? null),
            'delay' => static::delay($payload, $options),
            'sync' => $sync,
            'status' => $sync ? 'sync' : 'pushed',
            'started' => microtime(true),
            'duration_ms' => 0.0,
            'key' => $key,
            'args' => static::plain($args),
            'exception' => null,
            'callsite' => static::callsite(),
        ];
    }

    /**
     * Starts one execution timer.
     *
     * @param \Cake\Event\EventInterface $event Processor start event.
     * @return void
     */
    public function captureStart(EventInterface $event): void
    {
        $message = $event->getData('message');
        $key = static::messageKey($message);
        static::$inbox[$key] = [
            'kind' => 'execution',
            'job' => static::messageJob($message),
            'connection' => null,
            'queue' => null,
            'job_id' => null,
            'delay' => null,
            'sync' => false,
            'status' => 'running',
            'started' => microtime(true),
            'duration_ms' => 0.0,
            'key' => $key,
            'args' => [],
            'exception' => null,
            'callsite' => null,
        ];
    }

    /**
     * Completes one execution as completed.
     *
     * @param \Cake\Event\EventInterface $event Processor success event.
     * @return void
     */
    public function captureSuccess(EventInterface $event): void
    {
        $record = &static::execution($event);
        if ($record === null) {
            return;
        }

        $record['status'] = 'completed';
        $record['duration_ms'] = (float)($event->getData('duration') ?? 0);
    }

    /**
     * Completes one execution as failed.
     *
     * @param \Cake\Event\EventInterface $event Processor exception event.
     * @return void
     */
    public function captureException(EventInterface $event): void
    {
        $record = &static::execution($event);
        if ($record === null) {
            return;
        }

        $error = $event->getData('exception');
        $record['status'] = 'failed';
        $record['duration_ms'] = (float)($event->getData('duration') ?? 0);
        $record['exception'] = $error instanceof Throwable ? [
            'class' => $error::class,
            'message' => mb_strcut($error->getMessage(), 0, 2000),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ] : null;
    }

    /**
     * Completes one execution as rejected.
     *
     * @param \Cake\Event\EventInterface $event Processor reject event.
     * @return void
     */
    public function captureReject(EventInterface $event): void
    {
        $record = &static::execution($event);
        if ($record === null) {
            return;
        }

        $record['status'] = 'rejected';
        $record['duration_ms'] = (float)($event->getData('duration') ?? 0);
    }

    /**
     * Completes one execution as waiting for retry.
     *
     * @param \Cake\Event\EventInterface $event Processor failure event.
     * @return void
     */
    public function captureFailure(EventInterface $event): void
    {
        $record = &static::execution($event);
        if ($record === null) {
            return;
        }

        $record['status'] = 'waiting';
        $record['duration_ms'] = (float)($event->getData('duration') ?? 0);
    }

    /**
     * Records one poison message.
     *
     * @param \Cake\Event\EventInterface $event Processor invalid event.
     * @return void
     */
    public function captureInvalid(EventInterface $event): void
    {
        $message = $event->getData('message');
        $key = static::messageKey($message);
        static::$inbox[$key] = [
            'kind' => 'execution',
            'job' => static::messageJob($message),
            'connection' => null,
            'queue' => null,
            'job_id' => null,
            'delay' => null,
            'sync' => false,
            'status' => 'invalid',
            'started' => microtime(true),
            'duration_ms' => 0.0,
            'key' => $key,
            'args' => [],
            'exception' => null,
            'callsite' => null,
        ];
    }

    /**
     * Finds the execution matching a terminal event.
     *
     * @param \Cake\Event\EventInterface $event Terminal processor event.
     * @return array<string, mixed>|null
     */
    protected static function &execution(EventInterface $event): ?array
    {
        $key = static::messageKey($event->getData('message'));
        if (!isset(static::$inbox[$key])) {
            $missing = null;

            return $missing;
        }

        return static::$inbox[$key];
    }

    /**
     * Correlates processor events of one message.
     *
     * Prefers the toolbar key injected at push time; falls back to the
     * message identity for payloads pushed without the panel loaded.
     *
     * @param mixed $message Processor message.
     * @return string
     */
    protected static function messageKey(mixed $message): string
    {
        $args = static::messageArgs($message);
        $key = (string)($args[static::CORRELATION_FIELD] ?? '');
        if ($key !== '') {
            return $key;
        }

        if (is_object($message)) {
            return 'message-' . spl_object_id($message);
        }

        return 'message-' . (count(static::$inbox) + 1);
    }

    /**
     * Resolves the job class behind a processor message.
     *
     * @param mixed $message Processor message.
     * @return string
     */
    protected static function messageJob(mixed $message): string
    {
        $args = static::messageArgs($message);
        $job = (string)($args['job'] ?? '');
        if ($job !== '') {
            return $job;
        }

        if (is_object($message) && method_exists($message, 'getTarget')) {
            try {
                $target = $message->getTarget();
                if (is_array($target) && is_string($target[0] ?? null)) {
                    return (string)$target[0];
                }
            } catch (Throwable) {
                return 'Unknown job';
            }
        }

        return 'Unknown job';
    }

    /**
     * Reads message arguments without leaking failures.
     *
     * @param mixed $message Processor message.
     * @return array<string, mixed>
     */
    protected static function messageArgs(mixed $message): array
    {
        if (!is_object($message) || !method_exists($message, 'getArgument')) {
            return [];
        }

        try {
            $args = $message->getArgument();
        } catch (Throwable) {
            return [];
        }

        return is_array($args) ? $args : [];
    }

    /**
     * Reads pushed job arguments.
     *
     * @param array<string, mixed> $payload Event payload.
     * @return array<string, mixed>
     */
    protected static function args(array $payload): array
    {
        $body = (array)($payload['body'] ?? []);
        $args = (array)($body['args'] ?? []);
        $first = $args[0] ?? null;

        return is_array($first) ? $first : [];
    }

    /**
     * Reads an optional delay in seconds.
     *
     * @param array<string, mixed> $payload Event payload.
     * @param array<string, mixed> $options Event options.
     * @return int|null
     */
    protected static function delay(array $payload, array $options): ?int
    {
        foreach ([$options['delay'] ?? null, $payload['delay'] ?? null] as $candidate) {
            if (is_numeric($candidate)) {
                return (int)$candidate;
            }
        }

        return null;
    }

    /**
     * Keeps scalar values as strings, drops the rest.
     *
     * @param mixed $value Candidate value.
     * @return string|null
     */
    protected static function scalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string)$value);

        return $text === '' ? null : $text;
    }

    /**
     * Normalizes arbitrary values into JSON-safe plain data.
     *
     * @param mixed $value Value to normalize.
     * @param int $depth Remaining recursion depth.
     * @return mixed
     */
    protected static function plain(mixed $value, int $depth = 3): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($depth < 1) {
                return sprintf('[%d items]', count($value));
            }

            $plain = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= 100) {
                    $plain['...'] = sprintf('%d more items', count($value) - 100);

                    break;
                }

                $plain[$key] = static::plain($item, $depth - 1);
            }

            return $plain;
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => mb_strcut($value->getMessage(), 0, 500),
            ];
        }

        return get_debug_type($value);
    }

    /**
     * Captures the userland push callsite.
     *
     * Skips queue internals and framework paths so the first frame points
     * at the application code that dispatched the job.
     *
     * @return array<string, mixed>|null
     */
    protected static function callsite(): ?array
    {
        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = (string)($frame['class'] ?? '');
            if (str_starts_with($class, 'Crustum\\Queue\\')) {
                continue;
            }

            $file = $frame['file'] ?? '';
            if ($file === '') {
                continue;
            }

            $vendor = str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
            $frames[] = [
                'file' => $file,
                'line' => $frame['line'] ?? 0,
                'function' => $class !== ''
                    ? $class . ($frame['type'] ?? '') . $frame['function']
                    : $frame['function'],
                'vendor' => $vendor,
            ];

            if (count($frames) >= 24) {
                break;
            }
        }

        if ($frames === []) {
            return null;
        }

        $first = $frames[0];
        foreach ($frames as $frame) {
            if ($frame['vendor'] === false) {
                $first = $frame;

                break;
            }
        }

        return ['file' => $first['file'], 'line' => $first['line'], 'frames' => $frames];
    }

    /**
     * Short class name without namespace.
     *
     * @param string $class Fully qualified class name.
     * @return string
     */
    protected static function short(string $class): string
    {
        $class = ltrim($class, '\\');
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * Groups records by job class plus correlation key.
     *
     * @param array<string, array<string, mixed>> $records Captured records.
     * @return array<int, array<string, mixed>>
     */
    protected static function group(array $records): array
    {
        $buckets = [];
        foreach ($records as $record) {
            $key = ($record['job'] ?? '?') . '|' . ($record['key'] ?? '?');
            $buckets[$key][] = $record;
        }

        $groups = [];
        foreach (array_values($buckets) as $index => $bucket) {
            $first = (array)$bucket[0];
            $status = (string)($first['status'] ?? 'pushed');
            $statusLabel = match ($status) {
                'pushed' => 'Pushed',
                'sync' => 'Sync',
                'running' => 'Running',
                'completed' => 'Completed',
                'failed' => 'Failed',
                'rejected' => 'Rejected',
                'waiting' => 'Waiting for retry',
                'invalid' => 'Invalid',
                default => $status,
            };
            $job = (string)$first['job'];
            $recipient = (string)($first['connection'] ?? '') !== ''
                ? (string)$first['connection']
                : 'default';
            $filterTokens = [in_array($status, ['completed', 'pushed', 'sync'], true) ? 'sent' : 'attention'];
            $filterFacets = [];
            $toToken = 'to:' . static::slug($recipient);
            $filterTokens[] = $toToken;
            $filterFacets[$toToken] = $recipient;
            $classToken = 'class:' . static::slug(static::short($job));
            $filterTokens[] = $classToken;
            $filterFacets[$classToken] = static::short($job);
            $groups[] = [
                'group_id' => 'queue-' . ($index + 1),
                'job' => $job,
                'label' => static::short($job),
                'status' => $status,
                'status_label' => $statusLabel,
                'kind' => (string)($first['kind'] ?? 'push'),
                'connection' => (string)($first['connection'] ?? ''),
                'queue' => (string)($first['queue'] ?? ''),
                'job_id' => $first['job_id'] ?? null,
                'delay' => $first['delay'] ?? null,
                'duration_ms' => (float)($first['duration_ms'] ?? 0),
                'duration_label' => static::formatDuration((float)($first['duration_ms'] ?? 0)),
                'key' => (string)($first['key'] ?? ''),
                'args' => (array)($first['args'] ?? []),
                'exception' => $first['exception'] ?? null,
                'callsite' => $first['callsite'] ?? null,
                'filter' => implode(' ', $filterTokens),
                'filter_facets' => $filterFacets,
                'search' => mb_strtolower(implode(' ', array_filter([
                    $job,
                    (string)($first['connection'] ?? ''),
                    (string)($first['queue'] ?? ''),
                    $statusLabel,
                ]))),
            ];
        }

        return $groups;
    }

    /**
     * URL-safe filter token fragment.
     *
     * @param string $value Label text.
     * @return string
     */
    protected static function slug(string $value): string
    {
        $slug = mb_strtolower(trim($value));

        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    }

    /**
     * Formats milliseconds for list rows.
     *
     * @param float $milliseconds Duration.
     * @return string
     */
    protected static function formatDuration(float $milliseconds): string
    {
        if ($milliseconds >= 1000) {
            return number_format($milliseconds / 1000, 2) . ' s';
        }

        if ($milliseconds >= 1) {
            return number_format($milliseconds, $milliseconds >= 100 ? 0 : 1) . ' ms';
        }

        return number_format($milliseconds * 1000, 0) . ' µs';
    }
}
