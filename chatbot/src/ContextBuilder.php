<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use Closure;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;

use function array_slice;
use function arsort;
use function count;
use function html_entity_decode;
use function implode;
use function in_array;
use function strip_tags;
use function trim;

/**
 * Collect the family tree information needed to answer a question.
 */
class ContextBuilder
{
    // When the best match scores this much more than the next, assume it is the one the user means.
    private const CONFIDENT_MARGIN = 3;

    private const MAX_CANDIDATES = 8;

    private const MAX_SURNAME_LIST = 25;

    private PersonFinder $finder;

    private FactSheetBuilder $sheets;

    private RelationshipService $relationships;

    /** @var Closure(Individual,Individual):string */
    private Closure $relationship_url;

    /** @var Closure(Tree,string):string */
    private Closure $surname_url;

    /**
     * @param Closure(Individual,Individual):string $relationship_url link to the relationship chart, or ''
     * @param Closure(Tree,string):string           $surname_url      link to the list of individuals, or ''
     */
    public function __construct(
        PersonFinder $finder,
        FactSheetBuilder $sheets,
        RelationshipService $relationships,
        Closure $relationship_url,
        Closure $surname_url
    ) {
        $this->finder           = $finder;
        $this->sheets           = $sheets;
        $this->relationships    = $relationships;
        $this->relationship_url = $relationship_url;
        $this->surname_url      = $surname_url;
    }

    /**
     * @param array{intent:string,people:array<array{given:string,surname:string,birth_year:int|null,place:string}>,refers_to:string} $plan
     *
     * @return array{text:string,focus:Individual|null} focus is the person now being discussed; null means no change
     */
    public function build(Tree $tree, array $plan, ?Individual $discussed, ?Individual $page): array
    {
        $referred = null;

        if ($plan['refers_to'] === 'discussed') {
            $referred = $discussed ?? $page;
        } elseif ($plan['refers_to'] === 'page') {
            $referred = $page ?? $discussed;
        }

        $surname = $plan['people'][0]['surname'] ?? '';

        if ($plan['intent'] === 'surname' && $surname !== '') {
            return $this->surname($tree, $surname);
        }

        if ($plan['intent'] === 'relationship') {
            return $this->relationship($tree, $plan['people'], $referred);
        }

        if ($plan['intent'] === 'person' || $plan['people'] !== []) {
            return $this->person($tree, $plan['people'], $referred);
        }

        return ['text' => 'No family tree lookup was needed for this question.', 'focus' => null];
    }

    /**
     * @param array<array{given:string,surname:string,birth_year:int|null,place:string}> $people
     *
     * @return array{text:string,focus:Individual|null}
     */
    private function person(Tree $tree, array $people, ?Individual $referred): array
    {
        $texts = [];
        $focus = null;

        foreach ($people as $person) {
            [$text, $individual] = $this->resolve($tree, $person);
            $texts[] = $text;
            $focus ??= $individual;
        }

        // e.g. "Was Mary Jones his wife?" needs both people.
        if ($referred instanceof Individual && $referred !== $focus) {
            $texts[] = $this->sheets->full($referred);
            $focus ??= $referred;
        }

        if ($texts === []) {
            return ['text' => 'The question does not name a person, and nobody is being discussed yet.', 'focus' => null];
        }

        return ['text' => implode("\n\n", $texts), 'focus' => $focus];
    }

    /**
     * @param array<array{given:string,surname:string,birth_year:int|null,place:string}> $people
     *
     * @return array{text:string,focus:Individual|null}
     */
    private function relationship(Tree $tree, array $people, ?Individual $referred): array
    {
        $individuals = [];
        $texts       = [];

        // "How was he related to Mary Jones?" - the person referred to comes first.
        if ($referred instanceof Individual && count($people) < 2) {
            $individuals[] = $referred;
        }

        foreach ($people as $person) {
            [$text, $individual] = $this->resolve($tree, $person, false);

            if ($individual instanceof Individual && !in_array($individual, $individuals, true)) {
                $individuals[] = $individual;
            } else {
                $texts[] = $text;
            }
        }

        if (count($individuals) < 2) {
            $texts[] = 'Both people must be identified before their relationship can be described.';

            return ['text' => implode("\n\n", $texts), 'focus' => $individuals[0] ?? null];
        }

        [$first, $second] = $individuals;

        $name  = $this->text($this->relationships->getCloseRelationshipName($first, $second));
        $lines = [];

        if ($name === '') {
            $lines[] = 'No close family relationship was found between ' . $this->sheets->name($first) . ' and ' . $this->sheets->name($second) . '. (Only close relationships are checked here; they may still be distant relatives.)';
        } else {
            $lines[] = 'RELATIONSHIP: ' . $this->sheets->name($second) . ' is the ' . $name . ' of ' . $this->sheets->name($first) . '.';
        }

        $chart = ($this->relationship_url)($first, $second);
        if ($chart !== '') {
            $lines[] = 'Relationship chart showing how they are connected (works for distant relatives too): ' . $chart;
        }

        $lines[] = '';
        $lines[] = $this->sheets->summary($first);
        $lines[] = $this->sheets->summary($second);

        return ['text' => implode("\n", $lines), 'focus' => $first];
    }

