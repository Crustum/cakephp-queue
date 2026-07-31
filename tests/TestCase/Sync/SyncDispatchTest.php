<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Sync;

use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Queue\QueueManager;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\ContainerRegistry;
use Crustum\Queue\Event\JobDispatchEmitterInterface;
use Crustum\Queue\Event\JobDispatchEmitters;
use Crustum\Queue\QueuePlugin;
use Crustum\Queue\Sync\SyncModeResolver;
use Crustum\Queue\Test\TestCase\Job\ExampleDispatchableJob;
use Crustum\Queue\Test\TestCase\Job\ExampleDiSyncJob;
use Crustum\Queue\Test\TestCase\Job\ExampleSyncJob;
use Crustum\Queue\Test\TestCase\Job\ExampleSyncSuppressJob;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * Sync dispatch mode feature tests.
 */
class SyncDispatchTest extends TestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CrustumQueue.sync', false);
        Configure::write('CrustumQueue.syncOnly', []);
        ExampleSyncJob::$executed = [];
        ExampleSyncJob::$behavior = null;
        ExampleSyncSuppressJob::$executed = [];
        ExampleDiSyncJob::$tokens = [];
        RecordingProcessorListener::reset();
        ContainerRegistry::clear();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Configure::write('CrustumQueue.sync', false);
        Configure::write('CrustumQueue.syncOnly', []);
        JobDispatchEmitters::clear();
        ContainerRegistry::clear();
        EventManager::instance()->off('Crustum/Queue.Job.pending');
        EventManager::instance()->off('Crustum/Queue.Job.pushed');
        $this->restoreDefaultQueueConfig();
        parent::tearDown();
    }

    /**
     * Enable global sync and attach SyncDispatchListener for this test.
     *
     * @return void
     */
    protected function enableSync(): void
    {
        Configure::write('CrustumQueue.sync', true);
        QueuePlugin::registerSyncListener();
    }

    /**
     * @return void
     */
    protected function restoreDefaultQueueConfig(): void
    {
        if (in_array('default', QueueManager::configured(), true)) {
            QueueManager::drop('default');
        }

        QueueManager::setConfig('default', [
            'url' => 'null:',
            'queue' => 'default',
        ]);
    }

    /**
     * @return void
     */
    public function testAsyncByDefault(): void
    {
        ExampleSyncJob::dispatch(['id' => 1]);

        $this->assertJobQueued(ExampleSyncJob::class);
        $this->assertSame([], ExampleSyncJob::$executed);
    }

    /**
     * @return void
     */
    public function testPendingDecoratorListenersWorkWhenSyncOff(): void
    {
        $order = [];
        EventManager::instance()->on('Crustum/Queue.Job.pending', function () use (&$order): void {
            $order[] = 'decorator-pending';
        });
        EventManager::instance()->on('Crustum/Queue.Job.pushed', function () use (&$order): void {
            $order[] = 'decorator-pushed';
        });

        ExampleSyncJob::dispatch(['id' => 2]);

        $this->assertSame(['decorator-pending', 'decorator-pushed'], $order);
        $this->assertJobQueued(ExampleSyncJob::class);
        $this->assertSame([], ExampleSyncJob::$executed);
    }

    /**
     * @return void
     */
    public function testGlobalSyncRunsInProcessAndSkipsPush(): void
    {
        $this->enableSync();
        $order = [];
        $pushedSync = null;

        EventManager::instance()->on('Crustum/Queue.Job.pending', function () use (&$order): void {
            $order[] = 'pending';
        });
        EventManager::instance()->on(
            'Crustum/Queue.Job.pushed',
            function (EventInterface $event) use (&$order, &$pushedSync): void {
                $order[] = 'pushed';
                $pushedSync = $event->getData('options')['sync'] ?? null;
            },
        );

        ExampleSyncJob::dispatch(['id' => 99]);

        $this->assertCount(1, ExampleSyncJob::$executed);
        $this->assertSame(99, ExampleSyncJob::$executed[0]['id']);
        $this->assertTrue($pushedSync);
        $this->assertSame(['pending', 'pushed'], $order);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleSyncJob::class));
    }

    /**
     * @return void
     */
    public function testSuppressKeepsAsyncWhenGlobalSyncOn(): void
    {
        $this->enableSync();

        ExampleSyncSuppressJob::dispatch(['id' => 3]);

        $this->assertJobQueued(ExampleSyncSuppressJob::class);
        $this->assertSame([], ExampleSyncSuppressJob::$executed);
    }

    /**
     * @return void
     */
    public function testSyncOnlyAllowList(): void
    {
        $this->enableSync();
        Configure::write('CrustumQueue.syncOnly', [ExampleSyncJob::class]);

        ExampleSyncJob::dispatch(['id' => 6]);
        ExampleDispatchableJob::dispatch(['id' => 7]);

        $this->assertCount(1, ExampleSyncJob::$executed);
        $this->assertJobQueued(ExampleDispatchableJob::class);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleSyncJob::class));
    }

    /**
     * @return void
     */
    public function testDispatchLaterStaysAsyncWhenGlobalSyncOn(): void
    {
        $this->enableSync();

        ExampleSyncJob::dispatchLater(['id' => 8], 15);

        $this->assertJobQueuedWithDelay(ExampleSyncJob::class, 15);
        $this->assertSame([], ExampleSyncJob::$executed);
        $this->assertFalse(SyncModeResolver::resolve(
            ExampleSyncJob::class,
            ['id' => 8],
            ['delay' => 15, 'config' => 'default', 'queue' => 'default'],
        )['sync']);
    }

    /**
     * @return void
     */
    public function testDiContainerResolvesJob(): void
    {
        $this->enableSync();
        $container = new Container();
        $container->add(ExampleDiSyncJob::class)->addArgument('from-di');
        ContainerRegistry::setInstance($container);

        ExampleDiSyncJob::dispatch(['id' => 9]);

        $this->assertSame(['from-di'], ExampleDiSyncJob::$tokens);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleDiSyncJob::class));
    }

    /**
     * @return void
     */
    public function testFailureAfterPushedPropagates(): void
    {
        $this->enableSync();
        ExampleSyncJob::$behavior = 'throw';
        $pushed = false;
        EventManager::instance()->on('Crustum/Queue.Job.pushed', function () use (&$pushed): void {
            $pushed = true;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sync job boom');

        try {
            ExampleSyncJob::dispatch(['id' => 10]);
        } finally {
            $this->assertTrue($pushed);
            $this->assertSame([], $this->getQueuedJobsByClass(ExampleSyncJob::class));
        }
    }

    /**
     * @return void
     */
    public function testRejectSurfacesToCaller(): void
    {
        $this->enableSync();
        ExampleSyncJob::$behavior = Processor::REJECT;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sync job did not acknowledge');

        ExampleSyncJob::dispatch(['id' => 11]);
    }

    /**
     * @return void
     */
    public function testApplicationPendingEmitterStillRunsOnSync(): void
    {
        $this->enableSync();
        $order = [];

        JobDispatchEmitters::set(new class ($order) implements JobDispatchEmitterInterface {
            /**
             * @param array<int, string> $order Call log
             */
            public function __construct(protected array &$order)
            {
            }

            public function emitPending(string $jobClass, array $data, array $config): void
            {
                $this->order[] = 'application-pending';
            }

            public function emitPushed(string $jobClass, array $data, array $config): void
            {
                $this->order[] = 'application-pushed';
                $this->order[] = empty($config['sync']) ? 'application-pushed-async' : 'application-pushed-sync';
            }

            public function buildPayload(string $jobClass, array $data): array
            {
                return [];
            }
        });

        EventManager::instance()->on('Crustum/Queue.Job.pending', function () use (&$order): void {
            $order[] = 'plugin-pending';
        });
        EventManager::instance()->on('Crustum/Queue.Job.pushed', function () use (&$order): void {
            $order[] = 'plugin-pushed';
        });

        ExampleSyncJob::dispatch(['id' => 12]);

        $this->assertSame(
            [
                'plugin-pending',
                'application-pending',
                'plugin-pushed',
                'application-pushed',
                'application-pushed-sync',
            ],
            $order,
        );
        $this->assertCount(1, ExampleSyncJob::$executed);
    }

    /**
     * @return void
     */
    public function testProcessorEventsIncludeSyncFlag(): void
    {
        $this->enableSync();
        QueueManager::drop('default');
        QueueManager::setConfig('default', [
            'url' => 'null:',
            'queue' => 'default',
            'listener' => RecordingProcessorListener::class,
        ]);

        ExampleSyncJob::dispatch(['id' => 14]);

        $names = array_column(RecordingProcessorListener::$events, 'name');
        $this->assertContains('Processor.message.seen', $names);
        $this->assertContains('Processor.message.start', $names);
        $this->assertContains('Processor.message.success', $names);
        foreach (RecordingProcessorListener::$events as $event) {
            $this->assertTrue($event['sync']);
        }
    }

    /**
     * @return void
     */
    public function testSyncExceptionDoesNotEscapeDispatch(): void
    {
        $this->enableSync();

        ExampleSyncJob::dispatch(['id' => 13]);

        $this->assertCount(1, ExampleSyncJob::$executed);
    }
}
