<?php
declare(strict_types=1);

namespace Crustum\Queue\Manifest;

use Crustum\PluginManifest\Manifest\ManifestOps;
use Crustum\Queue\QueuePlugin;

/**
 * Standalone manifest provider for the Queue plugin.
 *
 * Discovered by `ManifestResolver::providerClass()` via convention.
 * Never called from plugin runtime code; only executes when the
 * installer (and this package) is present.
 */
final class QueueManifest
{
    /**
     * Get the manifest for the plugin.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__, 2);
        $plugin = ManifestOps::pluginName(QueuePlugin::class);

        return array_merge(
            ManifestOps::manifestConfig(
                $plugin,
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'crustum_queue.php',
                CONFIG . 'crustum_queue.php',
                false,
            ),
            ManifestOps::manifestBootstrapAppend(
                $plugin,
                "if (file_exists(CONFIG . 'crustum_queue.php')) {\n    Configure::load('crustum_queue', 'default');\n}",
                '// Crustum Queue Plugin Configuration',
            ),
            ManifestOps::manifestStarRepo($plugin, 'Crustum/cakephp-queue'),
        );
    }
}
