<?php
declare(strict_types=1);

namespace Crustum\Queue\Debug;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;
use Crustum\DebugBar\Service\CliRecorder;
use Throwable;

/**
 * Records queue worker runs as toolbar rows.
 *
 * Self-registering worker side of the queue panel: `QueuePlugin` attaches
 * it on every boot (web and worker alike). It only wires processor events
 * to job metadata — capture itself belongs to the generic toolbar CLI
 * API, which stays quiet unless the toolbar is installed and enabled, so
 * workers without DebugBar never notice the subscription. Nothing here
 * ever throws into the worker.
 */
class WorkerRecorder implements EventListenerInterface
{
    /**
     * Listener priority running before panel handlers.
     *
     * @var int
     */
    protected const PRIORITY_FIRST = 1;

    /**
     * Listener priority running after panel handlers.
     *
     * @var int
     */
    protected const PRIORITY_LAST = 1000;

    /**
     * Attaches the recorder to the shared event manager.
     *
     * @param \Cake\Event\EventManager|null $events Manager, defaults to global.
     * @return void
     */
    public static function subscribe(?EventManager $events = null): void
    {
        ($events ?? EventManager::instance())->on(new static());
    }

    /**
     * Subscribes the recorder to processor lifecycle events.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        $first = ['priority' => static::PRIORITY_FIRST];
        $last = ['priority' => static::PRIORITY_LAST];

        return [
            'Processor.message.start' => ['callable' => 'captureStart'] + $first,
            'Processor.message.success' => ['callable' => 'captureSuccess'] + $last,
            'Processor.message.exception' => ['callable' => 'captureException'] + $last,
            'Processor.message.reject' => ['callable' => 'captureReject'] + $last,
            'Processor.message.failure' => ['callable' => 'captureFailure'] + $last,
            'Processor.message.invalid' => ['callable' => 'captureInvalid'] + $last,
        ];
    }

    /**
     * Opens one toolbar capture for a job run.
     *
     * Web requests never reach here: the recorder stays quiet outside
     * workers so in-request executions are not stored twice. Pushes the
     * job into both panels directly — panels attach while this very
     * start event is dispatching (first job after every worker restart
     * especially), so their own listeners would miss it. Repeated
     * pushes overwrite the same correlation key, never duplicating.
     *
     * @param \Cake\Event\EventInterface $event Processor start event.
     * @return void
     */
    public function captureStart(EventInterface $event): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        try {
            static::cli('open', [static::url($event), 'JOB']);
        } catch (Throwable) {
            return;
        }

        try {
            $request = static::cli('panel', ['Request']);
            if (is_object($request) && method_exists($request, 'startCommand')) {
                $request->startCommand(static::target($event), []);
            }
        } catch (Throwable) {
            return;
        }

        try {
            $queue = static::cli('panel', ['Queue']);
            if (is_object($queue) && method_exists($queue, 'captureStart')) {
                $queue->captureStart($event);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Stores one completed job run.
     *
     * @param \Cake\Event\EventInterface $event Processor success event.
     * @return void
     */
    public function captureSuccess(EventInterface $event): void
    {
        static::complete('ack', 0);
        static::finish(200);
    }

    /**
     * Stores one failed job run.
     *
     * @param \Cake\Event\EventInterface $event Processor exception event.
     * @return void
     */
    public function captureException(EventInterface $event): void
    {
        static::complete('failed', 1);
        static::finish(500);
    }

    /**
     * Stores one rejected job run.
     *
     * @param \Cake\Event\EventInterface $event Processor reject event.
     * @return void
     */
    public function captureReject(EventInterface $event): void
    {
        static::complete('reject', 2);
        static::finish(200);
    }

    /**
     * Stores one requeued job run.
     *
     * @param \Cake\Event\EventInterface $event Processor failure event.
     * @return void
     */
    public function captureFailure(EventInterface $event): void
    {
        static::complete('requeue', 3);
        static::finish(500);
    }

    /**
     * Stores one poison message run.
     *
     * @param \Cake\Event\EventInterface $event Processor invalid event.
     * @return void
     */
    public function captureInvalid(EventInterface $event): void
    {
        static::complete('reject', 1);
        static::finish(500);
    }

    /**
     * Completes the job run in the request panel.
     *
     * Guards the panel contract the same decoupled way as the CLI API
     * calls: missing toolbar or panel resolves to a no-op, workers
     * without DebugBar never notice. Processor outcomes (`ack`,
     * `reject`, `requeue`) headline the run instead of an exit code.
     *
     * @param string $outcome Processor outcome for the headline.
     * @param int $exitCode Attention code for the status tone.
     * @return void
     */
    protected static function complete(string $outcome, int $exitCode): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        try {
            $panel = static::cli('panel', ['Request']);
            if (is_object($panel) && method_exists($panel, 'finishCommand')) {
                $panel->finishCommand($outcome, $exitCode);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Stores the running capture through the toolbar CLI API.
     *
     * @param int $statusCode Synthetic status for the row.
     * @return void
     */
    protected static function finish(int $statusCode): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        try {
            static::cli('finish', [$statusCode]);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Builds the synthetic row name for a job run.
     *
     * The list already shows the `JOB` method, so the row keeps only
     * the job slug — no path prefix, no URL look.
     *
     * @param \Cake\Event\EventInterface $event Processor event.
     * @return string
     */
    protected static function url(EventInterface $event): string
    {
        $short = static::target($event);
        $position = strrpos(ltrim($short, '\\'), '\\');
        $job = $position === false ? $short : substr($short, $position + 1);

        $slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $job), '-');

        return $slug !== '' ? $slug : 'job';
    }

    /**
     * Resolves the job class from a processor event.
     *
     * Shared by the row URL and the request panel push so both name
     * the same job.
     *
     * @param \Cake\Event\EventInterface $event Processor event.
     * @return string Job class or `job` when unresolvable.
     */
    protected static function target(EventInterface $event): string
    {
        $job = 'job';
        $message = $event->getData('message');
        if (is_object($message) && method_exists($message, 'getTarget')) {
            try {
                $target = $message->getTarget();
                if (is_array($target) && is_string($target[0] ?? null) && $target[0] !== '') {
                    $job = (string)$target[0];
                }
            } catch (Throwable) {
                $job = 'job';
            }
        }

        return $job;
    }

    /**
     * Calls the toolbar CLI API without coupling to it.
     *
     * Missing toolbar classes resolve to null instead of errors, so
     * workers without DebugBar never notice the recorder.
     *
     * @param string $method `CliRecorder` static method name.
     * @param array<int, mixed> $args Call arguments.
     * @return mixed
     */
    protected static function cli(string $method, array $args = []): mixed
    {
        $callable = [CliRecorder::class, $method];
        if (!is_callable($callable)) {
            return null;
        }

        try {
            return $callable(...$args);
        } catch (Throwable) {
            return null;
        }
    }
}
