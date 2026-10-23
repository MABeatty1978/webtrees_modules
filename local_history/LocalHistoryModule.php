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

use function array_keys;
use function array_map;
use function array_values;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function json_encode;
use function ksort;
use function max;
use function mb_substr;
use function min;
use function preg_replace;
use function redirect;
use function response;
use function route;
use function sprintf;
use function trim;
use function view;

/**
 * Add events from the history of a place to the facts of the people who lived there.
 *
 * For example, a website about a family from a small town can show when the railway came,
 * when the mill burned down, or when the town got electricity, on the page of everyone who
 * lived there at the time.  The website owner chooses which places count, which people see
 * which events, and writes the list of events, on the module's settings page.
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

    public const SCOPE_CONNECTED = 'connected';
    public const SCOPE_EVERYONE  = 'everyone';

    public const BASIS_LIFESPAN = 'lifespan';
    public const BASIS_WINDOW   = 'window';

    private const MAX_MIN_AGE = 120;

    private const DEFAULTS = [
        'TITLE'          => 'Local history',
        'DESCRIPTION'    => 'Add events from local history to the facts of the people who lived there.',
        'PLACES'         => '',
        'SCOPE'          => self::SCOPE_CONNECTED,
        'SRC_SPOUSE'     => '1',
        'SRC_PARENTS'    => '0',
        'PRESENCE_BASIS' => self::BASIS_LIFESPAN,
        'PRESENCE_TAGS'  => 'BIRT,CHR,BAPM,RESI,CENS,OCCU,MARR,EDUC,IMMI,EMIG,NATU',
        'MIN_AGE'        => '0',
        'EVENTS'         => '[]',
    ];

    /** @var PlaceMatcher|null */
    private $matcher;

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
        return '1.0.0';
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
     * All events provided by this module, as GEDCOM.
     *
     * Later versions of webtrees pass the language, which does not matter here, as the events
     * are written by the website owner, in whichever language they like.  It is optional, so
     * this works with webtrees 2.1 as well.
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
        if ($this->preference('SCOPE') !== self::SCOPE_EVERYONE && !$this->isConnected($individual)) {
            return new Collection();
        }

        if ($this->preference('PRESENCE_BASIS') === self::BASIS_WINDOW) {
            $window = $this->presenceWindow($individual);

            // There is no dated fact at a matching place that counts as evidence of actually
            // being there (for example, the person is only recorded as buried there) — so there
            // is nothing to safely narrow down to.  Show no events, rather than guessing at their
            // whole lifespan, which is exactly the over-eager behaviour this mode exists to avoid.
            if ($window === null) {
                return new Collection();
            }

            [$min_jd, $max_jd] = $window;

            $facts = $this->mappedFacts($individual)
                ->filter(static function (Fact $fact) use ($min_jd, $max_jd): bool {
                    $date = $fact->date();

                    return $date->maximumJulianDay() >= $min_jd && $date->minimumJulianDay() <= $max_jd;
                });
        } else {
            // The whole-lifespan rule this module has always used: an event is shown if it
            // happened while the person was alive, regardless of where in the world they were.
            $min_date = $individual->getEstimatedBirthDate();
            $max_date = $individual->getEstimatedDeathDate();

            $facts = $this->mappedFacts($individual)
                ->filter(static function (Fact $fact) use ($min_date, $max_date): bool {
                    return Date::compare($fact->date(), $min_date) >= 0 && Date::compare($fact->date(), $max_date) <= 0;
                });
        }

        return $this->filterByMinimumAge($facts, $individual);
    }

    /**
     * @return Collection<int,Fact>
     */
    private function mappedFacts(Individual $individual): Collection
    {
        return $this->historicEventsAll()
            ->map(static function (string $gedcom) use ($individual): Fact {
                return new Fact($gedcom, $individual, 'histo');
            });
    }

    /**
     * Optionally require the person to have reached a minimum age when the event happened — so
     * an event is not shown merely because an infant was technically alive for its last few days.
     * Applies whichever "Base events on" option is chosen.
     *
     * @param Collection<int,Fact> $facts
     *
     * @return Collection<int,Fact>
     */
    private function filterByMinimumAge(Collection $facts, Individual $individual): Collection
    {
        $min_age = (int) $this->preference('MIN_AGE');

        if ($min_age === 0) {
            return $facts;
        }

        $birth_date = $individual->getEstimatedBirthDate();

        if (!$birth_date->isOK()) {
            return $facts;
        }

        $birth_jd = $birth_date->minimumJulianDay();
        $min_days = $min_age * 365;

        return $facts->filter(static function (Fact $fact) use ($birth_jd, $min_days): bool {
            return $fact->date()->minimumJulianDay() - $birth_jd >= $min_days;
        });
    }

    /**
     * The span of time the person is known to have been at one of the listed places, using only
     * their own dated facts there (and, if chosen, the facts of their families) that have one of
     * the "Facts that count" tags.  Returns null when there is no such evidence — for example, a
     * person connected to the place only by an undated fact, or only by their burial there.
     *
     * @return array{0:int,1:int}|null
     */
    private function presenceWindow(Individual $individual): ?array
    {
        return PresenceWindow::find(
            $this->connectedRecords($individual),
            $this->matcher(),
            PresenceWindow::parseTags($this->preference('PRESENCE_TAGS'))
        );
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

        return $this->viewResponse($this->name() . '::settings', [
            'title'       => $this->title(),
            'settings'    => $settings,
            'events'      => $this->events(),
            'examples'    => $this->examples(),
            'no_places'   => $settings['SCOPE'] !== self::SCOPE_EVERYONE && $this->matcher()->isEmpty(),
            'action'      => route('module', ['module' => $this->name(), 'action' => 'Admin']),
            'import'      => route('module', ['module' => $this->name(), 'action' => 'AdminImport']),
            'export'      => route('module', ['module' => $this->name(), 'action' => 'AdminExport']),
            'script'      => $this->assetUrl('js/settings.js'),
            'max_events'  => EventList::MAX_EVENTS,
        ]);
    }

    /**
     * Save the settings and events.
     */
    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $title = mb_substr(trim($body->string('TITLE', '')), 0, 60);
        $this->setPreference('TITLE', $title === '' ? self::DEFAULTS['TITLE'] : $title);
        $this->setPreference('DESCRIPTION', mb_substr(trim($body->string('DESCRIPTION', '')), 0, 200) ?: self::DEFAULTS['DESCRIPTION']);

        $scope = $body->string('SCOPE', self::SCOPE_CONNECTED);
        $this->setPreference('SCOPE', $scope === self::SCOPE_EVERYONE ? self::SCOPE_EVERYONE : self::SCOPE_CONNECTED);
        $this->setPreference('SRC_SPOUSE', $body->string('SRC_SPOUSE', '') === '1' ? '1' : '0');
        $this->setPreference('SRC_PARENTS', $body->string('SRC_PARENTS', '') === '1' ? '1' : '0');

        $basis = $body->string('PRESENCE_BASIS', self::BASIS_LIFESPAN);
        $this->setPreference('PRESENCE_BASIS', $basis === self::BASIS_WINDOW ? self::BASIS_WINDOW : self::BASIS_LIFESPAN);

        $tags = PresenceWindow::cleanTags($body->string('PRESENCE_TAGS', ''));
        $this->setPreference('PRESENCE_TAGS', $tags);

        $min_age = (int) $body->string('MIN_AGE', '0');
        $this->setPreference('MIN_AGE', (string) max(0, min(self::MAX_MIN_AGE, $min_age)));

        [$places, $dropped] = EventList::cleanPlaces($body->string('PLACES', ''));
        $this->setPreference('PLACES', $places);

        foreach ($dropped as $line) {
            FlashMessages::addMessage('The place pattern “' . $line . '” is not a valid regular expression, so it was not saved.', 'danger');
        }

        [$events, $errors] = EventList::clean($body->array('events'));
        $this->saveEvents($events);

        foreach ($errors as $error) {
            FlashMessages::addMessage($error, 'danger');
        }

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Add events from an example file, or from pasted text.
     */
    public function postAdminImportAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Validator::parsedBody($request);

        $file = $body->string('example', '');
        $json = $file === '' ? $body->string('json', '') : $this->exampleJson($file);
        $pack = $json === null ? null : EventList::fromJson($json);

        if ($pack === null) {
            FlashMessages::addMessage('That is not a file of events this module can read. Use a file made by “Export”, or one of the examples.', 'danger');

            return redirect($this->getConfigLink());
        }

        foreach ($pack['errors'] as $error) {
            FlashMessages::addMessage($error, 'warning');
        }

        if ($body->string('mode', 'add') === 'replace') {
            $existing = [];
        } else {
            $existing = $this->events();
        }

        [$events, $added] = EventList::merge($existing, $pack['events']);
        $this->saveEvents($events);

        $message = $added === 1 ? 'Added 1 event.' : 'Added ' . $added . ' events.';

        // Packs can say which places they are about.  Add them to the list, unless they are already there.
        if ($body->string('add_places', '') === '1' && $pack['places'] !== []) {
            [$places] = EventList::cleanPlaces(trim($this->preference('PLACES') . "\n" . implode("\n", $pack['places'])));
            $this->setPreference('PLACES', $places);
            $message .= ' The places it is about were added to the list of places.';
        }

        FlashMessages::addMessage($message, 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Download the places and events as a file, to back them up or share them.
     */
    public function getAdminExportAction(ServerRequestInterface $request): ResponseInterface
    {
        $data = [
            'name'        => $this->title(),
            'description' => $this->description(),
            'places'      => PlaceMatcher::lines($this->preference('PLACES')),
            'events'      => $this->events(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->title())), '-') ?: 'local-history';

        return response((string) $json)
            ->withHeader('content-type', 'application/json; charset=UTF-8')
            ->withHeader('content-disposition', 'attachment; filename="' . $name . '.json"');
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
     * The example files in the examples folder.
     *
     * @return array<string,array{name:string,description:string,events:int,places:int}> indexed by file name
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
                    'places'      => count($pack['places']),
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
        if (!in_array($file, array_map('strval', array_keys($this->examples())), true)) {
            return null;
        }

        $json = file_get_contents(__DIR__ . '/examples/' . basename($file));

        return $json === false ? null : $json;
    }

    /**
     * Has this person, or a family they belong to, a place from the list?
     * Only facts the visitor is allowed to see are used.
     */
    private function isConnected(Individual $individual): bool
    {
        if ($this->matcher()->isEmpty()) {
            return false;
        }

        foreach ($this->connectedRecords($individual) as $record) {
            if ($this->hasMatchingPlace($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The person's own record, plus any families the "Also look at" settings say to include.
     * Used both to decide whether someone is connected to a place, and, if "time spent at the
     * place" is chosen, which facts may narrow down when.
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

    private function hasMatchingPlace(GedcomRecord $record): bool
    {
        foreach ($record->facts() as $fact) {
            if ($this->matcher()->matches($fact->place()->gedcomName())) {
                return true;
            }
        }

        return false;
    }

    private function matcher(): PlaceMatcher
    {
        if ($this->matcher === null) {
            $this->matcher = new PlaceMatcher($this->preference('PLACES'));
        }

        return $this->matcher;
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
