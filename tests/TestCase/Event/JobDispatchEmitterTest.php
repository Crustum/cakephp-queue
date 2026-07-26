<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Event;

use Cake\Event\EventManager;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Event\JobDispatchEmitterInterface;
use Crustum\Queue\Event\JobDispatchEmitters;
use Crustum\Queue\Event\JobPendingEvent;
use Crustum\Queue\Event\JobPushedEvent;
use Crustum\Queue\Test\TestCase\Job\ExampleDispatchableJob;

/**
 * JobDispatchEmitterInterface / JobDispatchEmitters tests.
 */
class JobDispatchEmitterTest extends TestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        JobDispatchEmitters::clear();
        EventManager::instance()->off('Crustum/Queue.Job.pending');
        EventManager::instance()->off('Crustum/Queue.Job.pushed');
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testExtraEmitterRunsAfterPluginEvents(): void
    {
        $order = [];
        $manager = EventManager::instance();
        $manager->on('Crustum/Queue.Job.pending', function () use (&$order): void {
            $order[] = 'plugin-pending';
        });
        $manager->on('Crustum/Queue.Job.pushed', function () use (&$order): void {
            $order[] = 'plugin-pushed';
        });

        $emitter = new class ($order) implements JobDispatchEmitterInterface {
            /**
             * @param array<int, string> $order Call log
             */
            public function __construct(protected array &$order)
            {
            }

            public function emitPending(string $jobClass, array $data, array $config): void
            {
                $this->order[] = 'extra-pending';
            }

            public function emitPushed(string $jobClass, array $data, array $config): void
            {
                $this->order[] = 'extra-pushed';
            }

            public function buildPayload(string $jobClass, array $data): array
            {
                return [
                    'id' => 'custom',
                    'job' => $jobClass,
                    'body' => ['class' => [$jobClass, 'execute'], 'args' => [$data]],
                    'tags' => [],
                ];
            }
        };

        JobDispatchEmitters::set($emitter);
        ExampleDispatchableJob::dispatch(['id' => 1]);

        $this->assertSame(
            [
                'plugin-pending',
                'extra-pending',
                'plugin-pushed',
                'extra-pushed',
            ],
            $order,
        );
        $this->assertJobQueued(ExampleDispatchableJob::class);
    }

    /**
     * @return void
     */
    public function testClearRemovesExtraButKeepsPluginPayload(): void
    {
        $noop = new class implements JobDispatchEmitterInterface {
            public function emitPending(string $jobClass, array $data, array $config): void
            {
            }

            public function emitPushed(string $jobClass, array $data, array $config): void
            {
            }

            public function buildPayload(string $jobClass, array $data): array
            {
                return ['id' => 'x', 'job' => $jobClass, 'body' => [], 'tags' => []];
            }
        };

        JobDispatchEmitters::set($noop);
        $this->assertNotNull(JobDispatchEmitters::extra());
        JobDispatchEmitters::clear();
        $this->assertNull(JobDispatchEmitters::extra());

        $payload = JobDispatchEmitters::get()->buildPayload(ExampleDispatchableJob::class, [
            '_uniqueId' => 'uid-1',
            'tags' => [],
        ]);
        $this->assertSame('uid-1', $payload['id']);
        $this->assertSame(ExampleDispatchableJob::class, $payload['job']);
    }

    /**
     * @return void
     */
    public function testPluginEventsStillFireWithoutExtraEmitter(): void
    {
        $seen = [];
        $manager = EventManager::instance();
        $manager->on('Crustum/Queue.Job.pending', function ($event) use (&$seen): void {
            $seen[] = $event->getName();
            $this->assertInstanceOf(JobPendingEvent::class, $event);
        });
        $manager->on('Crustum/Queue.Job.pushed', function ($event) use (&$seen): void {
            $seen[] = $event->getName();
            $this->assertInstanceOf(JobPushedEvent::class, $event);
        });

        ExampleDispatchableJob::dispatch(['id' => 2]);

        $this->assertSame(
            ['Crustum/Queue.Job.pending', 'Crustum/Queue.Job.pushed'],
            $seen,
        );
    }
}
