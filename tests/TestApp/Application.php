<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Override;

/**
 * Minimal test application for Crustum/Queue.
 */
class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function bootstrap(): void
    {
        parent::bootstrap();

        $this->addPlugin('Cake/Queue');
        $this->addPlugin('Crustum/Queue', [
            'bootstrap' => true,
            'routes' => false,
            'console' => false,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue->add(new RoutingMiddleware($this));
    }
}
