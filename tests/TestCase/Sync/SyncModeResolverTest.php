<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Sync;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Sync\SyncModeResolver;
use Crustum\Queue\Test\TestCase\Job\ExampleSyncJob;
use Crustum\Queue\Test\TestCase\Job\ExampleSyncSuppressJob;

/**
 * SyncModeResolver unit tests.
 */
class SyncModeResolverTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Configure::write('CrustumQueue.sync', false);
        Configure::write('CrustumQueue.syncOnly', []);
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testGlobalOff(): void
    {
        Configure::write('CrustumQueue.sync', false);
        $result = SyncModeResolver::resolve(ExampleSyncJob::class, [], ['config' => 'default']);
        $this->assertFalse($result['sync']);
        $this->assertSame('global_off', $result['reason']);
    }

    /**
     * @return void
     */
    public function testGlobalOn(): void
    {
        Configure::write('CrustumQueue.sync', true);
        $result = SyncModeResolver::resolve(ExampleSyncJob::class, [], ['config' => 'default']);
        $this->assertTrue($result['sync']);
        $this->assertSame('global', $result['reason']);
    }

    /**
     * @return void
     */
    public function testSuppress(): void
    {
        Configure::write('CrustumQueue.sync', true);
        $result = SyncModeResolver::resolve(ExampleSyncSuppressJob::class, [], ['config' => 'default']);
        $this->assertFalse($result['sync']);
        $this->assertSame('suppress', $result['reason']);
    }
}
