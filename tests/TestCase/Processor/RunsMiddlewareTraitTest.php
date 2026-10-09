<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Processor;

use Cake\Core\Container;
use Cake\Queue\Job\Message;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Test\TestCase\Job\ExampleMiddlewareJob;
use Enqueue\Null\NullContext;
use Enqueue\Null\NullMessage;
use Interop\Queue\Processor;

/**
 * RunsMiddlewareTrait Test Case
 */
class RunsMiddlewareTraitTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        ExampleMiddlewareJob::$executed = [];
        ExampleMiddlewareJob::$skip = false;
    }

    /**
     * @return void
     */
    public function testResolveUsesContainerInstance(): void
    {
        $premade = new ExampleMiddlewareJob();
        $container = new Container();
        $container->add(
            ExampleMiddlewareJob::class,
            static fn(): ExampleMiddlewareJob => $premade,
        );

        $processor = new ExampleTraitProcessor();
        $message = $this->buildMessage(['id' => 1]);

        $this->assertSame($premade, $processor->resolveJob($message, $container));
    }

    /**
     * @return void
     */
    public function testResolveFallsBackToNew(): void
    {
        $processor = new ExampleTraitProcessor();
        $message = $this->buildMessage(['id' => 1]);

        $resolved = $processor->resolveJob($message, null);

        $this->assertInstanceOf(ExampleMiddlewareJob::class, $resolved);
    }

    /**
     * @return void
     */
    public function testRunAppliesMiddlewareStack(): void
    {
        ExampleMiddlewareJob::$skip = true;
        $processor = new ExampleTraitProcessor();
        $message = $this->buildMessage(['id' => 2]);
        $job = new ExampleMiddlewareJob();
        $coreCalled = false;

        $result = $processor->runJob(
            $job,
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);
    }

    /**
     * Build a live `Message` for trait tests.
     *
     * @param array<string, mixed> $data Job data
     * @return \Cake\Queue\Job\Message
     */
    protected function buildMessage(array $data): Message
    {
        return new Message(
            new NullMessage((string)json_encode([
                'class' => [ExampleMiddlewareJob::class, 'execute'],
                'args' => [$data],
                'data' => $data,
            ])),
            new NullContext(),
        );
    }
}
