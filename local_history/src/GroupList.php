<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

use function array_column;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * The list of place groups: checking, sorting and converting them.
 *
 * A group is a named list of places, and an audience: either everybody in the tree, or only
 * people connected to one of its places.  Each event belongs to one group, which decides who
 * sees it.  A family website normally has a few groups, for example "Springfield" (local
 * events, shown only to people connected to the town) and "United States" (national events,
 * such as wars, shown to everyone).
 *
 * A group is an array with these keys:
 *   key    a short, stable id, e.g. "springfield".  Chosen automatically from the name, unless
 *          one is already set, so that renaming a group does not disconnect its events from it.
 *   label  the name shown on the settings page and in the event editor, e.g. "Springfield"
 *   places the group's places, in the format PlaceMatcher reads: one per line, or a line
 *          starting and ending with a slash for a regular expression.  Not used when scope is
 *          "everyone".
 *   scope  "connected" (only people connected to one of its places) or "everyone"
 */
class GroupList
{
    public const SCOPE_CONNECTED = 'connected';
    public const SCOPE_EVERYONE  = 'everyone';

    public const MAX_GROUPS = 20;

    private const MAX_LABEL = 60;
    private const MAX_PLACE_LINE   = 200;
    private const MAX_PLACE_LINES  = 50;

    /**
     * Check groups from a form or a file.
     *
     * @param array<mixed> $rows
     *
     * @return array{0:array<array<string,mixed>>,1:array<string>} the good groups, and a message for each one rejected
     */
    public static function clean(array $rows): array
    {
        $groups = [];
        $errors = [];
        $keys   = [];

        foreach (array_slice($rows, 0, self::MAX_GROUPS * 2) as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }

            $label = self::line($row['label'] ?? '', self::MAX_LABEL);

            [$places] = PlaceMatcher::cleanList((string) ($row['places'] ?? ''), self::MAX_PLACE_LINE, self::MAX_PLACE_LINES);

            if ($label === '' && $places === '') {
                continue;
            }

            if ($label === '') {
                $errors[] = 'A group with no name was not saved. Every group needs a name.';
                continue;
            }

            if (count($groups) >= self::MAX_GROUPS) {
                $errors[] = '“' . $label . '” was not saved: there is a limit of ' . self::MAX_GROUPS . ' groups.';
                continue;
            }

            $scope = ($row['scope'] ?? '') === self::SCOPE_EVERYONE ? self::SCOPE_EVERYONE : self::SCOPE_CONNECTED;

            // Keep an existing group's key, even if its name changes, so that events already
            // assigned to it are not disconnected.  Only a brand new group, or one whose key was
            // not a key this module would ever have generated, gets a fresh one from its name.
            $key = self::line($row['key'] ?? '', 40);

            if ($key === '' || !self::isValidKey($key) || in_array($key, $keys, true)) {
                $key = self::slugify($label, $keys);
            }

            $keys[]   = $key;
            $groups[] = [
                'key'    => $key,
                'label'  => $label,
                'places' => $places,
                'scope'  => $scope,
            ];
        }

        return [$groups, $errors];
    }

    /**
     * Read the saved list.
     *
     * @return array<array<string,mixed>>
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? self::clean($data)[0] : [];
    }

    /**
     * Turn a name into a short, URL-safe id, avoiding any already in use.
     *
     * @param array<string> $taken
     */
    public static function slugify(string $label, array $taken): string
    {
        $key = mb_strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/u', '-', $label), '-'));
        $key = mb_substr($key, 0, 30);

        if ($key === '') {
            $key = 'group';
        }

        $base = $key;
        $n    = 2;

        while (in_array($key, $taken, true)) {
            $key = mb_substr($base, 0, 27) . '-' . $n;
            $n++;
        }

        return $key;
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $key) === 1;
    }

    /**
     * @param array<array<string,mixed>> $groups
     *
     * @return array<string>
     */
    public static function keys(array $groups): array
    {
        return array_column($groups, 'key');
    }

    /**
     * One line of plain text.
     *
     * @param mixed $value
     */
    private static function line($value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
    }
}
