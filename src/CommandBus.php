<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\AttributeResolver\AttributeResolver;
use RuntimeException;

/**
 * Generic command dispatcher (prototype for crustum/cakephp-queue).
 *
 * Maps a {@see CommandMessage} class to a handler job class and delegates to the
 * handler's static dispatch — sync/async is decided by CrustumQueue.
 *
 * Mapping is discovered from {@see Handles} attributes via the AttributeResolver
 * (Crustum\Codex jobs only), so no manual registration is needed.
 */
final class CommandBus
{
    /**
     * Attribute resolver config name for job handler discovery.
     */
    public const HANDLERS_CONFIG = 'crustum_queue_command_handlers';

    /**
     * Cache config name used for the attribute scan result.
     */
    public const ATTRIBUTES_CACHE = '_queue_attributes_';

    /**
     * @var array<class-string, class-string>
     */
    private static array $handlers = [];

    /**
     * Register the handler job class for a command class.
     *
     * @param class-string $commandClass Command class
     * @param class-string $jobClass Handler job class
     * @return void
     */
    public static function map(string $commandClass, string $jobClass): void
    {
        self::$handlers[$commandClass] = $jobClass;
    }

    /**
     * Discover command→job handlers from {@see Handles} attributes in Job folders.
     *
     * Scans the app's src/Job (and, via getLoadedPlugins, every loaded plugin's
     * Job folder). basePath is scoped to src and vendor/tests are excluded so the
     * Finder never walks them. Pass explicit $paths / $basePath / $excludePaths to
     * restrict or widen the scan. Idempotent — calling twice rebuilds the map.
     *
     * @param list<string> $paths Relative glob patterns (default: src/Job).
     * @param string|null $basePath Base directory for app sources (default: ROOT/src).
     * @param list<string> $excludePaths Directory names / patterns to exclude.
     * @return void
     */
    public static function registerFromAttributes(
        array $paths = ['Job/*.php'],
        ?string $basePath = null,
        array $excludePaths = ['vendor', 'tests', 'build', 'tmp'],
    ): void {
        $basePath ??= ROOT . DS . 'src';
        $config = AttributeResolver::getConfig(self::HANDLERS_CONFIG);
        $sameScope = is_array($config)
            && $config['paths'] === $paths
            && ($config['basePath'] ?? null) === $basePath
            && ($config['excludePaths'] ?? null) === $excludePaths;

        if (!$sameScope) {
            if (is_array($config)) {
                AttributeResolver::clear(self::HANDLERS_CONFIG);
            }

            AttributeResolver::drop(self::HANDLERS_CONFIG);
            AttributeResolver::setConfig(self::HANDLERS_CONFIG, [
                'paths' => $paths,
                'basePath' => $basePath,
                'excludePaths' => $excludePaths,
                'cache' => self::ATTRIBUTES_CACHE,
                'validateFiles' => true,
            ]);
        }

        self::$handlers = [];

        foreach (AttributeResolver::collection(self::HANDLERS_CONFIG)->withAttribute(Handles::class) as $attributeInfo) {
            $instance = $attributeInfo->getInstance();
            if (!$instance instanceof Handles) {
                continue;
            }

            /** @var class-string $handlerClass */
            $handlerClass = $attributeInfo->className;
            self::$handlers[$instance->commandClass] = $handlerClass;
        }
    }

    /**
     * Dispatch a command to its handler job.
     *
     * @param \Crustum\Queue\CommandMessage $command Command payload
     * @param array<string, mixed> $overrides Queue config overrides
     * @return void
     * @throws \RuntimeException When no handler is registered for the command
     */
    public static function dispatch(CommandMessage $command, array $overrides = []): void
    {
        $handlerClass = self::handlerFor($command::class);
        $handlerClass::dispatch($command->payload(), $overrides);
    }

    /**
     * Dispatch a command to its handler job after a delay.
     *
     * @param \Crustum\Queue\CommandMessage $command Command payload
     * @param int $delay Delay in seconds
     * @param array<string, mixed> $overrides Queue config overrides
     * @return void
     * @throws \RuntimeException When no handler is registered for the command
     */
    public static function dispatchLater(CommandMessage $command, int $delay, array $overrides = []): void
    {
        $handlerClass = self::handlerFor($command::class);
        $handlerClass::dispatchLater($command->payload(), $delay, $overrides);
    }

    /**
     * Resolve the handler job class for a command, or throw a descriptive error.
     *
     * @param class-string $commandClass Command class
     * @return class-string Handler job class
     * @throws \RuntimeException When no handler is registered for the command
     */
    private static function handlerFor(string $commandClass): string
    {
        $handlerClass = self::$handlers[$commandClass] ?? null;
        if (!is_string($handlerClass) || !class_exists($handlerClass)) {
            throw new RuntimeException(sprintf(
                'No job handler registered for command %s; run CommandBus::registerFromAttributes() '
                . 'or CommandBus::map() first.',
                $commandClass,
            ));
        }

        return $handlerClass;
    }
}
