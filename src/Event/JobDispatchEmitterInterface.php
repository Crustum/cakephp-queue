<?php
declare(strict_types=1);

namespace Crustum\Queue\Event;

/**
 * Optional host-side job dispatch emitter for app-specific side effects.
 *
 * Plugin events (Crustum/Queue.Job.pending|pushed) always fire via
 * DefaultJobDispatchEmitter. Implementations registered with
 * JobDispatchEmitters::set() run afterward and only add host behavior —
 * they do not replace the plugin events.
 *
 * Example (application or plugin bootstrap):
 * ```php
 * JobDispatchEmitters::set(new AppJobDispatchEmitter());
 * ```
 */
interface JobDispatchEmitterInterface
{
    /**
     * Emit host pending side effects (after plugin Crustum/Queue.Job.pending).
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return void
     */
    public function emitPending(string $jobClass, array $data, array $config): void;

    /**
     * Emit host pushed side effects (after plugin Crustum/Queue.Job.pushed).
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return void
     */
    public function emitPushed(string $jobClass, array $data, array $config): void;

    /**
     * Build a payload for host emission (host may use its own shape).
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @return array<string, mixed>
     */
    public function buildPayload(string $jobClass, array $data): array;
}
