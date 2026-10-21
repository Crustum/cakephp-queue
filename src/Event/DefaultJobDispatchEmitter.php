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
        $payload = $this->buildPayload($jobClass, $data, $config);
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
        $payload = $this->buildPayload($jobClass, $data, $config);
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
    public function buildPayload(string $jobClass, array $data, array $config = []): array
    {
        $metadata = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
        $id = is_string($metadata['_uniqueId'] ?? null)
            ? $metadata['_uniqueId']
            : hash('sha256', $jobClass . '|' . json_encode($data));

        $dtoClass = $config['dtoClass'] ?? null;

        $payload = [
            'id' => $id,
            'job' => $jobClass,
            'body' => [
                'class' => [$jobClass, 'execute'],
                'args' => [$data],
            ],
            'metadata' => $metadata,
        ];
        if (is_string($dtoClass) && $dtoClass !== '') {
            $payload['dtoClass'] = $dtoClass;
        }

        return $payload;
    }

    /**
     * @return \Crustum\Queue\Event\EventDispatcher
     */
    protected function dispatcher(): EventDispatcher
    {
        return $this->dispatcher ??= EventDispatcher::instance();
    }
}
