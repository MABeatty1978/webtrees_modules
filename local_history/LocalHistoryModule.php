<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleHistoricEventsInterface;
use Fisharebest\Webtrees\Module\ModuleHistoricEventsTrait;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function array_map;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function json_encode;
use function ksort;
use function max;
use function mb_substr;
use function min;
use function preg_replace;
use function redirect;
use function response;
use function route;
use function strtolower;
use function trim;

/**
 * Add events from local history to the facts of the people connected to it.
 *
 * Events are organised into named groups — for example "Springfield" for the town's own
 * history, and "United States" for national events such as wars.  Each group has its own list
 * of places and its own audience (either everyone in the tree, or only people connected to one
 * of its places), and each event belongs to one group.  A website with only local history needs
 * only one group; a more detailed one can layer local, regional and national history, each
 * reaching the right people, without a county-wide event accidentally being shown to visitors
 * from the other side of the world.
 *
 * Settings are under Control panel -> Modules -> Historic events -> Local history.
 * Turn the module on or off in the same place.
 *
 * Written for webtrees 2.1 (PHP 7.4+), and compatible with later versions.
 */
class LocalHistoryModule extends AbstractModule implements ModuleConfigInterface, ModuleCustomInterface, ModuleHistoricEventsInterface
{
    use ModuleConfigTrait;
    use ModuleCustomTrait;
    use ModuleHistoricEventsTrait;

    public const BASIS_LIFESPAN = 'lifespan';
    public const BASIS_WINDOW   = 'window';

    private const MAX_MIN_AGE = 120;

    private const DEFAULTS = [
        'TITLE'          => 'Local history',
        'DESCRIPTION'    => 'Add events from local history to the facts of the people who lived there.',
        'GROUPS'         => '[]',
        'SRC_SPOUSE'     => '1',
        'SRC_PARENTS'    => '0',
        'PRESENCE_BASIS' => self::BASIS_LIFESPAN,
        'PRESENCE_TAGS'  => 'BIRT,CHR,BAPM,RESI,CENS,OCCU,MARR,EDUC,IMMI,EMIG,NATU',
        'MIN_AGE'        => '0',
        'EVENTS'         => '[]',
    ];

    /** @var array<string,PlaceMatcher>|null */
    private $matchers;

    public function title(): string
    {
        return $this->preference('TITLE');
    }

    public function description(): string
    {
        return $this->preference('DESCRIPTION');
    }

    public function customModuleAuthorName(): string
    {
        return 'Michael Beatty';
    }

