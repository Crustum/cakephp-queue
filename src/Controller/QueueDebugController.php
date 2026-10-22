<?php
declare(strict_types=1);

namespace Crustum\Queue\Controller;

use Cake\Controller\Controller;
use Cake\Core\Plugin;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Crustum\Queue\Debug\RelatedLookup;

/**
 * Toolbar support endpoints for the queue panel.
 *
 * Resolves correlation keys to toolbar rows as JSON for the shell
 * `data-dk-request-link` hook (`{request_id, inspector}`). Reads the
 * toolbar store directly and stays inert without it — no dependency on
 * toolbar classes.
 */
class QueueDebugController extends Controller
{
    /**
     * Components and helpers for JSON answers.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Before render handler.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @return \Cake\Http\Response|null
     */
    public function beforeRender(EventInterface $event): ?Response
    {
        $this->viewBuilder()->setOption('serialize', ['request_id', 'inspector']);

        return null;
    }

    /**
     * Finds the toolbar row related to a correlation key.
     *
     * Prefers the opposite kind (push rows jump to worker runs and back),
     * newest first. Answers 404 when the toolbar is absent, the key is
     * malformed, or no related row exists yet. Key matching stays in PHP:
     * panel content is deflated in storage, so SQL LIKE cannot see it —
     * the query only bounds the candidate set, RelatedLookup matches.
     *
     * @return void
     */
    public function related(): void
    {
        if (!Plugin::isLoaded('Crustum/DebugBar')) {
            throw new NotFoundException('Toolbar is not available.');
        }

        $key = (string)$this->request->getQuery('key', '');
        $kind = (string)$this->request->getQuery('kind', '');
        if ($key === '' || preg_match('/[^a-zA-Z0-9-]/', $key) === 1) {
            throw new BadRequestException('Invalid correlation key.');
        }

        $panels = $this->fetchTable('Crustum/DebugBar.Panels')
            ->find()
            ->select(['request_id', 'content'])
            ->where(['panel' => 'Queue'])
            ->contain(['Requests'])
            ->orderBy(['Requests.requested_at' => 'DESC'])
            ->limit(100)
            ->all();

        $requestId = RelatedLookup::find($panels, $key, $kind);

        if ($requestId === null) {
            throw new NotFoundException('Related run is not recorded yet.');
        }

        $this->set('request_id', $requestId);
        $this->set('inspector', 'queue');
    }
}
