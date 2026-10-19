<?php

/**
 * Google AdSense: adds the AdSense script to the <head> of every public page.
 *
 * Install by copying this folder to modules_v4/.  Remove the folder to uninstall.
 */

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\AdSense;

require_once __DIR__ . '/AdSenseModule.php';

return new AdSenseModule();
