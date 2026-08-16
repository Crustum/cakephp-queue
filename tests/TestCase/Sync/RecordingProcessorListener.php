<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Sync;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * Captures Processor.message.* events for sync runner tests.
 */
class RecordingProcessorListener implements EventListenerInterface
{
    /**
     * @var list<array{name: string, sync: mixed}>
     */
    public static array $events = [];

    /**
     * @return void
     */
    public static function reset(): void
    {
        self::$events = [];
    }

    /**
     * @inheritDoc
     */
    public function implementedEvents(): array
    {
        return [
            'Processor.message.seen' => 'record',
            'Processor.message.start' => 'record',
            'Processor.message.success' => 'record',
            'Processor.message.reject' => 'record',
            'Processor.message.failure' => 'record',
            'Processor.message.exception' => 'record',
            'Processor.message.invalid' => 'record',
        ];
    }

    /**
     * @param \Cake\Event\EventInterface $event Processor event
     * @return void
     */
    public function record(EventInterface $event): void
    {
        self::$events[] = [
            'name' => $event->getName(),
            'sync' => $event->getData('sync'),
        ];
    }
}
