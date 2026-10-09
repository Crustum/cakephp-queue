<?php
declare(strict_types=1);

namespace Crustum\Queue\Contract;

/**
 * Has Callback Serializer Interface
 *
 * Jobs implementing this contract pick the serializer for their completion
 * callbacks. Static on purpose: the pack side only holds the job
 * class-string (no instance), while the unpack side already resolved one —
 * both call the same method with zero constructions.
 */
interface HasCallbackSerializer
{
    /**
     * Pick the callback serializer for this job.
     *
     * @return \Crustum\Queue\Contract\CallbackSerializerInterface|null Null uses the default
     */
    public static function callbackSerializer(): ?CallbackSerializerInterface;
}
