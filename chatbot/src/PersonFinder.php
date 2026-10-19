<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Soundex;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

use function abs;
use function addcslashes;
use function array_filter;
use function array_intersect;
use function array_slice;
use function array_values;
use function explode;
use function mb_stripos;
use function mb_strtolower;
use function preg_split;
use function usort;

/**
 * Find individuals by name, in one tree, respecting webtrees privacy.
 */
class PersonFinder
{
    // Rows to read from the database, before privacy filtering and ranking.
    private const MAX_ROWS = 300;

    private bool $truncated = false;

    /**
     * Find the individuals who best match a name, best match first.
     *
     * @return array<array{individual:Individual,score:int}>
     */
    public function find(Tree $tree, string $given, string $surname, ?int $birth_year, string $place): array
    {
        $individuals = $this->search($tree, $given, $surname, false);

        // Nothing spelled that way?  Try names that sound the same.
        if ($individuals === []) {
            $individuals = $this->search($tree, $given, $surname, true);
        }

        $matches = [];
        foreach ($individuals as $individual) {
            $matches[] = [
                'individual' => $individual,
                'score'      => $this->score($individual, $given, $surname, $birth_year, $place),
            ];
        }

        usort($matches, static function (array $x, array $y): int {
            return $y['score'] <=> $x['score'] ?: self::birthYear($x['individual']) <=> self::birthYear($y['individual']);
        });

        return $matches;
    }

    /**
     * Everyone with a surname, oldest first.
     *
     * @return array<Individual>
     */
    public function findSurname(Tree $tree, string $surname): array
    {
        $individuals = $this->search($tree, '', $surname, false) ?: $this->search($tree, '', $surname, true);

        usort($individuals, static function (Individual $x, Individual $y): int {
            return self::birthYear($x) <=> self::birthYear($y);
        });

        return $individuals;
    }

    /**
     * Did the last search stop before reading every matching name?
     */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Sort key for a birth year.  Unknown dates sort last.
     */
    public static function birthYear(Individual $individual): int
    {
        $date = $individual->getBirthDate();

        return $date->isOK() ? $date->gregorianYear() : 9999;
    }

    /**
     * @return array<Individual> visible individuals, without duplicates
     */
    private function search(Tree $tree, string $given, string $surname, bool $phonetic): array
    {
        $first_given = $this->firstWord($given);

        if ($first_given === '' && $surname === '') {
            return [];
        }

        // Search the "name" table, which has a row for every name (birth, married, alias...)
        $query = DB::table('name')
            ->join('individuals', static function (JoinClause $join): void {
                $join
                    ->on('individuals.i_file', '=', 'name.n_file')
                    ->on('individuals.i_id', '=', 'name.n_id');
            })
            ->where('name.n_file', '=', $tree->id())
            ->select(['individuals.i_id', 'individuals.i_gedcom'])
            ->limit(self::MAX_ROWS);

        if ($phonetic) {
            if ($surname !== '') {
                $this->wherePhonetic($query, [
                    'name.n_soundex_surn_std' => Soundex::russell($surname),
                    'name.n_soundex_surn_dm'  => Soundex::daitchMokotoff($surname),
                ]);
            }

            if ($first_given !== '') {
                $this->wherePhonetic($query, [
                    'name.n_soundex_givn_std' => Soundex::russell($first_given),
                    'name.n_soundex_givn_dm'  => Soundex::daitchMokotoff($first_given),
                ]);
            }
        } else {
            // This can use the index on n_surn.  Surnames with prefixes ("van Dyke") are found phonetically.
            if ($surname !== '') {
                $query->where('name.n_surn', DB::iLike(), $this->escapeLike($surname));
            }

            if ($first_given !== '') {
                // Match the first given name, or a middle name: "John" finds "John William" and "William John".
                $like = $this->escapeLike($first_given);
                $query->where(static function (Builder $query) use ($like): void {
                    $query
                        ->where('name.n_givn', DB::iLike(), $like . '%')
                        ->orWhere('name.n_givn', DB::iLike(), '% ' . $like . '%');
                });
            }
        }

        $rows            = $query->get();
        $this->truncated = $rows->count() >= self::MAX_ROWS;

        $individuals = [];
        foreach ($rows as $row) {
            if (isset($individuals[$row->i_id])) {
                continue;
            }

            $individual = Registry::individualFactory()->make($row->i_id, $tree, $row->i_gedcom);

            // webtrees decides who is living, and what this user may see.
            if ($individual instanceof Individual && $individual->canShow()) {
                $individuals[$row->i_id] = $individual;
            }
        }

        return array_values($individuals);
    }

    /**
     * Rank a match.  Exact names, and the birth year/place the user gave, count most.
     */
    private function score(Individual $individual, string $given, string $surname, ?int $birth_year, string $place): int
    {
        $score        = 0;
        $query_givens = preg_split('/\s+/', mb_strtolower(trim($given)), -1, PREG_SPLIT_NO_EMPTY);
        $surname      = mb_strtolower($surname);

        $given_match   = false;
        $middle_match  = false;
        $surname_match = false;
        foreach ($individual->getAllNames() as $name) {
            $givens        = preg_split('/\s+/', mb_strtolower($name['givn'] ?? ''));
            $given_match   = $given_match || ($query_givens !== [] && $givens[0] === $query_givens[0]);
            $surname_match = $surname_match || ($surname !== '' && mb_strtolower($name['surn'] ?? '') === $surname);

            // "John William Smith" should prefer the John Smith whose middle name is William.
            $middle_match = $middle_match || array_intersect(array_slice($query_givens, 1), $givens) !== [];
        }

        $score += $given_match ? 3 : 0;
        $score += $middle_match ? 3 : 0;
        $score += $surname_match ? 3 : 0;

        if ($birth_year !== null) {
            $year = self::birthYear($individual);
            if ($year !== 9999) {
                $difference = abs($year - $birth_year);
                $score     += $difference <= 2 ? 6 : ($difference <= 10 ? 2 : -2);
            }
        }

        if ($place !== '') {
            foreach ([$individual->getBirthPlace(), $individual->getDeathPlace()] as $individual_place) {
                if (mb_stripos($individual_place->gedcomName(), $place) !== false) {
                    $score += 3;
                    break;
                }
            }
        }

        return $score;
    }

    /**
     * Soundex codes may contain alternatives, separated by colons.  webtrees
     * stores the same format, so a match on any code is a match.
     *
     * @param array<string,string> $fields column => soundex codes
     */
    private function wherePhonetic(Builder $query, array $fields): void
    {
        $fields = array_filter($fields, static fn (string $codes): bool => $codes !== '');

        if ($fields === []) {
            return;
        }

        $query->where(static function (Builder $query) use ($fields): void {
            foreach ($fields as $field => $codes) {
                foreach (explode(':', $codes) as $code) {
                    $query->orWhere($field, DB::iLike(), '%' . $code . '%');
                }
            }
        });
    }

    private function firstWord(string $text): string
    {
        $words = preg_split('/\s+/', trim($text));

        return $words[0] ?? '';
    }

    private function escapeLike(string $text): string
    {
        return addcslashes($text, '\\%_');
    }
}
