<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

use Fisharebest\Webtrees\Date;

use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function strtoupper;
use function trim;
use function usort;

/**
 * The list of local history events: checking, sorting and converting them.
 *
 * An event is an array with these keys:
 *   date    a GEDCOM date, such as "1833", "ABT 1842", "19 SEP 1864" or "FROM 1861 TO 1865"
 *   title   a short name, shown as the type of fact
 *   text    a sentence describing it (optional)
 *   place   where it happened, shown with the event (optional)
 *   note    a private reminder for the website owner, never shown to visitors (optional)
 *   group   the key of the GroupList group that decides who sees this event
 *   enabled false to keep an event in the list, but not show it
 */
class EventList
{
    public const MAX_EVENTS = 500;

    private const MAX_DATE  = 60;
    private const MAX_TITLE = 100;
    private const MAX_TEXT  = 500;
    private const MAX_PLACE = 200;
    private const MAX_NOTE  = 300;
    private const MAX_GROUP = 40;

    /**
     * Check events from a form or a file, and put them in date order.
     *
     * @param array<mixed> $rows
     *
     * @return array{0:array<array<string,mixed>>,1:array<string>} the good events, and a message for each one rejected
     */
    public static function clean(array $rows): array
    {
        $events = [];
        $errors = [];

        foreach (array_slice($rows, 0, self::MAX_EVENTS * 2) as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }

            $event = [
                'date'    => strtoupper(self::line($row['date'] ?? '', self::MAX_DATE)),
                'title'   => self::line($row['title'] ?? '', self::MAX_TITLE),
                'text'    => self::line($row['text'] ?? '', self::MAX_TEXT),
                'place'   => self::line($row['place'] ?? '', self::MAX_PLACE),
                'note'    => self::line($row['note'] ?? '', self::MAX_NOTE),
                'group'   => self::line($row['group'] ?? '', self::MAX_GROUP),
                'enabled' => !empty($row['enabled']),
            ];

            // A blank row.
            if ($event['date'] === '' && $event['title'] === '' && $event['text'] === '' && $event['place'] === '' && $event['note'] === '') {
                continue;
            }

            $name = $event['title'] !== '' ? '“' . $event['title'] . '”' : '“' . ($event['text'] ?: $event['date']) . '”';

            if ($event['title'] === '') {
                $errors[] = $name . ' was not saved: it needs a title.';
            } elseif (!self::isValidDate($event['date'])) {
                $errors[] = $name . ' was not saved: “' . $event['date'] . '” is not a date webtrees understands. Use a form such as 1850, ABT 1850, 19 SEP 1864 or FROM 1861 TO 1865.';
            } elseif (count($events) >= self::MAX_EVENTS) {
                $errors[] = $name . ' was not saved: there is a limit of ' . self::MAX_EVENTS . ' events.';
            } else {
                $events[] = $event;
            }
        }

