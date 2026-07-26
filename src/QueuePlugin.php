<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Override;

/**
 * Crustum Queue — Dispatchable / Configurable / Taggable job helpers.
 */
class QueuePlugin extends BasePlugin
{
    /**
     * @var string|null
     */
    protected ?string $name = 'Crustum/Queue';

    /**
     * @var bool
     */
    protected bool $bootstrapEnabled = true;

    /**
     * @var bool
     */
    protected bool $consoleEnabled = false;

    /**
     * @var bool
     */
    protected bool $middlewareEnabled = false;

    /**
     * @var bool
     */
    protected bool $routesEnabled = false;

    /**
     * @param \Cake\Core\PluginApplicationInterface $app Application
     * @return void
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);
    }
}
