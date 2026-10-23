<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\GedcomRecord;

use function explode;
use function implode;
use function in_array;
use function max;
use function min;
use function preg_match;
use function strrpos;
use function strtoupper;
use function substr;
use function trim;

/**
 * Work out the span of time a person is known to have spent at a place, from their own
 * dated facts (and, if the website owner allows it, the facts of their families).
 *
 * This is narrower than a person's whole lifespan.  Someone born on an island in 1845 who
 * moved away in 1850 and died elsewhere in 1910 should not be shown events from 1900 just
 * because they were alive — they were no longer there.  A person who is only ever buried at
 * a place, with no other fact placing them there, was very likely never a resident at all.
 *
 * The window is built only from facts whose date is fully understood (so an estimated or
 * blank date never narrows it), and, by default, only from fact types the website owner has
 * chosen as good evidence of actually being there (such as a birth, a residence or a census),
 * rather than ones that merely mention the place (such as a note).
 */
class PresenceWindow
{
    /**
     * @param array<GedcomRecord> $records the person's own record, and any families to also look at
     * @param array<string>       $tags    bare fact tags that count as evidence, e.g. ["BIRT", "RESI"].
     *                                      An empty array means any dated fact at a matching place counts.
     *
     * @return array{0:int,1:int}|null the earliest and latest Julian day the person is known to have
     *                                  been at a matching place, or null if there is no such evidence
     *                                  (for example, a tree with only a birth and a death recorded)
     */
    public static function find(array $records, PlaceMatcher $matcher, array $tags): ?array
    {
        $min = null;
        $max = null;

        foreach ($records as $record) {
            foreach ($record->facts() as $fact) {
                if (!$matcher->matches($fact->place()->gedcomName())) {
                    continue;
                }

                if ($tags !== [] && !in_array(self::bareTag($fact), $tags, true)) {
                    continue;
                }

                $date = $fact->date();

                if (!$date->isOK()) {
                    continue;
                }

                $lo = $date->minimumJulianDay();
                $hi = $date->maximumJulianDay();

                $min = $min === null ? $lo : min($min, $lo);
                $max = $max === null ? $hi : max($max, $hi);
            }
        }

        return $min === null ? null : [$min, $max];
    }

    /**
     * Turn a comma-separated list of fact tags, as typed into the settings page, into a
     * clean array such as ["BIRT", "RESI", "CENS"].
     *
     * @return array<string>
     */
    public static function parseTags(string $csv): array
    {
        $tags = [];

        foreach (explode(',', $csv) as $tag) {
            $tag = strtoupper(trim($tag));

            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Tidy a list of tags back into the comma-separated form the settings page shows,
     * keeping only plausible GEDCOM tag names.
     */
    public static function cleanTags(string $csv): string
    {
        $tags = [];

        foreach (self::parseTags($csv) as $tag) {
            if (preg_match('/^[A-Z][A-Z0-9_]{0,14}$/', $tag) === 1) {
                $tags[] = $tag;
            }
        }

        return implode(',', $tags);
    }

    /**
     * A fact's tag includes which kind of record it belongs to, e.g. "INDI:BIRT" or
     * "FAM:MARR".  Strip that off, so "BIRT" and "MARR" can be compared directly.
     */
    private static function bareTag(Fact $fact): string
    {
        $tag = $fact->tag();
        $pos = strrpos($tag, ':');

        return $pos === false ? $tag : substr($tag, $pos + 1);
    }
}
