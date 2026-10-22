<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Debug;

use Cake\TestSuite\TestCase;
use Crustum\Queue\Debug\RelatedLookup;
use stdClass;

/**
 * RelatedLookup Test Case
 */
class RelatedLookupTest extends TestCase
{
    /**
     * Builds a stored panel row stub.
     *
     * @param string $requestId Request id.
     * @param array<string, mixed> $payload Unserialized panel payload.
     * @return object
     */
    protected function row(string $requestId, array $payload): object
    {
        $row = new stdClass();
        $row->request_id = $requestId;
        $row->content = serialize($payload);

        return $row;
    }

    /**
     * Opposite kinds resolve newest first.
     *
     * @return void
     */
    public function testFindsOppositeKind(): void
    {
        $rows = [
            $this->row('web-1', ['groups' => [['key' => 'k-1', 'kind' => 'push']]]),
            $this->row('worker-1', ['groups' => [['key' => 'k-1', 'kind' => 'execution']]]),
            $this->row('worker-2', ['groups' => [['key' => 'k-1', 'kind' => 'execution']]]),
        ];

        $this->assertSame('worker-1', RelatedLookup::find($rows, 'k-1', 'push'));
        $this->assertSame('web-1', RelatedLookup::find($rows, 'k-1', 'execution'));
    }

    /**
     * Same-kind rows are the fallback, unknown keys miss.
     *
     * @return void
     */
    public function testFallbackAndMiss(): void
    {
        $rows = [
            $this->row('web-1', ['groups' => [['key' => 'k-1', 'kind' => 'push']]]),
            $this->row('broken', ['corrupt']),
        ];

        $this->assertSame('web-1', RelatedLookup::find($rows, 'k-1', 'push'));
        $this->assertNull(RelatedLookup::find($rows, 'nope', 'push'));
        $this->assertNull(RelatedLookup::find($rows, '', 'push'));
        $this->assertNull(RelatedLookup::find([], 'k-1', 'push'));
    }

    /**
     * Corrupt payloads and rows without ids never match.
     *
     * @return void
     */
    public function testCorruptRowsSkipped(): void
    {
        $bad = new stdClass();
        $bad->request_id = 'broken';
        $bad->content = 'not-serialized{{{';

        $empty = new stdClass();
        $empty->request_id = '';
        $empty->content = serialize(['groups' => [['key' => 'k-1', 'kind' => 'push']]]);

        $this->assertNull(RelatedLookup::find([$bad, $empty, 'nope'], 'k-1', 'execution'));
    }
}
