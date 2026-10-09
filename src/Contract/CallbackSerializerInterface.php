<?php
declare(strict_types=1);

namespace Crustum\Queue\Contract;

use Closure;

/**
 * Callback Serializer Interface
 *
 * Packs `then` / `catch` completion callbacks for queue transport and
 * unpacks them where the job runs. Pack and unpack live in different
 * processes (dispatch vs worker), so both sides must resolve the same
 * serializer — see `HasCallbackSerializer`.
 */
interface CallbackSerializerInterface
{
    /**
     * Pack a callback for queue transport.
     *
     * @param \Closure $callback Response or failure callback
     * @return string Packed callback
     * @throws \RuntimeException When the callback cannot be packed
     */
    public function pack(Closure $callback): string;

    /**
     * Unpack a transported callback.
     *
     * Must never throw: the worker has to survive corrupt payloads.
     *
     * @param string $payload Packed callback
     * @return callable|null Invokable callback, null when undecodable
     */
    public function unpack(string $payload): ?callable;
}
