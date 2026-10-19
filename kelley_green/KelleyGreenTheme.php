<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesThemes\KelleyGreen;

use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\ModuleThemeTrait;

use function asset;

/**
 * A modern, Kelley green theme.
 *
 * All behaviour (menus, chart boxes, etc.) comes from the core ModuleThemeTrait,
 * and all base styling comes from the core "minimal" theme, which is built on
 * Bootstrap CSS variables.  This theme only adds a stylesheet on top, so it keeps
 * working (and keeps receiving core fixes) when webtrees is upgraded.
 */
class KelleyGreenTheme extends AbstractModule implements ModuleCustomInterface, ModuleThemeInterface
{
    use ModuleCustomTrait;
    use ModuleThemeTrait;

    // Set to false to use system fonts only (no requests to Google Fonts).
    // (Untyped constants, so the module also loads on PHP versions before 8.3.)
    private const USE_WEB_FONTS = true;

    private const WEB_FONTS_URL = 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap';

    public function title(): string
    {
        return 'Kelley Green';
    }

    public function description(): string
    {
        return 'A modern, Kelley green theme, featuring Kelleys Island, Ohio.';
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

    /**
     * A list of CSS files to include for this page.
     *
     * @return array<string>
     */
    public function stylesheets(): array
    {
        $stylesheets = [
            asset('css/minimal.min.css'),
            $this->assetUrl('css/kelley-green.css'),
        ];

        if (self::USE_WEB_FONTS) {
            $stylesheets[] = self::WEB_FONTS_URL;
        }

        return $stylesheets;
    }
}
