<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

/**
 * Taggable Interface
 *
 * Interface for jobs that can generate custom tags.
 */
interface TaggableInterface
{
    /**
     * Create custom tags for the job based on its data.
     *
     * @param array<string, mixed> $data Job data
     * @return array<string> Array of custom tags
     */
    public static function createTags(array $data): array;
}
