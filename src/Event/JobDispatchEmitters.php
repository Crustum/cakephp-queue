<?php
declare(strict_types=1);

namespace Crustum\Queue\Event;

/**
 * Registry for job-dispatch emission.
 *
 * Always emits Crustum/Queue.Job.pending|pushed via DefaultJobDispatchEmitter
 * so application listeners can observe dispatch. Optional host emitters are
 * called afterward for their own side effects — they do not replace the
 * plugin events.
 *
 * ```php
 * JobDispatchEmitters::set(new AppJobDispatchEmitter());
 * ```
 */
final class JobDispatchEmitters
{
    /**
     * @var \Crustum\Queue\Event\JobDispatchEmitterInterface|null
     */
    protected static ?JobDispatchEmitterInterface $extra = null;

    /**
     * @var \Crustum\Queue\Event\JobDispatchEmitterInterface|null
     */
    protected static ?JobDispatchEmitterInterface $default = null;

    /**
     * Register an additional host emitter.
     *
     * Pass null to clear the extra emitter; plugin events still fire.
     *
     * @param \Crustum\Queue\Event\JobDispatchEmitterInterface|null $emitter Extra emitter
     * @return void
     */
    public static function set(?JobDispatchEmitterInterface $emitter): void
    {
        self::$extra = $emitter;
    }

    /**
     * Emit pending: plugin events first, then optional host emitter.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return void
     */
    public static function emitPending(string $jobClass, array $data, array $config): void
    {
        self::default()->emitPending($jobClass, $data, $config);
        self::$extra?->emitPending($jobClass, $data, $config);
    }

    /**
     * Emit pushed: plugin events first, then optional host emitter.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return void
     */
    public static function emitPushed(string $jobClass, array $data, array $config): void
    {
        self::default()->emitPushed($jobClass, $data, $config);
        self::$extra?->emitPushed($jobClass, $data, $config);
    }

    /**
     * Payload builder for plugin events (always DefaultJobDispatchEmitter).
     *
     * @return \Crustum\Queue\Event\JobDispatchEmitterInterface
     */
    public static function get(): JobDispatchEmitterInterface
    {
        return self::default();
    }

    /**
     * Optional host emitter, if registered.
     *
     * @return \Crustum\Queue\Event\JobDispatchEmitterInterface|null
     */
    public static function extra(): ?JobDispatchEmitterInterface
    {
        return self::$extra;
    }

    /**
     * Clear the extra emitter (tests).
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$extra = null;
        self::$default = null;
    }

    /**
     * @return \Crustum\Queue\Event\JobDispatchEmitterInterface
     */
    protected static function default(): JobDispatchEmitterInterface
    {
        return self::$default ??= new DefaultJobDispatchEmitter();
    }
}
