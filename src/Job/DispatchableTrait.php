<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

use Cake\I18n\DateTime;
use Cake\Queue\QueueManager;
use Crustum\Queue\Event\JobDataMutators;
use Crustum\Queue\Event\JobDispatchEmitters;
use Crustum\Queue\Sync\SyncDispatchHandledException;
use Crustum\Queue\Sync\SyncJobRunner;

/**
 * Trait DispatchableTrait
 *
 * Provides static methods for self-dispatching jobs with queue configuration
 * and pending/pushed events (plugin events + optional host emitter).
 */
trait DispatchableTrait
{
    /**
     * Dispatch the job to the queue with the given data and optional overrides.
     *
     * When sync mode handles the dispatch, emits pushed (with sync => true) then
     * runs SyncJobRunner in-process and skips QueueManager::push.
     *
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $overrides Runtime configuration overrides
     * @return void
     */
    public static function dispatch(array $data = [], array $overrides = []): void
    {
        $config = array_merge(static::resolveQueueConfig(), $overrides);
        $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];

        if (is_a(static::class, TaggableInterface::class, true)) {
            $tags = static::mergeTags($tags, static::createTags($data));
        }

        $data['tags'] = static::mergeTags($tags, [static::class]);

        if (!isset($data['_uniqueId'])) {
            $data['_uniqueId'] = DateTime::now()->getTimestamp() . '-' . uniqid();
        }

        $data = JobDataMutators::prepare(static::class, $data, $config);

        try {
            static::emitJobPendingEvent($data, $config);
            QueueManager::push(static::class, $data, $config);
            static::emitJobPushedEvent($data, $config);
        } catch (SyncDispatchHandledException $syncDispatchHandledException) {
            $data = $syncDispatchHandledException->data;
            $config = $syncDispatchHandledException->config;
            $config['sync'] = true;
            static::emitJobPushedEvent($data, $config);
            SyncJobRunner::run(static::class, $data, $config);
        }
    }

    /**
     * Dispatch the job to the queue with a delay.
     *
     * @param array<string, mixed> $data Job payload
     * @param int $delaySeconds Delay in seconds
     * @param array<string, mixed> $overrides Runtime configuration overrides
     * @return void
     */
    public static function dispatchLater(array $data, int $delaySeconds, array $overrides = []): void
    {
        $overrides['delay'] = $delaySeconds;
        static::dispatch($data, $overrides);
    }

    /**
     * Resolve the queue configuration for the job.
     *
     * @return array<string, mixed>
     */
    protected static function resolveQueueConfig(): array
    {
        $defaultConfig = [
            'queue' => 'default',
            'config' => 'default',
            'maxAttempts' => 3,
            'retryDelay' => 60,
        ];

        if (is_a(static::class, ConfigurableInterface::class, true) && method_exists(static::class, 'getQueueConfig')) {
            return static::getQueueConfig();
        }

        return $defaultConfig;
    }

    /**
     * Emit a job pending event (before enqueue).
     *
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return void
     */
    protected static function emitJobPendingEvent(array $data, array $config): void
    {
        JobDispatchEmitters::emitPending(static::class, $data, $config);
    }

    /**
     * Emit a job pushed event (after enqueue).
     *
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return void
     */
    protected static function emitJobPushedEvent(array $data, array $config): void
    {
        JobDispatchEmitters::emitPushed(static::class, $data, $config);
    }

    /**
     * Build the payload for pending/pushed events.
     *
     * @param array<string, mixed> $data Job data
     * @return array<string, mixed>
     */
    protected static function buildEventPayload(array $data): array
    {
        return JobDispatchEmitters::get()->buildPayload(static::class, $data);
    }

    /**
     * Merge tag lists uniquely.
     *
     * @param array<int|string, mixed> $base Base tags
     * @param array<int|string, mixed> $extra Extra tags
     * @return array<int, string>
     */
    protected static function mergeTags(array $base, array $extra): array
    {
        $merged = [];
        foreach (array_merge($base, $extra) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            if ($tag === '') {
                continue;
            }

            $merged[$tag] = $tag;
        }

        return array_values($merged);
    }
}
