<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Event;

use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Event\JobDataMutators;
use Crustum\Queue\Event\JobDispatchEmitters;
use Crustum\Queue\Test\TestCase\Job\ExampleDispatchableJob;

/**
 * JobDataMutators tests.
 */
class JobDataMutatorsTest extends TestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        JobDataMutators::clear();
        JobDispatchEmitters::clear();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testPrepareRunsMutatorsInOrder(): void
    {
        JobDataMutators::register(function (string $jobClass, array $data, array $config): array {
            $data['a'] = 1;

            return $data;
        });
        JobDataMutators::register(function (string $jobClass, array $data, array $config): array {
            $data['b'] = ($data['a'] ?? 0) + 1;

            return $data;
        });

        $prepared = JobDataMutators::prepare(ExampleDispatchableJob::class, ['x' => 0], [
            'config' => 'default',
            'queue' => 'default',
        ]);

        $this->assertSame(1, $prepared['a']);
        $this->assertSame(2, $prepared['b']);
        $this->assertSame(0, $prepared['x']);
    }

    /**
     * @return void
     */
    public function testDispatchIncludesMutatedDataInQueuedPayload(): void
    {
        JobDataMutators::register(function (string $jobClass, array $data, array $config): array {
            $data['speculum_uuid'] = 'uuid-from-mutator';

            return $data;
        });

        ExampleDispatchableJob::dispatch(['id' => 5]);

        $jobs = $this->getQueuedJobsByClass(ExampleDispatchableJob::class);
        $this->assertNotEmpty($jobs);
        $this->assertSame(5, $jobs[0]['data']['id']);
        $this->assertSame('uuid-from-mutator', $jobs[0]['data']['speculum_uuid']);
    }
}
