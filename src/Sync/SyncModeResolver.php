<?php
declare(strict_types=1);

namespace Crustum\Queue\Sync;

use Cake\Core\Configure;
use Crustum\Queue\Job\SyncSuppressibleInterface;

/**
 * Resolves whether a dispatch should run in-process (sync) or enqueue.
 *
 * Order: job suppress → global Configure → optional syncOnly allow-list
 * → sync when eligible.
 *
 * Delayed jobs (dispatchLater / delay) always stay async.
 * SyncDispatchListener is only registered when global sync is on.
 */
final class SyncModeResolver
{
    /**
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $config Queue / dispatch configuration
     * @return array{sync: bool, reason: string, allowList: bool}
     */
    public static function resolve(string $jobClass, array $data, array $config): array
    {
        if (self::hasDelay($config)) {
            return self::result(false, 'delay');
        }

        if (
            is_a($jobClass, SyncSuppressibleInterface::class, true)
            && $jobClass::suppressSync($data)
        ) {
            return self::result(false, 'suppress');
        }

        if (!self::isGlobalSyncEnabled()) {
            return self::result(false, 'global_off');
        }

        $allowList = self::syncOnly();
        if ($allowList !== [] && !in_array($jobClass, $allowList, true)) {
            return self::result(false, 'allow_list_miss', true);
        }

        return self::result(true, 'global', $allowList !== []);
    }

    /**
     * @return bool
     */
    public static function isGlobalSyncEnabled(): bool
    {
        $value = Configure::read('CrustumQueue.sync', false);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    public static function syncOnly(): array
    {
        $list = Configure::read('CrustumQueue.syncOnly', []);
        if (!is_array($list)) {
            return [];
        }

        $classes = [];
        foreach ($list as $class) {
            if (is_string($class) && $class !== '') {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param array<string, mixed> $config Dispatch config
     * @return bool
     */
    protected static function hasDelay(array $config): bool
    {
        if (!array_key_exists('delay', $config) || $config['delay'] === null) {
            return false;
        }

        return (int)$config['delay'] > 0;
    }

    /**
     * @param bool $sync Whether sync
     * @param string $reason Reason code
     * @param bool $allowList Whether allow-list was consulted
     * @return array{sync: bool, reason: string, allowList: bool}
     */
    protected static function result(bool $sync, string $reason, bool $allowList = false): array
    {
        return [
            'sync' => $sync,
            'reason' => $reason,
            'allowList' => $allowList,
        ];
    }
}
