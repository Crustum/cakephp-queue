<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Event;

use Cake\Event\EventManager;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Event\EventDispatcher;
use Crustum\Queue\Event\JobDispatchEmitters;
use Crustum\Queue\Event\JobPendingEvent;
use Crustum\Queue\Event\JobPushedEvent;
use Crustum\Queue\Test\TestCase\Job\ExampleDispatchableJob;

/**
 * EventDispatcher + dispatch event emission tests.
 */
class EventDispatcherTest extends TestCase
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
    public function testDispatchJobPendingAndPushedHelpers(): void
    {
        $dispatcher = new EventDispatcher(EventManager::instance());
        $payload = ['id' => 'abc', 'job' => ExampleDispatchableJob::class];

        $pending = $dispatcher->dispatchJobPending('default', 'default', $payload, ['config' => 'default']);
        $this->assertInstanceOf(JobPendingEvent::class, $pending);
        $this->assertSame('Crustum/Queue.Job.pending', $pending->getName());
        $this->assertSame('default', $pending->getConnection());
        $this->assertSame('default', $pending->getQueue());

        $pushed = $dispatcher->dispatchJobPushed('default', 'default', $payload, ['config' => 'default']);
        $this->assertInstanceOf(JobPushedEvent::class, $pushed);
        $this->assertSame('Crustum/Queue.Job.pushed', $pushed->getName());
    }

    /**
     * @return void
     */
    public function testDispatchEmitsPendingThenPushed(): void
    {
        $order = [];
        $manager = EventManager::instance();

        $manager->on('Crustum/Queue.Job.pending', function ($event) use (&$order): void {
            $order[] = 'pending';
            $this->assertInstanceOf(JobPendingEvent::class, $event);
            $this->assertSame(ExampleDispatchableJob::class, $event->getPayload()['job']);
        });
        $manager->on('Crustum/Queue.Job.pushed', function ($event) use (&$order): void {
            $order[] = 'pushed';
            $this->assertInstanceOf(JobPushedEvent::class, $event);
            $this->assertSame(ExampleDispatchableJob::class, $event->getPayload()['job']);
        });

        ExampleDispatchableJob::dispatch(['id' => 99]);

        $this->assertSame(['pending', 'pushed'], $order);
        $this->assertJobQueued(ExampleDispatchableJob::class);

        $manager->off('Crustum/Queue.Job.pending');
        $manager->off('Crustum/Queue.Job.pushed');
    }
}
