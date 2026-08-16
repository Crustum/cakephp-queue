<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Queue;

use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\CommandBus;
use Crustum\Queue\Handles;
use ReflectionClass;
use RuntimeException;
use TestApp\Job\TestPingJob;
use TestApp\Queue\Command\TestPingCommand;

class CommandBusTest extends TestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        CommandBus::map(TestPingCommand::class, TestPingJob::class);
    }

    /**
     * Manual map registers a command→job pair.
     *
     * @return void
     */
    public function testMapRegistersHandler(): void
    {
        CommandBus::map(TestPingCommand::class, TestPingJob::class);

        $this->assertSame(TestPingJob::class, $this->invokeRegisteredHandler(TestPingCommand::class));
    }

    /**
     * registerFromAttributes discovers #[Handles] jobs from the scanned scope.
     *
     * @return void
     */
    public function testRegisterFromAttributesDiscoversHandlers(): void
    {
        CommandBus::registerFromAttributes(
            paths: ['Job/*.php'],
            basePath: ROOT . DS . 'tests' . DS . 'TestApp',
            excludePaths: [],
        );

        $this->assertSame(TestPingJob::class, $this->invokeRegisteredHandler(TestPingCommand::class));
    }

    /**
     * dispatch enqueues the handler job with the command payload.
     *
     * @return void
     */
    public function testDispatchEnqueuesHandlerJob(): void
    {
        CommandBus::map(TestPingCommand::class, TestPingJob::class);

        CommandBus::dispatch(new TestPingCommand(value: 7));

        $this->assertJobQueued(TestPingJob::class);
        $jobs = $this->getQueuedJobsByClass(TestPingJob::class);
        $this->assertSame(7, $jobs[0]['data']['value']);
    }

    /**
     * dispatchLater enqueues the handler job.
     *
     * @return void
     */
    public function testDispatchLaterEnqueuesHandlerJob(): void
    {
        CommandBus::map(TestPingCommand::class, TestPingJob::class);

        CommandBus::dispatchLater(new TestPingCommand(value: 3), 60);

        $this->assertJobQueued(TestPingJob::class);
        $jobs = $this->getQueuedJobsByClass(TestPingJob::class);
        $this->assertSame(3, $jobs[0]['data']['value']);
    }

    /**
     * dispatch without a registered handler throws a descriptive exception
     * instead of a bare undefined-key notice / TypeError.
     *
     * @return void
     */
    public function testDispatchThrowsWhenNoHandlerRegistered(): void
    {
        $this->clearHandlers();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No job handler registered for command');

        CommandBus::dispatch(new TestPingCommand(value: 1));
    }

    /**
     * dispatchLater without a registered handler throws a descriptive exception.
     *
     * @return void
     */
    public function testDispatchLaterThrowsWhenNoHandlerRegistered(): void
    {
        $this->clearHandlers();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No job handler registered for command');

        CommandBus::dispatchLater(new TestPingCommand(value: 1), 60);
    }

    /**
     * Handles attribute exposes its command class.
     *
     * @return void
     */
    public function testHandlesAttributeExposesCommandClass(): void
    {
        $attribute = new Handles(TestPingCommand::class);
        $this->assertSame(TestPingCommand::class, $attribute->commandClass);
    }

    /**
     * @param class-string $command Command class
     * @return class-string|null
     */
    private function invokeRegisteredHandler(string $command): ?string
    {
        $handlers = $this->readHandlers();

        return $handlers[$command] ?? null;
    }

    /**
     * Empty the handler map so a command has no registered handler.
     *
     * @return void
     */
    private function clearHandlers(): void
    {
        $reflection = new ReflectionClass(CommandBus::class);
        $property = $reflection->getProperty('handlers');
        $property->setValue(null, []);
    }

    /**
     * @return array<class-string, class-string>
     */
    private function readHandlers(): array
    {
        $reflection = new ReflectionClass(CommandBus::class);
        $property = $reflection->getProperty('handlers');

        /** @var array<class-string, class-string> $handlers */
        $handlers = $property->getValue();

        return $handlers;
    }
}
