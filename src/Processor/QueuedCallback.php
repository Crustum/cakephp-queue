<?php
declare(strict_types=1);

namespace Crustum\Queue\Processor;

use Closure;
use Crustum\Queue\Contract\CallbackSerializerInterface;

/**
 * Queued Callback
 *
 * Single format definition for completion callbacks traveling in the payload
 * (`then` / `catch` keys): the dispatch side packs, the processor side
 * unpacks. Custom serializers plug in per call — no global state.
 */
class QueuedCallback
{
    /**
     * Pack a callback for queue transport.
     *
     * @param \Closure $callback Response or failure callback
     * @param \Crustum\Queue\Contract\CallbackSerializerInterface|null $serializer Custom serializer
     * @return string Packed callback
     */
    public static function pack(Closure $callback, ?CallbackSerializerInterface $serializer = null): string
    {
        return ($serializer ?? new SerializableClosureSerializer())->pack($callback);
    }

    /**
     * Unpack one callback item.
     *
     * @param mixed $item Packed or already-invokable callback
     * @param \Crustum\Queue\Contract\CallbackSerializerInterface|null $serializer Custom serializer
     * @return callable|null Invokable callback, null when undecodable
     */
    public static function unpack(mixed $item, ?CallbackSerializerInterface $serializer = null): ?callable
    {
        if ($item instanceof Closure) {
            return $item;
        }

        if (!is_string($item) || $item === '') {
            return null;
        }

        return ($serializer ?? new SerializableClosureSerializer())->unpack($item);
    }

    /**
     * Unpack a callback list, skipping corrupt entries one by one.
     *
     * @param mixed $packed Packed list (or anything else)
     * @param \Crustum\Queue\Contract\CallbackSerializerInterface|null $serializer Custom serializer
     * @return list<callable> Invokable callbacks
     */
    public static function unpackList(mixed $packed, ?CallbackSerializerInterface $serializer = null): array
    {
        if (!is_array($packed)) {
            return [];
        }

        $callbacks = [];
        foreach ($packed as $item) {
            $callback = static::unpack($item, $serializer);
            if ($callback !== null) {
                $callbacks[] = $callback;
            }
        }

        return $callbacks;
    }
}
