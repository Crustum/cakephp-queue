<?php
declare(strict_types=1);

namespace Crustum\Queue\Debug;

/**
 * Finds toolbar rows related by correlation key.
 *
 * Pure lookup over stored panel rows: unserializes each panel payload,
 * matches groups carrying the key, and prefers the opposite kind (push
 * versus execution) so push rows jump to worker runs and back. Newest
 * first — retries resolve to the latest run.
 */
class RelatedLookup
{
    /**
     * Finds the related request id for a correlation key.
     *
     * @param iterable<array-key, mixed> $panels Stored panel rows with `request_id` and `content`.
     * @param string $key Correlation key.
     * @param string $kind Requesting group kind (`push` or `execution`).
     * @return string|null
     */
    public static function find(iterable $panels, string $key, string $kind): ?string
    {
        if ($key === '') {
            return null;
        }

        $fallback = null;
        foreach ($panels as $panel) {
            if (!is_object($panel)) {
                continue;
            }

            $requestId = (string)($panel->request_id ?? '');
            if ($requestId === '') {
                continue;
            }

            $content = (string)($panel->content ?? '');
            if ($content === '' || !str_contains($content, $key)) {
                continue;
            }

            $data = unserialize($content, ['allowed_classes' => false]);
            if (!is_array($data)) {
                continue;
            }

            $match = self::match($data, $key, $kind);
            if ($match === 'opposite') {
                return $requestId;
            }

            if ($match === 'same' && $fallback === null) {
                $fallback = $requestId;
            }
        }

        return $fallback;
    }

    /**
     * Matches stored groups against the key.
     *
     * @param array<string, mixed> $data Unserialized panel payload.
     * @param string $key Correlation key.
     * @param string $kind Requesting group kind.
     * @return string `opposite`, `same`, or empty when nothing matches.
     */
    protected static function match(array $data, string $key, string $kind): string
    {
        $found = false;
        foreach (array_values((array)($data['groups'] ?? [])) as $group) {
            $group = (array)$group;
            if ((string)($group['key'] ?? '') !== $key) {
                continue;
            }

            $found = true;
            $groupKind = (string)($group['kind'] ?? '');
            if ($groupKind !== '' && $groupKind !== $kind) {
                return 'opposite';
            }
        }

        return $found ? 'same' : '';
    }
}
