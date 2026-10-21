<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Event\EventManager;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;

/**
 * DispatchableTrait Test Case
 */
class DispatchableTraitTest extends TestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    public function testDispatchPushesJob(): void
    {
        ExampleDispatchableJob::dispatch(['id' => 42]);

        $this->assertJobQueued(ExampleDispatchableJob::class);
        $jobs = $this->getQueuedJobsByClass(ExampleDispatchableJob::class);
        $this->assertSame(42, $jobs[0]['data']['id']);
    }

    /**
     * Envelope lives in the pushed event payload metadata, never in data.
     *
     * @return void
     */
    public function testDispatchAddsClassTagAndUniqueId(): void
    {
        $seen = [];
        EventManager::instance()->on(
            'Crustum/Queue.Job.pushed',
            function ($event) use (&$seen): void {
                $seen = (array)$event->getData('payload');
            },
        );

        ExampleDispatchableJob::dispatch(['id' => 7]);

        EventManager::instance()->off('Crustum/Queue.Job.pushed');

        $jobs = $this->getQueuedJobsByClass(ExampleDispatchableJob::class);
        $this->assertNotEmpty($jobs);
        $data = $jobs[0]['data'];

        $this->assertArrayNotHasKey('tags', $data);
        $this->assertArrayNotHasKey('_uniqueId', $data);
        $this->assertSame(7, $data['id']);

        $this->assertContains(ExampleDispatchableJob::class, $seen['metadata']['tags'] ?? []);
        $this->assertNotSame('', $seen['metadata']['_uniqueId'] ?? '');
    }

    /**
     * @return void
     */
    public function testConfigurableJobUsesNamedQueue(): void
    {
        ExampleConfigurableJob::dispatch(['id' => 1]);

        $this->assertJobQueuedToQueue('corpus-embed', ExampleConfigurableJob::class);
    }

    /**
     * @return void
     */
    public function testTaggableJobMergesCustomTags(): void
    {
        $seen = [];
        EventManager::instance()->on(
            'Crustum/Queue.Job.pushed',
            function ($event) use (&$seen): void {
                $seen = (array)$event->getData('payload');
            },
        );

        ExampleTaggableJob::dispatch(['package' => 'cakephp/cakephp']);

        EventManager::instance()->off('Crustum/Queue.Job.pushed');

        $this->assertJobQueued(ExampleTaggableJob::class);
        $tags = $seen['metadata']['tags'] ?? [];

        $this->assertContains('package:cakephp/cakephp', $tags);
        $this->assertContains(ExampleTaggableJob::class, $tags);
    }

    /**
     * @return void
     */
    public function testDispatchLaterSetsDelay(): void
    {
        ExampleDispatchableJob::dispatchLater(['id' => 9], 30);

        $this->assertJobQueuedWithDelay(ExampleDispatchableJob::class, 30);
    }
}
