<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

use Fisharebest\Webtrees\Date;

use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
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
 *   place   where it happened (optional)
 *   note    a private reminder for the website owner, never shown to visitors (optional)
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
     *   {"name": "...", "description": "...", "places": ["...", "..."], "events": [{...}, {...}]}
     *   [{...}, {...}]
     *
     * @return array{name:string,description:string,places:array<string>,events:array<array<string,mixed>>,errors:array<string>}|null null if it is not valid JSON of this kind
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

        $rows = [];
        foreach ($data['events'] as $row) {
            if (is_array($row)) {
                // Events are on unless the file says otherwise.
                $row['enabled'] = $row['enabled'] ?? true;
                $rows[]         = $row;
            }
        }

        [$events, $errors] = self::clean($rows);

        $places = [];
        foreach (is_array($data['places'] ?? null) ? $data['places'] : [] as $place) {
            if (is_string($place) && trim($place) !== '') {
                $places[] = self::line($place, self::MAX_PLACE);
            }
        }

        return [
            'name'        => self::line($data['name'] ?? '', 100),
            'description' => self::line($data['description'] ?? '', 300),
            'places'      => $places,
            'events'      => $events,
            'errors'      => $errors,
        ];
    }

    /**
     * Put new events after the existing ones, skipping any already in the list (same date and title).
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
            $known[$event['date'] . '|' . $event['title']] = true;
        }

        $added = 0;
        foreach ($new as $event) {
            $key = $event['date'] . '|' . $event['title'];

            if (!isset($known[$key]) && count($existing) < self::MAX_EVENTS) {
                $known[$key] = true;
                $existing[]  = $event;
                $added++;
            }
        }

        return [self::sort($existing), $added];
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
     * Tidy a list of places: one per line, no repeats.
     *
     * @return array{0:string,1:array<string>} the tidy list, and the lines that were dropped
     */
    public static function cleanPlaces(string $lines): array
    {
        $keep    = [];
        $dropped = [];

        foreach (PlaceMatcher::lines($lines) as $line) {
            $line = self::line($line, self::MAX_PLACE);

            if (PlaceMatcher::isPattern($line) && !PlaceMatcher::isValidPattern($line)) {
                $dropped[] = $line;
            } else {
                $keep[] = $line;
            }
        }

        return [implode("\n", array_slice(array_values(array_unique($keep)), 0, 50)), $dropped];
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
