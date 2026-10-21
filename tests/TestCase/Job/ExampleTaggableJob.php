<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Crustum\Queue\Job\TaggableInterface;
use Interop\Queue\Processor;

/**
 * Taggable dispatchable job for tests.
 */
class ExampleTaggableJob implements JobInterface, DispatchableInterface, TaggableInterface
{
    use DispatchableTrait;

    /**
     * @param array<string, mixed>|object $data Job data
     * @return array<string>
     */
    public static function createTags(array|object $data): array
    {
        $package = is_array($data) ? ($data['package'] ?? null) : null;

        return is_string($package) ? ['package:' . $package] : [];
    }

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        return Processor::ACK;
    }
}
