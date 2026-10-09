<?php
declare(strict_types=1);

namespace Crustum\Queue\Processor;

use Closure;
use Crustum\Queue\Contract\CallbackSerializerInterface;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;

/**
 * Serializable Closure Serializer
 *
 * Default `CallbackSerializerInterface`: `base64(serialize())` wrapping in
 * `laravel/serializable-closure`. Requires the package only when actually
 * used — packing throws a descriptive error without it, unpacking skips.
 */
class SerializableClosureSerializer implements CallbackSerializerInterface
{
    /**
     * @param \Closure $callback Response or failure callback
     * @return string Packed callback
     * @throws \RuntimeException Without the serializer package or on unserializable captures
     */
    public function pack(Closure $callback): string
    {
        if (!class_exists(SerializableClosure::class)) {
            throw new RuntimeException(
                'Packing queued callbacks requires `laravel/serializable-closure`.',
            );
        }

        try {
            return base64_encode(serialize(new SerializableClosure($callback)));
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Queued completion callbacks must be serializable for transport: '
                . 'avoid by-reference captures and unserializable bound state.',
                0,
                $throwable,
            );
        }
    }

    /**
     * @param string $payload Packed callback
     * @return callable|null Invokable callback, null when undecodable
     */
    public function unpack(string $payload): ?callable
    {
        if (!class_exists(SerializableClosure::class)) {
            return null;
        }

        try {
            $unpacked = unserialize(base64_decode($payload, true) ?: '');
        } catch (Throwable) {
            return null;
        }

        if ($unpacked instanceof Closure || (is_object($unpacked) && is_callable($unpacked))) {
            return $unpacked;
        }

        return null;
    }
}
