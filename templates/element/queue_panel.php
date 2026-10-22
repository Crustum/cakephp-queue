<?php
/**
 * Queue panel element.
 *
 * Server-rendered port of the upstream queue inspector: pushed jobs and
 * worker executions grouped by job class plus correlation key, with a
 * status filter, facet filters by connection and job class, search, and
 * Overview (facts), Payload (arguments) and Source (push callsite plus
 * frames) tabs per group. Interactive states are server-rendered
 * (`aria-pressed`, `hidden`); the boot script owns search/filter/
 * selection over the `data-dk-*` hooks. No JavaScript ships with the
 * panel: list+detail selection self-registers through the embedded
 * `data-dk-selection-config` tag.
 *
 * CakePHP notes (no fake data): only observed statuses render
 * (`pushed`, `sync`, `running`, `completed`, `failed`, `rejected`,
 * `waiting`, `invalid`). Attempt counters are omitted — processor events
 * carry no attempt number. Worker executions arrive as their own request
 * rows; pushed jobs link to them through the correlation key text.
 *
 * @var \Crustum\DebugBar\View\AjaxView $this
 * @var array $groups Grouped records from `QueuePanel::data()`
 * @var array $summary Counters with `count` and `attention`
 * @var array $facets Filter facets with `to` (connections) and `class` (jobs)
 */
use function Cake\Core\h;

$groups = array_values((array)($groups ?? []));
$summary = (array)($summary ?? []);
$facets = (array)($facets ?? []);
$toFacets = (array)($facets['to'] ?? []);
$classFacets = (array)($facets['class'] ?? []);
$total = (int)($summary['count'] ?? count($groups));
$attentionCount = (int)($summary['attention'] ?? 0);
$facetCount = static function (string $token) use ($groups): int {
    $count = 0;
    foreach ($groups as $group) {
        $tokens = explode(' ', (string)((array)$group)['filter'] ?? '');
        if (in_array($token, $tokens, true)) {
            $count++;
        }
    }

    return $count;
};

