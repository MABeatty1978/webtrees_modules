<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Place;

use function array_filter;
use function html_entity_decode;
use function implode;
use function in_array;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function strip_tags;
use function strpos;
use function strrchr;
use function substr;
use function trim;

/**
 * Describe individuals in short, plain English for the AI.
 *
 * Raw GEDCOM makes the AI decode tags and match record IDs to work out who is
 * whose parent, which costs tokens and causes mistakes.  Here, webtrees does
 * that work, and its privacy rules decide which people and facts are included.
 */
class FactSheetBuilder
{
    // Tags that are links to other records, or of no interest to the reader.
    private const SKIP_TAGS = [
        'NAME', 'SEX', 'FAMC', 'FAMS', 'HUSB', 'WIFE', 'CHIL', 'OBJE', 'SOUR', 'CHAN', 'RIN', 'RFN', 'AFN', 'REFN',
        'RESN', 'SUBM', 'ASSO', '_ASSO', '_UID', '_TODO', '_WT_OBJE_SORT', 'ALIA',
    ];

    private const MAX_VALUE_LENGTH = 400;

    /**
     * Everything we know about one individual, and their close family.
     */
    public function full(Individual $individual): string
    {
        $lines   = [];
        $lines[] = 'PERSON: ' . $this->nameAndYears($individual);
        $lines[] = 'Link: ' . $individual->url();
        $lines[] = 'Sex: ' . $this->sex($individual);

        $other_names = $this->otherNames($individual);
        if ($other_names !== '') {
            $lines[] = 'Also known as: ' . $other_names;
        }

        foreach ($individual->facts([], true) as $fact) {
            $line = $this->fact($fact);
            if ($line !== '') {
                $lines[] = '- ' . $line;
            }
        }

        foreach ($individual->childFamilies() as $family) {
            $lines[] = '';
            $lines[] = 'Parents' . $this->pedigree($individual, $family) . ':';
            foreach ($family->spouses() as $parent) {
                $lines[] = '- ' . $this->role($parent, 'Father', 'Mother', 'Parent') . ': ' . $this->relative($parent);
            }

            $siblings = $family->children()->filter(static fn (Individual $child): bool => $child !== $individual);
            if ($siblings->isNotEmpty()) {
                $lines[] = 'Siblings:';
                foreach ($siblings as $sibling) {
                    $lines[] = '- ' . $this->role($sibling, 'Brother', 'Sister', 'Sibling') . ': ' . $this->relative($sibling);
                }
            }
        }

        foreach ($individual->spouseFamilies() as $family) {
            $spouse  = $family->spouse($individual);
            $lines[] = '';
            $lines[] = $spouse instanceof Individual
                ? $this->role($spouse, 'Husband', 'Wife', 'Spouse') . ': ' . $this->relative($spouse)
                : 'Family with an unknown partner:';

            foreach ($family->facts([], true) as $fact) {
                $line = $this->fact($fact);
                if ($line !== '') {
                    $lines[] = '- ' . $line;
                }
            }

            foreach ($family->children() as $child) {
                $lines[] = '- ' . $this->role($child, 'Son', 'Daughter', 'Child') . ': ' . $this->relative($child);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Enough to tell people with the same name apart.
     */
    public function summary(Individual $individual): string
    {
        $parts = [$this->nameAndYears($individual)];

        foreach (['born' => [$individual->getBirthDate(), $individual->getBirthPlace()], 'died' => [$individual->getDeathDate(), $individual->getDeathPlace()]] as $label => [$date, $place]) {
            $when_where = $this->dateAndPlace($date, $place);
            if ($when_where !== '') {
                $parts[] = $label . ' ' . $when_where;
            }
        }

        $parents = [];
        foreach ($individual->childFamilies() as $family) {
            foreach ($family->spouses() as $parent) {
                $parents[] = $this->name($parent);
            }
        }

        if ($parents !== []) {
            $parts[] = 'child of ' . implode(' and ', $parents);
        }

        return implode('; ', $parts) . ' — Link: ' . $individual->url();
    }

    /**
     * One line for a list of people.
     */
    public function brief(Individual $individual): string
    {
        return $this->nameAndYears($individual) . ' — Link: ' . $individual->url();
    }

    public function name(Individual $individual): string
    {
        return $this->text($individual->fullName());
    }

    public function nameAndYears(Individual $individual): string
    {
        $birth_date = $individual->getBirthDate();
        $death_date = $individual->getDeathDate();
        $birth      = $birth_date->isOK() ? (string) $birth_date->minimumDate()->year() : '';
        $death      = $death_date->isOK() ? (string) $death_date->maximumDate()->year() : '';

        if ($birth === '' && $death === '') {
            return $this->name($individual);
        }

        return $this->name($individual) . ' (' . $birth . '–' . $death . ')';
    }

    /**
     * A relative may be visible, have only their name visible, or be completely private.
     */
    private function relative(Individual $individual): string
    {
        if ($individual->canShow()) {
            return $this->nameAndYears($individual) . ' — Link: ' . $individual->url();
        }

        if ($individual->canShowName()) {
            return $this->name($individual) . ' (other details are private)';
        }

        return 'a private individual';
    }

    private function fact(Fact $fact): string
    {
        $tag = substr((string) strrchr(':' . $fact->tag(), ':'), 1);

        if (in_array($tag, self::SKIP_TAGS, true) || strpos($tag, '_WT') === 0 || strpos($tag, '_FS') === 0) {
            return '';
        }

        $value = trim($fact->value());

        // "1 BIRT Y" just means it happened.  "1 NOTE @N1@" is a link to a shared note.
        if ($value === 'Y' || preg_match('/^@[^@]+@$/', $value)) {
            $value = '';
        }

        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        $label = $this->text($fact->label());
        $value = $this->text($value);
        $type  = $this->text($fact->attribute('TYPE'));
        $when  = $this->dateAndPlace($fact->date(), $fact->place());

        // Custom facts and events use their TYPE as the label.
        if ($type === $label || $type === $value) {
            $type = '';
        }

        $details = array_filter([$value, $type, $when], static fn (string $x): bool => $x !== '');

        if ($details === []) {
            return '';
        }

        return $label . ': ' . implode(', ', $details);
    }

    private function dateAndPlace(Date $date, Place $place): string
    {
        $date_text  = $date->isOK() ? $this->text($date->display()) : '';
        $place_text = $place->gedcomName();

        if ($date_text !== '' && $place_text !== '') {
            return $date_text . ' in ' . $place_text;
        }

        return $date_text . $place_text;
    }

    private function otherNames(Individual $individual): string
    {
        $primary = $this->name($individual);
        $names   = [];

        foreach ($individual->getAllNames() as $name) {
            $full = $this->text($name['full'] ?? '');
            if ($full !== '' && $full !== $primary && !in_array($full, $names, true)) {
                $names[] = $full;
            }
        }

        return implode('; ', $names);
    }

    /**
     * Mark adoptive, foster and other non-birth families.
     */
    private function pedigree(Individual $individual, Family $family): string
    {
        foreach ($individual->facts(['FAMC']) as $fact) {
            if ($fact->value() === '@' . $family->xref() . '@') {
                $pedigree = $fact->attribute('PEDI');

                return $pedigree === '' || $pedigree === 'birth' ? '' : ' (' . $pedigree . ')';
            }
        }

        return '';
    }

    private function role(Individual $individual, string $male, string $female, string $unknown): string
    {
        if (!$individual->canShow()) {
            return $unknown;
        }

        switch ($individual->sex()) {
            case 'M':
                return $male;
            case 'F':
                return $female;
            default:
                return $unknown;
        }
    }

    private function sex(Individual $individual): string
    {
        switch ($individual->sex()) {
            case 'M':
                return 'male';
            case 'F':
                return 'female';
            default:
                return 'unknown';
        }
    }

    /**
     * webtrees returns names, dates and labels as HTML.
     */
    private function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove the invisible direction marks that webtrees adds around dates and names.
        $text = preg_replace('/[\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', $text) ?? $text;

        return trim($text);
    }
}
