<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\AdSense;

use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleAnalyticsInterface;
use Fisharebest\Webtrees\Module\ModuleAnalyticsTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleExternalUrlInterface;
use Fisharebest\Webtrees\Module\ModuleExternalUrlTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\View;

use function preg_match;
use function view;

/**
 * Add Google AdSense to the <head> of every page.
 *
 * It is listed under Control panel -> Modules -> Tracking and analytics, where
 * the publisher ID can be changed.  It is independent of the theme, so ads keep
 * working if the theme is changed.  webtrees does not add custom modules'
 * head content to the control panel, so ads never appear there.
 *
 * Written for webtrees 2.1 (PHP 7.4+), and compatible with later versions.
 */
class AdSenseModule extends AbstractModule implements ModuleAnalyticsInterface, ModuleConfigInterface, ModuleCustomInterface, ModuleExternalUrlInterface, ModuleGlobalInterface
{
    use ModuleAnalyticsTrait;
    use ModuleConfigTrait;
    use ModuleCustomTrait;
    use ModuleExternalUrlTrait;
    use ModuleGlobalTrait;

    // No ads are shown until a publisher ID is saved on the settings page.
    private const DEFAULT_CLIENT_ID = '';

    private const CLIENT_ID_PATTERN = '/^ca-pub-[0-9]{10,20}$/';

    public function title(): string
    {
        return 'Google™ AdSense';
    }

    public function description(): string
    {
        return 'Show Google AdSense advertisements.';
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
     * Home page for the service.
     */
    public function externalUrl(): string
    {
        return 'https://adsense.google.com';
    }

    /**
     * The parameters that need to be embedded in the snippet.
     *
     * @return array<string>
     */
    public function analyticsParameters(): array
    {
        return [
            'ADSENSE_CLIENT_ID' => $this->getPreference('ADSENSE_CLIENT_ID', self::DEFAULT_CLIENT_ID),
        ];
    }

    /**
     * Form fields to edit the parameters.
     */
    public function analyticsFormFields(): string
    {
        return view($this->name() . '::form', $this->analyticsParameters());
    }

    /**
     * Embed placeholders in the snippet.
     *
     * @param array<string> $parameters
     */
    public function analyticsSnippet(array $parameters): string
    {
        return view($this->name() . '::snippet', $parameters);
    }

    /**
     * Unlike the core analytics modules, ignore the browser's "Do Not Track"
     * header (which Google does not support, and browsers have deprecated),
     * as that would hide ads from those visitors.  Only check for a valid ID.
     */
    public function analyticsCanShow(): bool
    {
        return preg_match(self::CLIENT_ID_PATTERN, $this->analyticsParameters()['ADSENSE_CLIENT_ID']) === 1;
    }

    /**
     * Raw content, to be added at the end of the <head> element.
     */
    public function headContent(): string
    {
        if ($this->analyticsCanShow()) {
            return $this->analyticsSnippet($this->analyticsParameters());
        }

        return '';
    }
}
