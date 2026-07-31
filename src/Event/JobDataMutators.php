<?php
declare(strict_types=1);

namespace Crustum\Queue\Event;

/**
 * Mutate job data after tags/_uniqueId and before pending emit + push.
 *
 * Host packages (e.g. Speculum) can inject fields such as `speculum_uuid`
 * into the payload that will be stored on the queue message.
 *
 * ```php
 * JobDataMutators::register(function (string $jobClass, array $data, array $config): array {
 *     $data['speculum_uuid'] = $uuid;
 *
 *     return $data;
 * });
 * ```
 */
final class JobDataMutators
{
    /**
     * @var list<callable(class-string, array<string, mixed>, array<string, mixed>): array<string, mixed>>
     */
    protected static array $mutators = [];

    /**
     * Register a data mutator (FIFO).
     *
     * @param callable(class-string, array<string, mixed>, array<string, mixed>): array<string, mixed> $mutator Mutator
     * @return void
     */
    public static function register(callable $mutator): void
    {
        self::$mutators[] = $mutator;
    }

    /**
     * Apply all mutators and return the final job data.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @param array<string, mixed> $config Queue configuration
     * @return array<string, mixed>
     */
    public static function prepare(string $jobClass, array $data, array $config): array
    {
        foreach (self::$mutators as $mutator) {
            $data = $mutator($jobClass, $data, $config);
        }

        return $data;
    }

    /**
     * Clear mutators (tests).
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$mutators = [];
    }

    /**
     * @return list<callable(class-string, array<string, mixed>, array<string, mixed>): array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$mutators;
    }
}
