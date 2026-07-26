<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

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
     * @return void
     */
    public function testDispatchAddsClassTagAndUniqueId(): void
    {
        ExampleDispatchableJob::dispatch(['id' => 7]);

        $jobs = $this->getQueuedJobsByClass(ExampleDispatchableJob::class);
        $this->assertNotEmpty($jobs);
        $data = $jobs[0]['data'];

        $this->assertContains(ExampleDispatchableJob::class, $data['tags']);
        $this->assertArrayHasKey('_uniqueId', $data);
        $this->assertNotSame('', $data['_uniqueId']);
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
        ExampleTaggableJob::dispatch(['package' => 'cakephp/cakephp']);

        $jobs = $this->getQueuedJobsByClass(ExampleTaggableJob::class);
        $tags = $jobs[0]['data']['tags'];

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
