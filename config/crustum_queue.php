<?php
declare(strict_types=1);

/**
 * Crustum Queue plugin configuration (Cake Configure style).
 *
 * Loaded as Configure key "CrustumQueue".
 * Publish to the application with: bin/cake manifest install --plugin Crustum/Queue
 */
return [
    'CrustumQueue' => [
        /**
         * When true, eligible jobs run in-process (no QueueManager::push).
         * SyncDispatchListener is attached only when this is true.
         */
        'sync' => filter_var(env('CRUSTUM_QUEUE_SYNC', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Optional allow-list of job classes when sync is on.
         * Empty = all jobs eligible (except SyncSuppressibleInterface opt-outs).
         *
         * @var list<class-string>
         */
        'syncOnly' => [
            // \App\Job\CriticalPathJob::class,
        ],
    ],
];
