<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\Queue\Job\ConfigurableInterface;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Crustum\Queue\Job\SyncSuppressibleInterface;
use Crustum\Queue\Job\TaggableInterface;
use Crustum\Queue\QueuePlugin;
use Crustum\Queue\Sync\SyncJobRunner;
use TestApp\Application;

/**
 * QueuePlugin load smoke test.
 */
class QueuePluginTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Configure::write('CrustumQueue', [
            'sync' => false,
            'syncOnly' => [],
        ]);
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testPluginIsLoadable(): void
    {
        $this->assertTrue(Plugin::isLoaded('Crustum/Queue') || class_exists(QueuePlugin::class));
        $this->assertTrue(interface_exists(DispatchableInterface::class));
        $this->assertTrue(interface_exists(ConfigurableInterface::class));
        $this->assertTrue(interface_exists(TaggableInterface::class));
        $this->assertTrue(interface_exists(SyncSuppressibleInterface::class));
        $this->assertTrue(trait_exists(DispatchableTrait::class));
        $this->assertTrue(class_exists(SyncJobRunner::class));
    }

    /**
     * @return void
     */
    public function testImplementsManifestAndPublishesConfig(): void
    {
        $this->assertInstanceOf(ManifestInterface::class, new QueuePlugin());
        $manifest = QueuePlugin::manifest();
        $this->assertNotEmpty($manifest);

        $sources = array_column($manifest, 'source');
        $this->assertTrue(array_any(
            $sources,
            static fn(mixed $source): bool => is_string($source) && str_ends_with(
                str_replace('\\', '/', $source),
                'config/crustum_queue.php',
            ),
        ));
    }

    /**
     * @return void
     */
    public function testBootstrapLoadsPluginConfigWhenMissing(): void
    {
        Configure::delete('CrustumQueue');
        $this->assertFalse(Configure::check('CrustumQueue'));

        $plugin = new QueuePlugin();
        $plugin->bootstrap(new Application(CONFIG));

        $this->assertTrue(Configure::check('CrustumQueue'));
        $this->assertFalse((bool)Configure::read('CrustumQueue.sync'));
        $this->assertSame([], Configure::read('CrustumQueue.syncOnly'));
    }
}
