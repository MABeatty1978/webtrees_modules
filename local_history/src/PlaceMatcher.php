<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function trim;

/**
 * Decide whether a place name, such as "Kelley's Island, Erie, Ohio, USA", is one of the
 * places the website owner has listed.
 *
 * Each line of the list is either:
 *  - plain text, which matches any place containing it.  Case, apostrophes and extra spaces
 *    are ignored, so "Kelleys Island" also matches "Kelley's Island, Ohio"; or
 *  - a regular expression, if the line starts and ends with a slash, such as /\bKell?e?ys? Island\b/i
 */
class PlaceMatcher
{
    /** @var array<string> */
    private $words = [];

    /** @var array<string> */
    private $patterns = [];

    public function __construct(string $lines)
    {
        foreach (self::lines($lines) as $line) {
            if (self::isPattern($line)) {
                if (self::isValidPattern($line)) {
                    $this->patterns[] = $line;
                }
            } else {
                $this->words[] = self::normalize($line);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->words === [] && $this->patterns === [];
    }

    public function matches(string $place): bool
    {
        $normalized = self::normalize($place);

        foreach ($this->words as $word) {
            if ($word !== '' && strpos($normalized, $word) !== false) {
                return true;
            }
        }

        // Patterns see the place as written, and with apostrophes removed, so that
        // /Kelleys Island/ also finds "Kelley's Island".
        $without_apostrophes = self::withoutApostrophes($place);

        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $place) === 1 || @preg_match($pattern, $without_apostrophes) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string> the non-empty lines of a list
     */
    public static function lines(string $lines): array
    {
        $result = [];

        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $result[] = $line;
            }
        }

        return $result;
    }

    public static function isPattern(string $line): bool
    {
        return strlen($line) >= 3 && $line[0] === '/' && strrpos($line, '/') > 0;
    }

    public static function isValidPattern(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(self::withoutApostrophes($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function withoutApostrophes(string $text): string
    {
        return str_replace(["'", '’', '‘', '`'], '', $text);
    }
}
