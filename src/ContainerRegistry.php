<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\Container\ContainerInterface;

/**
 * Holds the application DI container for SyncJobRunner (same instance workers use).
 *
 * Set from QueuePlugin::bootstrap when the host implements ContainerApplicationInterface.
 */
class ContainerRegistry
{
    /**
     * @var \Cake\Container\ContainerInterface|null
     */
    protected static ?ContainerInterface $instance = null;

    /**
     * @param \Cake\Container\ContainerInterface|null $container Application container
     * @return void
     */
    public static function setInstance(?ContainerInterface $container): void
    {
        self::$instance = $container;
    }

    /**
     * @return \Cake\Container\ContainerInterface|null
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
