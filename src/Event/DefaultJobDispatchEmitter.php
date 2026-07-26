<?php
declare(strict_types=1);

namespace Crustum\Queue\Event;

/**
 * Default emitter: Crustum/Queue.Job.pending|pushed via EventDispatcher.
 */
class DefaultJobDispatchEmitter implements JobDispatchEmitterInterface
{
    /**
     * @param \Crustum\Queue\Event\EventDispatcher|null $dispatcher Dispatcher
     */
    public function __construct(
        protected ?EventDispatcher $dispatcher = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function emitPending(string $jobClass, array $data, array $config): void
    {
        $payload = $this->buildPayload($jobClass, $data);
        $this->dispatcher()->dispatchJobPending(
            $config['config'] ?? 'default',
            $config['queue'] ?? $config['config'] ?? 'default',
            $payload,
            $config,
        );
    }

    /**
     * @inheritDoc
     */
    public function emitPushed(string $jobClass, array $data, array $config): void
    {
        $payload = $this->buildPayload($jobClass, $data);
        $this->dispatcher()->dispatchJobPushed(
            $config['config'] ?? 'default',
            $config['queue'] ?? $config['config'] ?? 'default',
            $payload,
            $config,
        );
    }

    /**
     * @inheritDoc
     */
    public function buildPayload(string $jobClass, array $data): array
    {
        $id = is_string($data['_uniqueId'] ?? null)
            ? $data['_uniqueId']
            : hash('sha256', $jobClass . '|' . json_encode($data));

        return [
            'id' => $id,
            'job' => $jobClass,
            'body' => [
                'class' => [$jobClass, 'execute'],
                'args' => [$data],
            ],
            'tags' => $data['tags'] ?? [],
        ];
    }

    /**
     * @return \Crustum\Queue\Event\EventDispatcher
     */
    protected function dispatcher(): EventDispatcher
    {
        return $this->dispatcher ??= EventDispatcher::instance();
    }
}
