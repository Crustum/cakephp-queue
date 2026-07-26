<?php
declare(strict_types=1);

namespace Crustum\Queue\Event;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;

/**
 * Queue Event Dispatcher
 *
 * Thin wrapper around CakePHP EventManager for job pending/pushed events.
 */
class EventDispatcher
{
    /**
     * @var \Cake\Event\EventManager
     */
    protected EventManager $eventManager;

    /**
     * @param \Cake\Event\EventManager|null $eventManager Event manager (defaults to global)
     */
    public function __construct(?EventManager $eventManager = null)
    {
        $this->eventManager = $eventManager ?? EventManager::instance();
    }

    /**
     * Register an event listener.
     *
     * @param \Cake\Event\EventListenerInterface $listener Listener
     * @return void
     */
    public function registerListener(EventListenerInterface $listener): void
    {
        $events = $listener->implementedEvents();

        foreach ($events as $eventKey => $handler) {
            if (is_string($handler)) {
                $this->eventManager->on($eventKey, [$listener, $handler]);
            } else {
                $this->eventManager->on($eventKey, $handler);
            }
        }
    }

    /**
     * Dispatch an event.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @return \Cake\Event\EventInterface
     */
    public function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventManager->dispatch($event);
    }

    /**
     * Dispatch a job pending event (before enqueue).
     *
     * @param string $connection Queue config name
     * @param string $queue Queue name
     * @param array<string, mixed> $payload Job payload
     * @param array<string, mixed> $options Options
     * @return \Cake\Event\EventInterface
     */
    public function dispatchJobPending(
        string $connection,
        string $queue,
        array $payload,
        array $options = [],
    ): EventInterface {
        return $this->dispatch(new JobPendingEvent($connection, $queue, $payload, $options));
    }

    /**
     * Dispatch a job pushed event (after enqueue).
     *
     * @param string $connection Queue config name
     * @param string $queue Queue name
     * @param array<string, mixed> $payload Job payload
     * @param array<string, mixed> $options Options
     * @return \Cake\Event\EventInterface
     */
    public function dispatchJobPushed(
        string $connection,
        string $queue,
        array $payload,
        array $options = [],
    ): EventInterface {
        return $this->dispatch(new JobPushedEvent($connection, $queue, $payload, $options));
    }

    /**
     * Shared dispatcher bound to the global EventManager.
     *
     * @return self
     */
    public static function instance(): self
    {
        return new self(EventManager::instance());
    }
}
