<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Panel;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Crustum\Queue\Debug\WorkerRecorder;
use Crustum\Queue\Event\JobDataMutators;
use Crustum\Queue\Event\JobPushedEvent;
use Crustum\Queue\Panel\QueuePanel;
use RuntimeException;
use TestApp\Queue\PanelProbeMessage;

/**
 * QueuePanel Test Case
 */
class QueuePanelTest extends TestCase
{
    /**
     * @var \Crustum\Queue\Panel\QueuePanel
     */
    protected QueuePanel $panel;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        QueuePanel::clear();
        $this->panel = new QueuePanel();
        EventManager::instance()->on($this->panel);
        Router::reload();
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $routes = Router::createRouteBuilder('/');
        require dirname(__DIR__, 3) . '/config/routes.php';
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        EventManager::instance()->off($this->panel);
        QueuePanel::clear();
        JobDataMutators::clear();
        Router::reload();
        parent::tearDown();
    }

    /**
     * Toolbar adapter contract stays intact.
     *
     * @return void
     */
    public function testPanelContract(): void
    {
        $this->assertSame('Queue', $this->panel->title());
        $this->assertSame('Crustum/Queue.queue_panel', $this->panel->elementName());
        $this->assertSame('', $this->panel->summary());
        $this->assertSame(
            ['groups' => [], 'summary' => ['count' => 0, 'attention' => 0]],
            $this->panel->data(),
        );
    }

    /**
     * Pushed jobs group with sync flags and correlation keys.
     *
     * @return void
     */
    public function testPushedJobsGroup(): void
    {
        EventManager::instance()->dispatch(new JobPushedEvent('default', 'mail', [
            'job' => 'App\\Job\\SendMail',
            'body' => ['args' => [['to' => 'ann@example.com']]],
            'debugbar_key' => 'key-1',
        ]));
        EventManager::instance()->dispatch(new JobPushedEvent('default', 'mail', [
            'job' => 'App\\Job\\SendMail',
            'sync' => true,
            'body' => ['args' => [[]]],
            'debugbar_key' => 'key-2',
        ]));

        $data = $this->panel->data();

        $this->assertCount(2, $data['groups']);
        $this->assertSame('pushed', $data['groups'][0]['status']);
        $this->assertSame('key-1', $data['groups'][0]['key']);
        $this->assertSame(['to' => 'ann@example.com'], $data['groups'][0]['args']);
        $this->assertSame('sync', $data['groups'][1]['status']);
        $this->assertSame(['count' => 2, 'attention' => 0], $data['summary']);
    }

    /**
     * Correlation keys read from nested job arguments.
     *
     * The emitter nests mutated data under `body.args[0]`, not top level.
     *
     * @return void
     */
    public function testNestedCorrelationKey(): void
    {
        EventManager::instance()->dispatch(new JobPushedEvent('default', 'mail', [
            'job' => 'App\\Job\\SendMail',
            'body' => ['args' => [['to' => 'ann@example.com', 'debugbar_key' => 'nested-1']]],
        ]));

        $data = $this->panel->data();

        $this->assertSame('nested-1', $data['groups'][0]['key']);
    }

    /**
     * Toolbar badge counts the inbox before draining.
     *
     * @return void
     */
    public function testSummaryCountsInbox(): void
    {
        EventManager::instance()->dispatch(new JobPushedEvent('default', 'mail', [
            'job' => 'App\\Job\\SendMail',
            'body' => ['args' => [[]]],
            'debugbar_key' => 'key-1',
        ]));

        $this->assertSame('1 jobs', $this->panel->summary());
    }

    /**
     * Processor executions correlate through the injected key.
     *
     * @return void
     */
    public function testExecutionsCorrelateByKey(): void
    {
        $message = new PanelProbeMessage(['job' => 'App\\Job\\SendMail', 'debugbar_key' => 'key-9']);
        EventManager::instance()->dispatch(new Event(
            'Processor.message.start',
            null,
            ['message' => $message],
        ));
        EventManager::instance()->dispatch(new Event(
            'Processor.message.success',
            null,
            ['message' => $message, 'duration' => 12],
        ));

        $data = $this->panel->data();

        $this->assertCount(1, $data['groups']);
        $group = $data['groups'][0];
        $this->assertSame('completed', $group['status']);
        $this->assertSame('key-9', $group['key']);
        $this->assertSame(12.0, $group['duration_ms']);
    }

    /**
     * Failed executions keep exception shapes.
     *
     * @return void
     */
    public function testFailedExecution(): void
    {
        $message = new PanelProbeMessage(['job' => 'App\\Job\\SendMail', 'debugbar_key' => 'key-7']);
        EventManager::instance()->dispatch(new Event(
            'Processor.message.start',
            null,
            ['message' => $message],
        ));
        EventManager::instance()->dispatch(new Event(
            'Processor.message.exception',
            null,
            ['message' => $message, 'duration' => 3, 'exception' => new RuntimeException('boom')],
        ));

        $data = $this->panel->data();
        $group = $data['groups'][0];

        $this->assertSame('failed', $group['status']);
        $this->assertSame('boom', $group['exception']['message']);
        $this->assertSame(RuntimeException::class, $group['exception']['class']);
        $this->assertSame(1, $data['summary']['attention']);
    }

    /**
     * The mutator injects correlation keys into pushed payloads.
     *
     * @return void
     */
    public function testMutatorInjectsKey(): void
    {
        $this->panel->initialize();

        $prepared = JobDataMutators::prepare('App\\Job\\SendMail', ['a' => 1], []);

        $this->assertArrayHasKey(QueuePanel::CORRELATION_FIELD, $prepared);
        $this->assertNotEmpty($prepared[QueuePanel::CORRELATION_FIELD]);
        $kept = JobDataMutators::prepare('App\\Job\\SendMail', ['debugbar_key' => 'fixed'], []);

        $this->assertSame('fixed', $kept['debugbar_key']);
    }

    /**
     * The worker recorder stays quiet without the toolbar.
     *
     * @return void
     */
    public function testRecorderNoopsWithoutToolbar(): void
    {
        $this->assertFalse(class_exists('Crustum\\DebugBar\\ToolbarService'));

        $recorder = new WorkerRecorder();
        $message = new PanelProbeMessage(['job' => 'App\\Job\\SendMail']);
        $recorder->captureStart(new Event('Processor.message.start', null, ['message' => $message]));
        $recorder->captureSuccess(new Event(
            'Processor.message.success',
            null,
            ['message' => $message, 'duration' => 1],
        ));

        $this->assertSame([], QueuePanel::recorded());
    }

    /**
     * Element renders groups with list hooks and detail panes.
     *
     * Toolbar kit elements resolve only inside a host app with both
     * plugins loaded, so the render test stubs those calls and asserts
     * our markup plus the integration points.
     *
     * @return void
     */
    public function testElementRendersGroups(): void
    {
        EventManager::instance()->dispatch(new JobPushedEvent('default', 'mail', [
            'job' => 'App\\Job\\SendMail',
            'body' => ['args' => [[]]],
            'debugbar_key' => 'key-1',
        ]));
        EventManager::instance()->dispatch(new JobPushedEvent('default', 'mail', [
            'job' => 'App\\Job\\SendMail',
            'sync' => true,
            'body' => ['args' => [[]]],
            'debugbar_key' => 'key-2',
        ]));
        $data = $this->panel->data();

        $view = new class (new ServerRequest()) extends View {
            /**
             * Stubs toolbar kit elements unavailable in isolation.
             *
             * @param string $name Element name.
             * @param array $data Element variables.
             * @param array $options Element options.
             * @return string
             */
            public function element(string $name, array $data = [], array $options = []): string
            {
                if ($name === 'Crustum/DebugBar.ui/inspector_workspace') {
                    return (string)($data['list'] ?? '') . (string)($data['detail'] ?? '');
                }

                if ($name === 'Crustum/DebugBar.ui/inspector_list_panel') {
                    return (string)($data['controls'] ?? '') . (string)($data['body'] ?? '');
                }

                if (str_starts_with($name, 'Crustum/DebugBar.')) {
                    return '<!--kit:' . $name . '-->';
                }

                return parent::element($name, $data, $options);
            }
        };
        $html = (string)$view->element('Crustum/Queue.queue_panel', $data);

        $this->assertStringContainsString('data-dk-queue-panel', $html);
        $this->assertStringContainsString('data-dk-queue-entry', $html);
        $this->assertStringContainsString('data-dk-queue-detail="queue-1"', $html);
        $this->assertStringContainsString('SendMail', $html);
        $this->assertStringContainsString('data-dk-tab="queue-1:payload"', $html);
        $this->assertStringContainsString('data-dk-selection-config', $html);
        $this->assertStringContainsString('Open worker run', $html);
        $this->assertSame(1, substr_count($html, 'data-dk-request-link'));
        $this->assertStringContainsString('<!--kit:Crustum/DebugBar.ui/icon-->', $html);
    }
}
