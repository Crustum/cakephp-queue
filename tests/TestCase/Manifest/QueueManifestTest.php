<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Crustum\PluginManifest\Manifest\Tag;
use Crustum\Queue\Manifest\QueueManifest;
use Crustum\Queue\QueuePlugin;

/**
 * QueueManifest provider test (soft manifest dependency).
 */
class QueueManifestTest extends TestCase
{
    /**
     * Manifest ops carry the expected tags.
     *
     * @return void
     */
    public function testManifestOpsContainExpectedTags(): void
    {
        $manifest = QueueManifest::manifest();
        $this->assertNotEmpty($manifest);

        $tags = array_column($manifest, 'tag');
        $this->assertContains(Tag::CONFIG, $tags);
        $this->assertContains(Tag::BOOTSTRAP, $tags);
        $this->assertContains(Tag::STAR_REPO, $tags);

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
     * Resolver discovers the standalone provider.
     *
     * @return void
     */
    public function testResolverFindsProvider(): void
    {
        $this->assertSame(QueueManifest::class, ManifestResolver::providerClass(QueuePlugin::class));
        $this->assertSame(QueueManifest::manifest(), ManifestResolver::manifestFor(QueuePlugin::class));
    }

    /**
     * Plugin class carries no manifest contract (soft dependency).
     *
     * @return void
     */
    public function testPluginHasNoManifestMethod(): void
    {
        $this->assertFalse(method_exists(QueuePlugin::class, 'manifest'));
    }
}
