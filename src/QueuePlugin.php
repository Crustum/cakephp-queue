<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\EventManager;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Crustum\Queue\Sync\SyncDispatchListener;
use Crustum\Queue\Sync\SyncModeResolver;
use Override;

/**
 * Crustum Queue — Dispatchable / Configurable / Taggable job helpers.
 *
 * @uses \Crustum\PluginManifest\Manifest\ManifestTrait
 */
class QueuePlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

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
    protected bool $routesEnabled = false;

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

        self::registerSyncListener();
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
     * Get the manifest for the plugin.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__);

        return array_merge(
            static::manifestConfig(
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'crustum_queue.php',
                CONFIG . 'crustum_queue.php',
                false,
            ),
            static::manifestBootstrapAppend(
                "if (file_exists(CONFIG . 'crustum_queue.php')) {\n    Configure::load('crustum_queue', 'default');\n}",
                '// Crustum Queue Plugin Configuration',
            ),
            static::manifestStarRepo('Crustum/cakephp-queue'),
        );
    }
}
