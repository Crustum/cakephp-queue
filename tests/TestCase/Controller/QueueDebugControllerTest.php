<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Controller;

use Cake\Core\Plugin;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Controller\QueueDebugController;

/**
 * QueueDebugController Test Case
 *
 * The toolbar store is absent in isolation, so only the guarded
 * paths run here; the lookup itself is covered by RelatedLookupTest.
 */
class QueueDebugControllerTest extends TestCase
{
    /**
     * Missing toolbar answers 404 without touching the store.
     *
     * @return void
     */
    public function testRelatedWithoutToolbar(): void
    {
        $this->assertFalse(Plugin::isLoaded('Crustum/DebugBar'));

        $controller = new QueueDebugController(new ServerRequest([
            'url' => '/debug/related',
            'query' => ['key' => 'abc-123', 'kind' => 'push'],
        ]));

        $this->expectException(NotFoundException::class);
        $controller->related();
    }
}