    public function customModuleVersion(): string
    {
        return '2.0.0';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    /**
     * All events provided by this module, as GEDCOM.  Which of them any one person sees depends
     * on their group, computed in historicEventsForIndividual() below; webtrees itself only ever
     * calls that method, but the interface requires this one too.
     *
     * @return Collection<int,string>
     */
    public function historicEventsAll(string $language_tag = ''): Collection
    {
        $gedcom = [];

        foreach ($this->events() as $event) {
            if ($event['enabled']) {
                $gedcom[] = EventList::toGedcom($event);
            }
        }

        return new Collection($gedcom);
    }

    /**
     * Which events should we show for an individual?
     *
     * @return Collection<int,Fact>
     */
    public function historicEventsForIndividual(Individual $individual): Collection
    {
        $groups = $this->groups();

        if ($groups === []) {
            return new Collection();
        }

        $records = $this->connectedRecords($individual);
        $basis   = $this->preference('PRESENCE_BASIS');
        $tags    = PresenceWindow::parseTags($this->preference('PRESENCE_TAGS'));

        // Work out, once per group, whether this person is in its audience, and (if "time spent
        // at the place" is chosen) the window of time they are known to have been there.
        $connected      = [];
        $windows        = [];
        $groups_by_key  = [];

        foreach ($groups as $group) {
            $key                  = $group['key'];
            $groups_by_key[$key]  = $group;

            if ($group['scope'] === GroupList::SCOPE_EVERYONE) {
                $connected[$key] = true;

                continue;
            }

            $matcher         = $this->matcher($key, $group['places']);
            $connected[$key] = $this->isConnectedTo($records, $matcher);

            if ($connected[$key] && $basis === self::BASIS_WINDOW) {
                $windows[$key] = PresenceWindow::find($records, $matcher, $tags);
            }
        }

        if (!in_array(true, $connected, true)) {
            return new Collection();
        }

        $min_age  = (int) $this->preference('MIN_AGE');
        $birth    = $individual->getEstimatedBirthDate();
        $death    = $individual->getEstimatedDeathDate();
        $birth_jd = $birth->isOK() ? $birth->minimumJulianDay() : null;

        $facts = new Collection();

        foreach ($this->events() as $event) {
            if (!$event['enabled'] || empty($connected[$event['group']])) {
                continue;
            }

            $group = $groups_by_key[$event['group']];
            $fact  = new Fact(EventList::toGedcom($event), $individual, 'histo');
            $date  = $fact->date();

            if ($basis === self::BASIS_WINDOW && $group['scope'] !== GroupList::SCOPE_EVERYONE) {
                $window = $windows[$event['group']] ?? null;

                // No dated fact at one of this group's places counts as evidence of actually
                // being there (for example, the person is only recorded as buried there) — so
                // there is nothing to safely narrow down to.  Skip the event, rather than
                // guessing at their whole lifespan.
                if ($window === null || $date->maximumJulianDay() < $window[0] || $date->minimumJulianDay() > $window[1]) {
                    continue;
                }
            } else {
                if (Date::compare($date, $birth) < 0 || Date::compare($date, $death) > 0) {
                    continue;
                }
            }

            if ($min_age > 0 && $birth_jd !== null && $date->minimumJulianDay() - $birth_jd < $min_age * 365) {
                continue;
            }

            $facts->push($fact);
        }

        return $facts;
    }

    /**
     * The settings page.
     */
    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $settings = [];
        foreach (self::DEFAULTS as $name => $default) {
            $settings[$name] = $this->preference($name);
        }

        $groups = $this->groups();
        $events = $this->events();

        return $this->viewResponse($this->name() . '::settings', [
            'title'      => $this->title(),
            'settings'   => $settings,
            'groups'     => $groups,
            'events'     => $events,
            'orphaned'   => $this->orphanedEvents($events, $groups),
            'no_groups'  => $groups === [],
            'examples'   => $this->examples(),
            'ai_prompt'  => $this->aiPrompt($groups),
            'action'     => route('module', ['module' => $this->name(), 'action' => 'Admin']),
            'import'     => route('module', ['module' => $this->name(), 'action' => 'AdminImport']),
            'export'     => route('module', ['module' => $this->name(), 'action' => 'AdminExport']),
            'script'     => $this->assetUrl('js/settings.js'),
            'max_events' => EventList::MAX_EVENTS,
            'max_groups' => GroupList::MAX_GROUPS,
        ]);
    }

    /**
     * Save the settings, groups and events.
     */
    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $title = mb_substr(trim($body->string('TITLE', '')), 0, 60);
        $this->setPreference('TITLE', $title === '' ? self::DEFAULTS['TITLE'] : $title);
        $this->setPreference('DESCRIPTION', mb_substr(trim($body->string('DESCRIPTION', '')), 0, 200) ?: self::DEFAULTS['DESCRIPTION']);

        $this->setPreference('SRC_SPOUSE', $body->string('SRC_SPOUSE', '') === '1' ? '1' : '0');
        $this->setPreference('SRC_PARENTS', $body->string('SRC_PARENTS', '') === '1' ? '1' : '0');

        $basis = $body->string('PRESENCE_BASIS', self::BASIS_LIFESPAN);
        $this->setPreference('PRESENCE_BASIS', $basis === self::BASIS_WINDOW ? self::BASIS_WINDOW : self::BASIS_LIFESPAN);

        $this->setPreference('PRESENCE_TAGS', PresenceWindow::cleanTags($body->string('PRESENCE_TAGS', '')));

        $min_age = (int) $body->string('MIN_AGE', '0');
        $this->setPreference('MIN_AGE', (string) max(0, min(self::MAX_MIN_AGE, $min_age)));

        [$groups, $group_errors] = GroupList::clean($body->array('groups'));
        $this->saveGroups($groups);

        foreach ($group_errors as $error) {
            FlashMessages::addMessage($error, 'danger');
        }

        [$events, $event_errors] = EventList::clean($body->array('events'));
        $this->saveEvents($events);

        foreach ($event_errors as $error) {
            FlashMessages::addMessage($error, 'danger');
        }

        $orphaned = $this->orphanedEvents($events, $groups);

        if ($orphaned !== []) {
            $titles = implode(', ', array_map(static function (array $event): string {
                return $event['title'];
            }, $orphaned));

            FlashMessages::addMessage('Nobody will see these events, because they are assigned to a group that no longer exists: ' . $titles . '. Re-assign them to a group, below, or delete them.', 'warning');
        }

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Add groups and events from an example file, or from pasted text.
     */
    public function postAdminImportAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $file = $body->string('example', '');
        $json = $file === '' ? self::stripCodeFence($body->string('json', '')) : $this->exampleJson($file);
        $pack = $json === null ? null : EventList::fromJson($json);

