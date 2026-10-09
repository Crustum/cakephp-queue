<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Processor;

use Cake\TestSuite\TestCase;
use Closure;
use Crustum\Queue\Contract\CallbackSerializerInterface;
use Crustum\Queue\Processor\QueuedCallback;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;

/**
 * QueuedCallback Test Case
 *
 * Covers the codec plumbing with a stub serializer (the real
 * `SerializableClosure` roundtrip runs in the Ai suite) plus the
 * missing-dependency behavior of the default.
 */
class QueuedCallbackTest extends TestCase
{
    /**
     * @return void
     */
    public function testPackDelegatesToCustomSerializer(): void
    {
        $serializer = new class implements CallbackSerializerInterface {
            public function pack(Closure $callback): string
            {
                return 'packed';
            }

            public function unpack(string $payload): ?callable
            {
                return $payload === 'packed' ? static fn(): string => 'ran' : null;
            }
        };

        $this->assertSame('packed', QueuedCallback::pack(static fn(): string => 'x', $serializer));
        $this->assertSame(['ran'], $this->keys(QueuedCallback::unpackList(['packed', 'bogus'], $serializer)));
    }

    /**
     * @return void
     */
    public function testUnpackSkipsCorruptEntries(): void
    {
        $this->assertSame([], QueuedCallback::unpackList('not-a-list'));
        $this->assertSame([], QueuedCallback::unpackList(['', 42, null]));
        $this->assertNull(QueuedCallback::unpack(''));
        $this->assertNull(QueuedCallback::unpack(null));
    }

    /**
     * @return void
     */
    public function testUnpackPassesThroughClosures(): void
    {
        $callback = static fn(): string => 'direct';

        $this->assertSame($callback, QueuedCallback::unpack($callback));
    }

    /**
     * @return void
     */
    public function testDefaultPackThrowsWithoutDependency(): void
    {
        if (class_exists(SerializableClosure::class)) {
            $this->markTestSkipped('SerializableClosure is installed.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('laravel/serializable-closure');

        QueuedCallback::pack(static fn(): string => 'x');
    }

    /**
     * @return void
     */
    public function testDefaultUnpackSkipsWithoutDependency(): void
    {
        if (class_exists(SerializableClosure::class)) {
            $this->markTestSkipped('SerializableClosure is installed.');
        }

        $this->assertNull(QueuedCallback::unpack('bogus'));
        $this->assertSame([], QueuedCallback::unpackList(['bogus']));
    }

    /**
     * Map callbacks to their invocation results.
     *
     * @param list<callable> $callbacks Callbacks
     * @return list<string>
     */
    protected function keys(array $callbacks): array
    {
        return array_map(static fn(callable $callback): string => $callback(), $callbacks);
    }
}
