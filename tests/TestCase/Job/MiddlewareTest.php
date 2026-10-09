<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\Message;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Contract\MiddlewareInterface;
use Crustum\Queue\Middleware\Skip;
use Crustum\Queue\MiddlewareRunner;
use Crustum\Queue\Processor\MiddlewareProcessor;
use Enqueue\Null\NullContext;
use Enqueue\Null\NullMessage;
use Interop\Queue\Processor;
use Psr\Log\NullLogger;

/**
 * Job middleware pipeline tests (Phase 0 POC).
 *
 * Proves the shared `MiddlewareRunner` core + `Skip` guard + thin
 * `MiddlewareProcessor` (resolve-once shape) + `MiddlewareJob` base.
 */
class MiddlewareTest extends TestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        ExampleMiddlewareJob::$executed = [];
        ExampleMiddlewareJob::$skip = false;
        ExampleMiddlewareBaseJob::$executed = [];
        ExampleMiddlewareBaseJob::$skip = false;
    }

    /**
     * @return void
     */
    public function testRunnerPreservesOnionOrder(): void
    {
        $order = [];
        $message = $this->buildMessage(ExampleMiddlewareJob::class, ['id' => 1]);
        $job = new ExampleMiddlewareJob();

        $result = MiddlewareRunner::run(
            $job,
            $message,
            static function (object $job, Message $message) use (&$order): string {
                $order[] = 'core';

                return Processor::ACK;
            },
            [
                new class ($order) implements MiddlewareInterface {
                    /**
                     * @param list<string> $order Call log
                     */
                    public function __construct(protected array &$order)
                    {
                    }

                    public function handle(object $job, Message $message, callable $next): mixed
                    {
                        $this->order[] = 'first-before';

                        $result = $next($job, $message);

                        $this->order[] = 'first-after';

                        return $result;
                    }
                },
                new class ($order) implements MiddlewareInterface {
                    /**
                     * @param list<string> $order Call log
                     */
                    public function __construct(protected array &$order)
                    {
                    }

                    public function handle(object $job, Message $message, callable $next): mixed
                    {
                        $this->order[] = 'second-before';

                        $result = $next($job, $message);

                        $this->order[] = 'second-after';

                        return $result;
                    }
                },
            ],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertSame(
            ['first-before', 'second-before', 'core', 'second-after', 'first-after'],
            $order,
        );
    }

    /**
     * @return void
     */
    public function testSkipWhenSkipsWithoutCallingNext(): void
    {
        $message = $this->buildMessage(ExampleMiddlewareJob::class, ['id' => 2]);
        $job = new ExampleMiddlewareJob();
        $nextCalled = false;

        $result = MiddlewareRunner::run(
            $job,
            $message,
            static function () use (&$nextCalled): string {
                $nextCalled = true;

                return Processor::ACK;
            },
            [Skip::when(true)],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($nextCalled);
    }

    /**
     * @return void
     */
    public function testSkipUnlessAndCallableConditions(): void
    {
        $message = $this->buildMessage(ExampleMiddlewareJob::class, ['dry_run' => true]);
        $job = new ExampleMiddlewareJob();
        $calls = 0;
        $core = static function () use (&$calls): string {
            $calls++;

            return Processor::ACK;
        };

        MiddlewareRunner::run($job, $message, $core, [Skip::unless(true)]);
        $this->assertSame(1, $calls);

        MiddlewareRunner::run($job, $message, $core, [Skip::unless(false)]);
        $this->assertSame(1, $calls);

        MiddlewareRunner::run(
            $job,
            $message,
            $core,
            [Skip::when(
                static fn(object $job, Message $message): bool => $message->getArgument('dry_run') === true,
            )],
        );
        $this->assertSame(1, $calls);
    }

    /**
     * POC test (§7 Phase 0): push asserted via QueueTrait, execution spy
     * asserts `execute()` never ran, processor result is ACK.
     *
     * @return void
     */
    public function testProcessorSkipsMiddlewareJobAndAcks(): void
    {
        ExampleMiddlewareJob::$skip = true;
        ExampleMiddlewareJob::dispatch(['id' => 42]);

        $this->assertJobQueued(ExampleMiddlewareJob::class);

        $processor = new MiddlewareProcessor(new NullLogger());
        $result = $processor->process(
            new NullMessage(json_encode($this->messageBody(ExampleMiddlewareJob::class, ['id' => 42]))),
            new NullContext(),
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertSame([], ExampleMiddlewareJob::$executed);
    }

    /**
     * @return void
     */
    public function testProcessorRunsJobWithoutMiddleware(): void
    {
        ExampleMiddlewareJob::dispatch(['id' => 7]);

        $this->assertJobQueued(ExampleMiddlewareJob::class);

        $processor = new MiddlewareProcessor(new NullLogger());
        $result = $processor->process(
            new NullMessage(json_encode($this->messageBody(ExampleMiddlewareJob::class, ['id' => 7]))),
            new NullContext(),
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertCount(1, ExampleMiddlewareJob::$executed);
        $this->assertSame(7, ExampleMiddlewareJob::$executed[0]['id']);
    }

    /**
     * @return void
     */
    public function testMiddlewareJobBaseSkipsAndRuns(): void
    {
        ExampleMiddlewareBaseJob::$skip = true;
        ExampleMiddlewareBaseJob::dispatch(['id' => 1]);

        $this->assertJobQueued(ExampleMiddlewareBaseJob::class);

        $processor = new MiddlewareProcessor(new NullLogger());
        $result = $processor->process(
            new NullMessage(json_encode($this->messageBody(ExampleMiddlewareBaseJob::class, ['id' => 1]))),
            new NullContext(),
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertSame([], ExampleMiddlewareBaseJob::$executed);

        ExampleMiddlewareBaseJob::$skip = false;
        $result = $processor->process(
            new NullMessage(json_encode($this->messageBody(ExampleMiddlewareBaseJob::class, ['id' => 2]))),
            new NullContext(),
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertCount(1, ExampleMiddlewareBaseJob::$executed);
        $this->assertSame(2, ExampleMiddlewareBaseJob::$executed[0]['id']);
    }

    /**
     * Build a live `Message` for direct runner tests.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @return \Cake\Queue\Job\Message
     */
    protected function buildMessage(string $jobClass, array $data): Message
    {
        return new Message(
            new NullMessage(json_encode($this->messageBody($jobClass, $data))),
            new NullContext(),
        );
    }

    /**
     * Build a queue body in the same shape `SyncJobRunner` uses.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @return array<string, mixed>
     */
    protected function messageBody(string $jobClass, array $data): array
    {
        return [
            'class' => [$jobClass, 'execute'],
            'args' => [$data],
            'data' => $data,
        ];
    }
}