        return [self::sort($events), $errors];
    }

    /**
     * Read a file of events, as made by "Export" or supplied as an example.  It is JSON, in one of these forms:
     *
     *   {"name": "...", "description": "...",
     *    "groups": [{"label": "...", "places": [...], "scope": "connected"}, ...],
     *    "events": [{"date": "...", "title": "...", "group": "<a group's key or label>"}, ...]}
     *
     *   {"name": "...", "places": ["...", "..."], "events": [{...}, {...}]}
     *       a simpler, single-group form: one group is made from "places", named after "name".
     *
     *   [{...}, {...}]
     *       a bare list of events, and nothing else.  If none of them name a group, they all go
     *       into one group with no places, shown to everyone.
     *
     * @return array{name:string,description:string,groups:array<array<string,mixed>>,events:array<array<string,mixed>>,errors:array<string>}|null
     *               null if it is not valid JSON of this kind
     */
    public static function fromJson(string $json): ?array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        // A plain list of events, with no details about the pack.
        if (isset($data[0]) || $data === []) {
            $data = ['events' => $data];
        }

        if (!is_array($data['events'] ?? null)) {
            return null;
        }

        $raw_groups = is_array($data['groups'] ?? null) ? $data['groups'] : null;

        // The simpler form: one group, made from a flat list of places.
        if ($raw_groups === null) {
            $places = [];
            foreach (is_array($data['places'] ?? null) ? $data['places'] : [] as $place) {
                if (is_string($place) && trim($place) !== '') {
                    $places[] = $place;
                }
            }

            $raw_groups = $places === [] ? [] : [[
                'label'  => $data['name'] ?? 'Local',
                'places' => implode("\n", $places),
                'scope'  => GroupList::SCOPE_CONNECTED,
            ]];
        }

        [$groups, $group_errors] = GroupList::clean($raw_groups);

        // Events may name a group by its key or, more conveniently for someone hand-writing a
        // pack, by its label.  An event naming no group goes into the pack's first group, if it
        // has one — or is shown to everyone, if the pack defines no groups at all.
        $label_to_key = [];
        foreach ($groups as $group) {
            $label_to_key[self::fold($group['label'])] = $group['key'];
        }

        $default_key = $groups[0]['key'] ?? '';

        if ($groups === []) {
            $groups[]     = ['key' => 'everyone', 'label' => 'Everyone', 'places' => '', 'scope' => GroupList::SCOPE_EVERYONE];
            $default_key  = 'everyone';
        }

        $rows = [];
        foreach ($data['events'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Events are on unless the file says otherwise.
            $row['enabled'] = $row['enabled'] ?? true;

            $named = is_string($row['group'] ?? null) ? trim($row['group']) : '';
            $row['group'] = $named === '' ? $default_key : ($label_to_key[self::fold($named)] ?? $named);

            $rows[] = $row;
        }

        [$events, $event_errors] = self::clean($rows);

        return [
            'name'        => self::line($data['name'] ?? '', 100),
            'description' => self::line($data['description'] ?? '', 300),
            'groups'      => $groups,
            'events'      => $events,
            'errors'      => array_merge($group_errors, $event_errors),
        ];
    }

    /**
     * A key to compare names loosely by: case and spacing do not matter.
     */
    private static function fold(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Put new events after the existing ones, skipping any already in the list (same date,
     * title and group).
     *
     * @param array<array<string,mixed>> $existing
     * @param array<array<string,mixed>> $new
     *
     * @return array{0:array<array<string,mixed>>,1:int} the combined list, and how many were added
     */
    public static function merge(array $existing, array $new): array
    {
        $known = [];
        foreach ($existing as $event) {
            $known[self::mergeKey($event)] = true;
        }

        $added = 0;
        foreach ($new as $event) {
            $key = self::mergeKey($event);

            if (!isset($known[$key]) && count($existing) < self::MAX_EVENTS) {
                $known[$key] = true;
                $existing[]  = $event;
                $added++;
            }
        }

        return [self::sort($existing), $added];
    }

    /**
     * @param array<string,mixed> $event
     */
    private static function mergeKey(array $event): string
    {
        return $event['date'] . '|' . $event['title'] . '|' . $event['group'];
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
     * The GEDCOM for an event, which is how webtrees stores and shows facts.
     *
     * @param array<string,mixed> $event
     */
    public static function toGedcom(array $event): string
    {
        $gedcom = '1 EVEN ' . $event['text'] . "\n2 TYPE " . $event['title'] . "\n2 DATE " . $event['date'];

        if ($event['place'] !== '') {
            $gedcom .= "\n2 PLAC " . $event['place'];
        }

        return $gedcom;
    }

    public static function isValidDate(string $date): bool
    {
        return $date !== '' && (new Date($date))->isOK();
    }

    /**
     * @param array<array<string,mixed>> $events
     *
     * @return array<array<string,mixed>>
     */
    private static function sort(array $events): array
    {
        $days = array_map(static function (array $event): int {
            return (new Date($event['date']))->minimumJulianDay();
        }, $events);

        $order = array_keys($events);

        usort($order, static function (int $a, int $b) use ($days, $events): int {
            return [$days[$a], $events[$a]['title']] <=> [$days[$b], $events[$b]['title']];
        });

        return array_map(static function (int $key) use ($events): array {
            return $events[$key];
        }, $order);
    }

    /**
     * One line of plain text.  GEDCOM is line-based, so line breaks are not allowed.
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