        if ($pack === null) {
            FlashMessages::addMessage('That is not a file of events this module can read. Use a file made by “Export”, or one of the examples.', 'danger');

            return redirect($this->getConfigLink());
        }

        foreach ($pack['errors'] as $error) {
            FlashMessages::addMessage($error, 'warning');
        }

        $replace = $body->string('mode', 'add') === 'replace';

        $groups       = $replace ? [] : $this->groups();
        $group_keys   = GroupList::keys($groups);
        $groups_added = 0;
        $kept_local   = [];

        foreach ($pack['groups'] as $group) {
            if (in_array($group['key'], $group_keys, true)) {
                $kept_local[] = $group['label'];

                continue;
            }

            if (count($groups) >= GroupList::MAX_GROUPS) {
                continue;
            }

            $groups[]     = $group;
            $group_keys[] = $group['key'];
            $groups_added++;
        }

        $this->saveGroups($groups);

        $existing_events        = $replace ? [] : $this->events();
        [$events, $events_added] = EventList::merge($existing_events, $pack['events']);
        $this->saveEvents($events);

        $message = $events_added === 1 ? 'Added 1 event.' : 'Added ' . $events_added . ' events.';

        if ($groups_added > 0) {
            $message .= ' ' . ($groups_added === 1 ? 'Added 1 new group.' : 'Added ' . $groups_added . ' new groups.');
        }

        if ($kept_local !== []) {
            $message .= ' These groups already existed, and were left as they are: ' . implode(', ', array_unique($kept_local)) . '.';
        }

        FlashMessages::addMessage($message, 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Download the groups and events as a file, to back them up or share them.
     */
    public function getAdminExportAction(ServerRequestInterface $request): ResponseInterface
    {
        $data = [
            'name'        => $this->title(),
            'description' => $this->description(),
            'groups'      => $this->groups(),
            'events'      => $this->events(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->title())), '-') ?: 'local-history';

        return response((string) $json)
            ->withHeader('content-type', 'application/json; charset=UTF-8')
            ->withHeader('content-disposition', 'attachment; filename="' . $name . '.json"');
    }

