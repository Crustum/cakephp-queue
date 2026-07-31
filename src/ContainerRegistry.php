<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\Core\ContainerInterface;

/**
 * Holds the application DI container for SyncJobRunner (same instance workers use).
 *
 * Set from QueuePlugin::bootstrap when the host implements ContainerApplicationInterface.
 */
final class ContainerRegistry
{
    /**
     * @var \Cake\Core\ContainerInterface|null
     */
    protected static ?ContainerInterface $instance = null;

    /**
     * @param \Cake\Core\ContainerInterface|null $container Application container
     * @return void
     */
    public static function setInstance(?ContainerInterface $container): void
    {
        self::$instance = $container;
    }

    /**
     * @return \Cake\Core\ContainerInterface|null
     */
    public static function getInstance(): ?ContainerInterface
    {
        return self::$instance;
    }

    /**
     * Clear the registry (tests).
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$instance = null;
    }
}
