<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\Cache\Cache;
use Cake\Cache\Engine\FileEngine;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\EventManager;
use Crustum\Queue\Debug\WorkerRecorder;
use Crustum\Queue\Sync\SyncDispatchListener;
use Crustum\Queue\Sync\SyncModeResolver;
use Override;

/**
 * Crustum Queue — Dispatchable / Configurable / Taggable job helpers.
 */
class QueuePlugin extends BasePlugin
{
    /**
     * @var string|null
     */
    protected ?string $name = 'Crustum/Queue';

    /**
     * @var bool
     */
    protected bool $bootstrapEnabled = true;

    /**
     * @var bool
     */
    protected bool $consoleEnabled = false;

    /**
     * @var bool
     */
    protected bool $middlewareEnabled = false;

    /**
     * @var bool
     */
    protected bool $routesEnabled = true;

    /**
     * @param \Cake\Core\PluginApplicationInterface $app Application
     * @return void
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (!Configure::check('CrustumQueue')) {
            if (file_exists(CONFIG . 'crustum_queue.php')) {
                Configure::load('crustum_queue', 'default');
            } elseif (file_exists($this->getConfigPath() . 'crustum_queue.php')) {
                Configure::load('Crustum/Queue.crustum_queue', 'default', false);
            }
        }

        if ($app instanceof ContainerApplicationInterface) {
            ContainerRegistry::setInstance($app->getContainer());
        }

        self::registerAttributeCache();
        self::registerSyncListener();
        self::registerWorkerRecorder();

        $descriptions = (array)Configure::read('DebugBar.inspectorDescriptions');
        $descriptions['Queue'] ??= 'Pushed jobs and worker executions with status.';
        Configure::write('DebugBar.inspectorDescriptions', $descriptions);
    }

    /**
     * Ensure the attribute-resolver cache config exists (self-registering plugin).
     *
     * CommandBus scans #[Handles] job attributes; the scan result is cached via
     * Cake Cache. The plugin registers its own `_queue_attributes_` config if the
     * host has not already provided one, so no manual app setup is required.
     *
     * @return void
     */
    public static function registerAttributeCache(): void
    {
        if (in_array(CommandBus::ATTRIBUTES_CACHE, Cache::configured(), true)) {
            return;
        }

        Cache::setConfig(CommandBus::ATTRIBUTES_CACHE, [
            'className' => FileEngine::class,
            'prefix' => 'queue_attributes_',
            'path' => CACHE . 'persistent' . DS,
            'serialize' => true,
            'duration' => '+1 hour',
        ]);
    }

    /**
     * Attach SyncDispatchListener once when global sync is enabled.
     *
     * When `CrustumQueue.sync` / `CRUSTUM_QUEUE_SYNC` is false (typical production),
     * the listener is not attached. Other pending listeners (decorators, emitters)
     * are unaffected.
     *
     * @return void
     */
    public static function registerSyncListener(): void
    {
        if (!SyncModeResolver::isGlobalSyncEnabled()) {
            return;
        }

        $manager = EventManager::instance();
        foreach ($manager->listeners('Crustum/Queue.Job.pending') as $listener) {
            $callable = $listener['callable'] ?? null;
            if (
                is_array($callable)
                && ($callable[0] ?? null) instanceof SyncDispatchListener
            ) {
                return;
            }
        }

        $manager->on(new SyncDispatchListener());
    }

    /**
     * Attach the toolbar worker recorder (self-registering plugin).
     *
     * Boots on every process including queue workers: the recorder itself
     * stays quiet unless the debug toolbar is installed and enabled, so
     * workers without it never notice the subscription. Attaching twice
     * is a no-op.
     *
     * @return void
     */
    public static function registerWorkerRecorder(): void
    {
        $manager = EventManager::instance();
        foreach ($manager->listeners('Processor.message.start') as $listener) {
            $callable = $listener['callable'] ?? null;
            if (
                is_array($callable)
                && ($callable[0] ?? null) instanceof WorkerRecorder
            ) {
                return;
            }
        }

        WorkerRecorder::subscribe($manager);
    }
}