    /**
     * @return array{text:string,focus:Individual|null}
     */
    private function surname(Tree $tree, string $surname): array
    {
        $individuals = $this->finder->findSurname($tree, $surname);
        $count       = count($individuals);

        if ($count === 0) {
            return ['text' => 'Nobody with the surname "' . $surname . '" was found in this family tree, or their records are private.', 'focus' => null];
        }

        $lines   = [];
        $lines[] = 'SURNAME: ' . $surname . ' — ' . ($this->finder->wasTruncated() ? 'at least ' : '') . $count . ' people found, oldest first.';

        // Where the family came from.
        $places = [];
        foreach ($individuals as $individual) {
            $place = $individual->getBirthPlace()->gedcomName();
            if ($place !== '') {
                $places[$place] = ($places[$place] ?? 0) + 1;
            }
        }

        if ($places !== []) {
            arsort($places);
            $lines[] = 'Most common birthplaces: ' . implode('; ', array_slice(array_keys($places), 0, 5));
        }

        $list = $this->finder->wasTruncated() ? '' : ($this->surname_url)($tree, $surname);
        if ($list !== '') {
            $lines[] = 'List of everyone with this surname: ' . $list;
        }

        $lines[] = '';
        foreach (array_slice($individuals, 0, self::MAX_SURNAME_LIST) as $individual) {
            $lines[] = '- ' . $this->sheets->brief($individual);
        }

        if ($count > self::MAX_SURNAME_LIST) {
            $lines[] = '- … and ' . ($count - self::MAX_SURNAME_LIST) . ' more.';
        }

        return ['text' => implode("\n", $lines), 'focus' => null];
    }

    /**
     * Find the individual a name refers to.
     *
     * @param array{given:string,surname:string,birth_year:int|null,place:string} $person
     *
     * @return array{0:string,1:Individual|null} information for the AI, and the individual if we are sure who it is
     */
    private function resolve(Tree $tree, array $person, bool $full = true): array
    {
        $label   = trim($person['given'] . ' ' . $person['surname']);
        $matches = $this->finder->find($tree, $person['given'], $person['surname'], $person['birth_year'], $person['place']);

        if ($matches === []) {
            return ['Nobody called "' . $label . '" was found in this family tree, or their record is private.', null];
        }

        $best   = $matches[0];
        $second = $matches[1] ?? null;

        if ($second === null || $best['score'] - $second['score'] >= self::CONFIDENT_MARGIN) {
            $text = $full ? $this->sheets->full($best['individual']) : $this->sheets->summary($best['individual']);

            $others = array_slice($matches, 1, 3);
            if ($full && $others !== []) {
                $text .= "\n\nOther people with similar names (in case the user meant one of them):";
                foreach ($others as $other) {
                    $text .= "\n- " . $this->sheets->brief($other['individual']);
                }
            }

            return [$text, $best['individual']];
        }

        $lines = ['Several people match "' . $label . '". Ask the user which one they mean:'];
        foreach (array_slice($matches, 0, self::MAX_CANDIDATES) as $match) {
            $lines[] = '- ' . $this->sheets->summary($match['individual']);
        }

        if (count($matches) > self::MAX_CANDIDATES) {
            $lines[] = '- … and ' . (count($matches) - self::MAX_CANDIDATES) . ' more. Suggest adding a birth year or place.';
        }

        return [implode("\n", $lines), null];
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
