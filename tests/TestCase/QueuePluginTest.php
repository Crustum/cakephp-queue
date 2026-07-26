<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase;

use Cake\Core\Plugin;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Job\ConfigurableInterface;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Crustum\Queue\Job\TaggableInterface;
use Crustum\Queue\QueuePlugin;

/**
 * QueuePlugin load smoke test.
 */
class QueuePluginTest extends TestCase
{
    /**
     * @return void
     */
    public function testPluginIsLoadable(): void
    {
        $this->assertTrue(Plugin::isLoaded('Crustum/Queue') || class_exists(QueuePlugin::class));
        $this->assertTrue(interface_exists(DispatchableInterface::class));
        $this->assertTrue(interface_exists(ConfigurableInterface::class));
        $this->assertTrue(interface_exists(TaggableInterface::class));
        $this->assertTrue(trait_exists(DispatchableTrait::class));
    }
}
