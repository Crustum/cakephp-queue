<?php
declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/**
 * Crustum Queue plugin routes.
 *
 * @var \Cake\Routing\RouteBuilder $routes
 */
$routes->plugin(
    'Crustum/Queue',
    function (RouteBuilder $routes): void {
        $routes->setRouteClass(DashedRoute::class);
        $routes->get(
            '/debug/related',
            ['controller' => 'QueueDebug', 'action' => 'related'],
        );
        $routes->fallbacks(DashedRoute::class);
    },
);
