<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

use Cake\I18n\DateTime;
use Cake\Queue\Dto\DtoManager;
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
     * DTO/command objects are serialized at entry (never mutated); the envelope
     * (tags, _uniqueId) travels in $config['metadata'], never inside $data.
     *
     * @param object|array<string, mixed> $data Job payload (array or DTO/command object)
     * @param array<string, mixed> $overrides Runtime configuration overrides
     * @return void
     */
    public static function dispatch(array|object $data = [], array $overrides = []): void
    {
        $config = array_merge(static::resolveQueueConfig(), $overrides);

        $dtoClass = is_object($data) ? $data::class : null;
        if (is_object($data)) {
            $data = DtoManager::serialize($data);
        } elseif (!empty($config['dtoClass'])) {
            $dtoClass = is_string($config['dtoClass']) ? $config['dtoClass'] : null;
            unset($config['dtoClass']);
            if ($dtoClass !== null) {
                $config['dtoClass'] = $dtoClass;
            }
        }

        /** @var array<string, mixed> $data */

        $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        unset($data['tags']);
        unset($data['_uniqueId']);

        if (is_a(static::class, TaggableInterface::class, true)) {
            $taggableClass = static::class;
            $tags = static::mergeTags($tags, $taggableClass::createTags($data));
        }

        $metadata = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
        $metadataTags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];
        $metadata['tags'] = static::mergeTags(
            static::mergeTags($metadataTags, $tags),
            [static::class],
        );
        $metadata['_uniqueId'] ??= DateTime::now()->getTimestamp() . '-' . uniqid();
        $config['metadata'] = $metadata;

        if ($dtoClass !== null) {
            $config['dtoClass'] = $dtoClass;
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
     * @param object|array<string, mixed> $data Job payload (array or DTO/command object)
     * @param int $delaySeconds Delay in seconds
     * @param array<string, mixed> $overrides Runtime configuration overrides
     * @return void
     */
    public static function dispatchLater(array|object $data, int $delaySeconds, array $overrides = []): void
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