$segmentedTabClass = 'dk:relative dk:flex dk:min-w-0 dk:items-baseline dk:justify-center dk:gap-1.5 dk:whitespace-nowrap dk:rounded-md dk:border dk:border-transparent dk:bg-transparent dk:px-3 dk:py-1.5 dk:text-xs dk:font-semibold dk:text-zinc-500 dk:transition-[background-color,color,box-shadow] dk:hover:bg-white/60 dk:hover:text-zinc-950 dk:focus-visible:z-10 dk:focus-visible:outline-2 dk:focus-visible:outline-indigo-500 dk:aria-pressed:bg-white dk:aria-pressed:text-zinc-950 dk:aria-pressed:shadow-sm dk:aria-pressed:hover:bg-white dk:aria-pressed:hover:text-zinc-950 dk:aria-selected:bg-white dk:aria-selected:text-zinc-950 dk:aria-selected:shadow-sm dk:aria-selected:hover:bg-white dk:aria-selected:hover:text-zinc-950 dk:dark:text-zinc-400 dk:dark:hover:bg-zinc-800/70 dk:dark:hover:text-white dk:dark:aria-pressed:bg-zinc-800 dk:dark:aria-pressed:text-white dk:dark:aria-selected:bg-zinc-800 dk:dark:aria-selected:text-white';
$statusClass = static function (string $status): string {
    return match ($status) {
        'completed' => 'dk:text-emerald-600 dk:dark:text-emerald-300',
        'failed', 'invalid' => 'dk:text-red-600 dk:dark:text-red-300',
        'rejected', 'waiting' => 'dk:text-amber-600 dk:dark:text-amber-300',
        default => 'dk:text-sky-600 dk:dark:text-sky-300',
    };
};
$firstKey = '';
foreach ($groups as $group) {
    $group = (array)$group;
    if ($firstKey === '') {
        $firstKey = (string)($group['group_id'] ?? '');
    }
}
?>
<div data-dk-queue-panel data-dk-active-filter="all" class="dk:space-y-4 dk:lg:flex dk:lg:min-h-0 dk:lg:flex-1 dk:lg:flex-col dk:lg:space-y-0">
    <script type="application/json" data-dk-selection-config><?= (string)json_encode([
        'entry' => 'data-dk-queue-entry',
        'key' => 'data-dk-queue-key',
        'detail' => 'data-dk-queue-detail',
        'panel' => '[data-dk-queue-panel]',
        'paint' => true,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
    <?php if ($groups === []) : ?>
    <?= $this->element('Crustum/DebugBar.ui/empty_state', ['message' => 'No jobs were pushed or executed.']) ?>
    <?php else : ?>
    <?php
    ob_start();
        ?>
    <p
        data-dk-queue-summary
        aria-live="polite"
        class="dk:min-w-0 dk:text-xs dk:font-semibold dk:text-zinc-600 dk:dark:text-zinc-300"
    >
        <?= number_format($total) ?> <?= $total === 1 ? 'job' : 'jobs' ?>
        <span
            data-dk-visible-count
            hidden
            class="dk:ml-1 dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400"
        ></span>
    </p>
    <div
        data-dk-inspector-list-controls
        class="dk:grid dk:items-start dk:gap-x-2 dk:gap-y-3 dk:grid-cols-[minmax(0,1fr)_8.75rem]"
    >
        <div class="dk:min-w-0">
            <label class="dk:relative dk:block dk:min-w-0">
                <span class="dk:sr-only">Search jobs</span>
                <input
                    data-dk-search="queue"
                    type="search"
                    placeholder="Search job, connection, or queue"
                    aria-label="Search jobs"
                    autocomplete="off"
                    class="dk:h-9 dk:w-full dk:rounded-lg dk:border dk:border-zinc-200 dk:bg-white/70 dk:pr-3 dk:pl-8 dk:text-xs dk:outline-none dk:transition dk:placeholder:text-zinc-400 dk:focus:border-indigo-400 dk:focus:ring-2 dk:focus:ring-indigo-500/15 dk:dark:border-zinc-700 dk:dark:bg-zinc-900/70"
                />
                <?= $this->element('Crustum/DebugBar.ui/icon', ['name' => 'search', 'size' => '4', 'class' => 'dk:pointer-events-none dk:absolute dk:top-1/2 dk:left-2.5 dk:-translate-y-1/2 dk:text-zinc-400']) ?>
            </label>
        </div>
        <div class="dk:min-w-0">
            <label class="dk:relative dk:block">
                <span class="dk:sr-only">Filter jobs by status</span>
                <select
                    data-dk-select="queue-filter"
                    class="dk:h-9 dk:w-full dk:appearance-none dk:rounded-lg dk:border dk:border-zinc-200 dk:bg-white/70 dk:pr-8 dk:pl-3 dk:text-xs dk:font-semibold dk:outline-none dk:transition dk:focus:border-indigo-400 dk:focus:ring-2 dk:focus:ring-indigo-500/15 dk:dark:border-zinc-700 dk:dark:bg-zinc-900/70"
                >
                    <option value="all" selected>All (<?= number_format($total) ?>)</option>
                    <option value="attention">Needs attention (<?= number_format($attentionCount) ?>)</option>
                    <?php if ($toFacets !== []) : ?>
                    <optgroup label="Connection">
                        <?php foreach ($toFacets as $token => $label) : ?>
                        <option value="<?= h((string)$token) ?>"><?= h((string)$label) ?> (<?= number_format($facetCount((string)$token)) ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if ($classFacets !== []) : ?>
                    <optgroup label="Job">
                        <?php foreach ($classFacets as $token => $label) : ?>
                        <option value="<?= h((string)$token) ?>"><?= h((string)$label) ?> (<?= number_format($facetCount((string)$token)) ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
                <?= $this->element('Crustum/DebugBar.ui/icon', ['name' => 'chevron-down', 'size' => '3.5', 'class' => 'dk:pointer-events-none dk:absolute dk:top-1/2 dk:right-2.5 dk:-translate-y-1/2 dk:text-zinc-400']) ?>
            </label>
        </div>
    </div>
    <?php
    $controls = (string)ob_get_clean();
    ob_start();
        ?>
    <div data-dk-queue-list aria-label="Queue jobs">
        <?php foreach ($groups as $group) :
            $group = (array)$group;
            $key = (string)($group['group_id'] ?? '');
            $status = (string)($group['status'] ?? 'pushed');
            $connection = (string)($group['connection'] ?? '');
            $queue = (string)($group['queue'] ?? '');
            $context = trim($connection . ($queue !== '' ? ' / ' . $queue : ''));
            $pressed = $key !== '' && $key === $firstKey;
            ?>
        <button
            type="button"
            data-dk-queue-entry
            data-dk-queue-key="<?= h($key) ?>"
            data-dk-filter-group="<?= h((string)($group['filter'] ?? 'attention')) ?>"
            data-dk-filter-attention="<?= in_array($status, ['completed', 'pushed', 'sync'], true) ? 'false' : 'true' ?>"
            data-dk-search-text="<?= h((string)($group['search'] ?? '')) ?>"
            aria-pressed="<?= $pressed ? 'true' : 'false' ?>"
            class="dk:grid dk:h-auto dk:w-full dk:grid-cols-[minmax(0,1fr)_auto] dk:items-baseline dk:gap-x-3 dk:gap-y-1 dk:px-3 dk:py-2.5 dk:text-left dk:transition-colors dk:focus-visible:relative dk:focus-visible:z-10 dk:focus-visible:outline-2 dk:focus-visible:outline-indigo-500 dk:sm:py-3<?= $pressed ? ' dk:bg-indigo-50/65 dk:dark:bg-indigo-950/20' : ' dk:hover:bg-zinc-50/80 dk:dark:hover:bg-zinc-900/60' ?>"
        >
            <span class="dk:min-w-0 dk:truncate dk:text-xs dk:font-bold dk:text-zinc-900 dk:dark:text-zinc-100"><?= h((string)($group['label'] ?? '')) ?></span>
            <span class="dk:justify-self-end dk:text-xs dk:font-bold <?= h($statusClass($status)) ?>">
                <?= h((string)($group['status_label'] ?? '')) ?>
            </span>
            <span class="dk:col-start-1 dk:min-w-0 dk:truncate dk:text-xs dk:text-zinc-500 dk:dark:text-zinc-400"><?= h($context !== '' ? $context : 'default') ?></span>
            <span class="dk:col-start-2 dk:justify-self-end dk:text-right dk:text-xs dk:font-semibold dk:tabular-nums dk:text-zinc-400">
                <?= h((string)($group['duration_label'] ?? '')) ?>
            </span>
        </button>
        <?php endforeach; ?>
    </div>
    <div data-dk-list-empty hidden>
        <?= $this->element('Crustum/DebugBar.ui/empty_state', ['message' => 'No jobs match these filters.']) ?>
    </div>
    <?php
    $listBody = (string)ob_get_clean();
    ob_start();
        ?>
    <div data-dk-detail-pane class="dk:min-w-0">
        <div data-dk-detail-empty hidden>No job is selected. Adjust the filter or search.</div>
        <?php foreach ($groups as $group) :
            $group = (array)$group;
            $key = (string)($group['group_id'] ?? '');
            $status = (string)($group['status'] ?? 'pushed');
            $exception = is_array($group['exception'] ?? null) ? $group['exception'] : null;
            $callsite = is_array($group['callsite'] ?? null) ? $group['callsite'] : null;
            $frames = array_values((array)($callsite['frames'] ?? []));
            $pressed = $key !== '' && $key === $firstKey;
            $tabScope = $key;
            ?>
        <section
            data-dk-queue-detail="<?= h($key) ?>"
            aria-label="Selected job details"
            <?= $pressed ? '' : 'hidden' ?>
        >
            <div class="dk:flex dk:flex-col">
                <header class="dk:border-b dk:border-zinc-200/90 dk:p-3 dk:sm:p-4 dk:dark:border-zinc-800">
                    <div class="dk:grid dk:min-w-0 dk:grid-cols-[minmax(0,1fr)_auto] dk:items-start dk:gap-x-3 dk:gap-y-2">
                        <div class="dk:min-w-0">
                            <h3 class="dk:break-words dk:text-base dk:font-bold dk:leading-6"><?= h((string)($group['label'] ?? '')) ?></h3>
                            <code class="dk:mt-1 dk:block dk:break-all dk:bg-transparent dk:font-mono dk:text-xs dk:font-medium dk:leading-4 dk:text-zinc-400"><?= h((string)($group['job'] ?? '')) ?></code>
                        </div>
                        <div class="dk:flex dk:shrink-0 dk:flex-col dk:items-end dk:gap-2">
                            <span class="dk:text-xs dk:font-bold <?= h($statusClass($status)) ?>">
                                <?= h((string)($group['status_label'] ?? '')) ?>
                            </span>
                            <?php
                            $relatedKind = (string)($group['kind'] ?? '');
                            $relatedStatus = (string)($group['status'] ?? '');
                            $relatedLabel = $relatedKind === 'push' && $relatedStatus === 'pushed'
                                ? 'Open worker run'
                                : ($relatedKind === 'execution' ? 'Open push' : '');
                            $relatedKey = (string)($group['key'] ?? '');
                            if ($relatedLabel !== '' && $relatedKey !== '' && !str_starts_with($relatedKey, 'pushed-') && !str_starts_with($relatedKey, 'message-')) :
                                $relatedUrl = $this->Url->build([
                                    'plugin' => 'Crustum/Queue',
                                    'controller' => 'QueueDebug',
                                    'action' => 'related',
                                    '?' => ['key' => $relatedKey, 'kind' => $relatedKind],
                                ]);
                                ?>
                            <button
                                type="button"
                                data-dk-request-link="<?= h($relatedUrl) ?>"
                                class="dk:inline-flex dk:h-8 dk:shrink-0 dk:items-center dk:gap-1.5 dk:rounded-lg dk:bg-indigo-50 dk:px-2.5 dk:text-xs dk:font-bold dk:text-indigo-700 dk:transition dk:hover:bg-indigo-100 dk:focus-visible:outline-2 dk:focus-visible:outline-indigo-500 dk:dark:bg-indigo-950/55 dk:dark:text-indigo-300 dk:dark:hover:bg-indigo-950"
                            ><?= h($relatedLabel) ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($exception !== null) : ?>
                    <div class="dk:mt-3 dk:rounded-lg dk:border dk:border-red-200/80 dk:bg-red-50/60 dk:px-3 dk:py-2.5 dk:dark:border-red-950 dk:dark:bg-red-950/30">
                        <p class="dk:text-xs dk:font-semibold dk:leading-5 dk:text-red-700 dk:dark:text-red-300"><?= h((string)($exception['message'] ?? '')) ?></p>
                        <?php if ((string)($exception['class'] ?? '') !== '') : ?>
                        <code class="dk:mt-1 dk:block dk:break-all dk:text-xs dk:text-red-500 dk:dark:text-red-400"><?= h((string)$exception['class']) ?></code>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <dl class="dk:mt-3 dk:grid dk:min-w-0 dk:gap-x-6 dk:gap-y-3 dk:grid-cols-1 dk:sm:grid-cols-2">
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Connection</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:text-sm dk:font-semibold dk:text-zinc-800 dk:dark:text-zinc-200"><?= h((string)($group['connection'] ?? '') !== '' ? (string)$group['connection'] : 'default') ?></dd>
                        </div>
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Queue</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:text-sm dk:font-semibold dk:text-zinc-800 dk:dark:text-zinc-200"><?= h((string)($group['queue'] ?? '') !== '' ? (string)$group['queue'] : '—') ?></dd>
                        </div>
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Duration</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:text-sm dk:font-semibold dk:tabular-nums dk:text-zinc-800 dk:dark:text-zinc-200"><?= h((string)($group['duration_label'] ?? '')) ?></dd>
                        </div>
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Correlation key</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:font-mono dk:text-xs dk:text-zinc-500 dk:dark:text-zinc-400"><?= h((string)($group['key'] ?? '')) ?></dd>
                        </div>
                    </dl>
                </header>
                <div role="tablist" aria-label="Job detail" data-dk-detail-tabs class="dk:border-b dk:border-zinc-200/90 dk:px-3 dk:py-2.5 dk:sm:px-4 dk:dark:border-zinc-800 dk:grid dk:grid-cols-1 dk:justify-items-center dk:gap-2">
                    <div role="presentation" class="dk:flex dk:min-w-0 dk:rounded-lg dk:bg-zinc-100/70 dk:p-1 dk:dark:bg-zinc-900/70">
                        <?php foreach (['overview' => ['Overview', 'eye'], 'payload' => ['Payload', 'database'], 'source' => ['Source', 'code']] as $tabKey => [$tabLabel, $tabIcon]) : ?>
                        <button
                            type="button"
                            role="tab"
                            aria-selected="<?= $tabKey === 'overview' ? 'true' : 'false' ?>"
                            aria-label="<?= h($tabLabel) ?>"
                            data-dk-tab="<?= h($tabScope . ':' . $tabKey) ?>"
                            class="<?= h($segmentedTabClass) ?> dk:h-auto"
                        >
                            <?= $this->element('Crustum/DebugBar.ui/icon', ['name' => $tabIcon, 'size' => '3.5', 'class' => 'dk:sm:hidden']) ?>
                            <span class="dk:hidden dk:sm:inline"><?= h($tabLabel) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div data-dk-tab-panel="<?= h($tabScope) ?>:overview" class="dk:p-3 dk:sm:p-4">
                    <dl class="dk:grid dk:min-w-0 dk:gap-x-6 dk:gap-y-3 dk:grid-cols-1 dk:sm:grid-cols-2">
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Kind</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:text-sm dk:font-semibold dk:text-zinc-800 dk:dark:text-zinc-200"><?= h((string)($group['kind'] ?? 'push')) ?></dd>
                        </div>
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Job ID</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:font-mono dk:text-xs dk:text-zinc-500 dk:dark:text-zinc-400"><?= h((string)($group['job_id'] ?? '') !== '' ? (string)$group['job_id'] : '—') ?></dd>
                        </div>
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Delay</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:text-sm dk:font-semibold dk:tabular-nums dk:text-zinc-800 dk:dark:text-zinc-200"><?= h($group['delay'] !== null ? (string)$group['delay'] . ' s' : '—') ?></dd>
                        </div>
                        <div class="dk:min-w-0">
                            <dt class="dk:text-xs dk:font-medium dk:text-zinc-500 dk:dark:text-zinc-400">Executions</dt>
                            <dd class="dk:min-w-0 dk:truncate dk:text-sm dk:font-semibold dk:tabular-nums dk:text-zinc-800 dk:dark:text-zinc-200"><?= h((string)($group['kind'] ?? '') === 'execution' ? '1' : 'queued, awaiting worker') ?></dd>
                        </div>
                    </dl>
                </div>
                <div data-dk-tab-panel="<?= h($tabScope) ?>:payload" hidden class="dk:p-3 dk:sm:p-4">
                    <h4 class="dk:text-xs dk:font-bold">Job arguments</h4>
                    <?= $this->element('Crustum/DebugBar.ui/inspector_evidence', [
                        'language' => 'json',
                        'content' => (string)json_encode(
                            $group['args'] ?? [],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ),
                    ]) ?>
                </div>
                <div data-dk-tab-panel="<?= h($tabScope) ?>:source" hidden class="dk:p-3 dk:sm:p-4">
                    <?php if ($callsite === null) : ?>
                    <p class="dk:text-xs dk:text-zinc-400">Source unavailable</p>
                    <?php else : ?>
                    <?= $this->element('Crustum/DebugBar.ui/source_ref', [
                        'file' => (string)($callsite['file'] ?? ''),
                        'line' => (int)($callsite['line'] ?? 0),
                    ]) ?>
                    <?php if ($frames !== []) : ?>
                    <dl class="dk:mt-3 dk:divide-y dk:divide-zinc-200/90 dk:dark:divide-zinc-800">
                        <?php foreach ($frames as $frame) :
                            $frame = (array)$frame;
                                ?>
                        <div class="dk:grid dk:gap-1 dk:py-2 dk:sm:grid-cols-[8rem_minmax(0,1fr)]">
                            <dt class="dk:min-w-0 dk:break-all dk:text-xs dk:font-semibold dk:text-zinc-500 dk:dark:text-zinc-400"><?= h((string)($frame['function'] ?? '')) ?></dt>
                            <dd class="dk:min-w-0">
                                <?= $this->element('Crustum/DebugBar.ui/source_ref', [
                                    'file' => (string)($frame['file'] ?? ''),
                                    'line' => (int)($frame['line'] ?? 0),
                                ]) ?>
                            </dd>
                        </div>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <?php
    $detailBody = (string)ob_get_clean();
        ?>
    <?= $this->element('Crustum/DebugBar.ui/inspector_workspace', [
        'mode' => 'split',
        'frame' => 'top',
        'list' => $this->element('Crustum/DebugBar.ui/inspector_list_panel', [
            'controls' => $controls,
            'body' => $listBody,
        ]),
        'detail' => $detailBody,
    ]) ?>
    <?php endif; ?>
</div>