    /**
     * AI assistants asked for JSON sometimes wrap it in a Markdown code fence even when told not
     * to.  Strip one off, so pasting their answer straight in still works.
     */
    private static function stripCodeFence(string $text): string
    {
        return trim((string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', trim($text)));
    }

    /**
     * A prompt someone can paste into an AI assistant, to research local history and write it
     * back out in the exact format the "Import" box below accepts.  If the website owner has
     * already set up a group, its name is used as a starting example, so the prompt is useful
     * to copy and adjust rather than needing to be built from nothing.
     *
     * @param array<array<string,mixed>> $groups
     */
    private function aiPrompt(array $groups): string
    {
        $place = $groups[0]['label'] ?? 'Springfield, Clark County, Ohio';

        return <<<PROMPT
            I'm researching local history to add to a family history website, using a tool that reads a
            specific JSON format. Please research real, verifiable events from the history of:

            {$place}

            Covering approximately: [a time period, e.g. 1800 to 1950]

            Write about 15–25 events: things like when the place was founded, named or incorporated; a
            railway, road or other transport link arriving; a major fire, flood, storm or other disaster;
            the main local industry starting, booming or closing; a notable building, school or church
            opening; a well-known local event or visitor; and local effects of national events (wars,
            Prohibition, the Depression) — but only where there is a genuinely local detail to add, not just
            "the country was at war". Avoid vague or undated events.

            Reply with ONLY a JSON file in exactly this format, and nothing else before or after it:

            {
              "name": "A short name for this set of events",
              "description": "One or two sentences describing it",
              "groups": [
                {
                  "label": "A short name for this place",
                  "places": "One place name per line, as it might appear in a family tree, e.g.:\\nSpringfield, Clark, Ohio, USA\\nSpringfield, Ohio",
                  "scope": "connected"
                }
              ],
              "events": [
                {
                  "date": "a GEDCOM date — see the formats below",
                  "title": "A short title, e.g. The railway arrives",
                  "text": "One or two sentences describing what happened.",
                  "group": "the label of one of the groups above",
                  "note": "optional — start with \\"Check:\\" if you are not fully confident about this date or detail"
                }
              ]
            }

            Dates must use these exact GEDCOM forms:
            - "1850" for a year
            - "ABT 1850" for about a year
            - "19 SEP 1864" for an exact day (month abbreviations: JAN FEB MAR APR MAY JUN JUL AUG SEP OCT NOV DEC)
            - "FROM 1861 TO 1865" for something that lasted several years
            - "BEF 1940" or "AFT 1940" for before or after a date
            - "BET 1880 AND 1890" for sometime within a range

            Rules:
            - Every event needs a real date and a source you are reasonably confident in. Do not invent events,
              names or dates.
            - If you are not fully sure of a date or a detail, still include the event, but say so in its
              "note" field, starting with "Check:", rather than silently guessing.
            - If some events are specific to the place above, and others are really about a wider region, state
              or country that happens to include it (a war, a national depression, a widely-felt storm), put
              the wider ones in a second group, with "scope": "everyone" and "places": "", so they are not
              limited to people connected to the local place. Otherwise, one group is enough.
            - Keep each event's "text" to one or two plain-English sentences.
            PROMPT;
    }

    /**
     * The saved groups.
     *
     * @return array<array<string,mixed>>
     */
    private function groups(): array
    {
        return GroupList::decode($this->preference('GROUPS'));
    }

    /**
     * @param array<array<string,mixed>> $groups
     */
    private function saveGroups(array $groups): void
    {
        $this->setPreference('GROUPS', (string) json_encode(array_values($groups), JSON_UNESCAPED_UNICODE));
        $this->matchers = null;
    }

    /**
     * The saved events, in date order.
     *
     * @return array<array<string,mixed>>
     */
    private function events(): array
    {
        return EventList::decode($this->preference('EVENTS'));
    }

    /**
     * @param array<array<string,mixed>> $events
     */
    private function saveEvents(array $events): void
    {
        $this->setPreference('EVENTS', (string) json_encode(array_values($events), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Enabled events whose group no longer exists.  Nobody sees these; the settings page warns
     * about them rather than silently hiding the problem.
     *
     * @param array<array<string,mixed>> $events
     * @param array<array<string,mixed>> $groups
     *
     * @return array<array<string,mixed>>
     */
    private function orphanedEvents(array $events, array $groups): array
    {
        $keys     = GroupList::keys($groups);
        $orphaned = [];

        foreach ($events as $event) {
            if ($event['enabled'] && !in_array($event['group'], $keys, true)) {
                $orphaned[] = $event;
            }
        }

        return $orphaned;
    }

    /**
     * The example files in the examples folder.
     *
     * @return array<string,array{name:string,description:string,events:int,groups:int}> indexed by file name
     */
    private function examples(): array
    {
        $examples = [];

        foreach (glob(__DIR__ . '/examples/*.json') ?: [] as $path) {
            $pack = EventList::fromJson((string) file_get_contents($path));

            if ($pack !== null) {
                $file            = basename($path);
                $examples[$file] = [
                    'name'        => $pack['name'] ?: $file,
                    'description' => $pack['description'],
                    'events'      => count($pack['events']),
                    'groups'      => count($pack['groups']),
                ];
            }
        }

        ksort($examples);

        return $examples;
    }

    /**
     * Only files in the examples folder can be loaded this way.
     */
    private function exampleJson(string $file): ?string
    {
        if (!isset($this->examples()[$file])) {
            return null;
        }

        $json = file_get_contents(__DIR__ . '/examples/' . basename($file));

        return $json === false ? null : $json;
    }

    /**
     * Is this person, or a family record also being looked at, somewhere on the list of places?
     * Only facts the visitor is allowed to see are used.
     *
     * @param array<GedcomRecord> $records
     */
    private function isConnectedTo(array $records, PlaceMatcher $matcher): bool
    {
        if ($matcher->isEmpty()) {
            return false;
        }

        foreach ($records as $record) {
            foreach ($record->facts() as $fact) {
                if ($matcher->matches($fact->place()->gedcomName())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The person's own record, plus any families the "Also look at" settings say to include.
     *
     * @return array<GedcomRecord>
     */
    private function connectedRecords(Individual $individual): array
    {
        $records = [$individual];

        if ($this->preference('SRC_SPOUSE') === '1') {
            foreach ($individual->spouseFamilies() as $family) {
                $records[] = $family;
            }
        }

        if ($this->preference('SRC_PARENTS') === '1') {
            foreach ($individual->childFamilies() as $family) {
                $records[] = $family;
            }
        }

        return $records;
    }

    private function matcher(string $key, string $places): PlaceMatcher
    {
        if ($this->matchers === null) {
            $this->matchers = [];
        }

        if (!isset($this->matchers[$key])) {
            $this->matchers[$key] = new PlaceMatcher($places);
        }

        return $this->matchers[$key];
    }

    private function preference(string $name): string
    {
        try {
            return $this->getPreference($name, self::DEFAULTS[$name]);
        } catch (Throwable $exception) {
            // The title is needed to list the modules, even when the database is not ready (e.g. during an upgrade).
            return self::DEFAULTS[$name];
        }
    }
}
