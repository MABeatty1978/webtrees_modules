<?php

/**
 * Local history: adds events from the history of a place to the facts of the people who lived there.
 *
 * Install by copying this folder to modules_v4/.  Remove the folder to uninstall.
 */

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\LocalHistory;

require_once __DIR__ . '/src/EventList.php';
require_once __DIR__ . '/src/PlaceMatcher.php';
require_once __DIR__ . '/src/PresenceWindow.php';
require_once __DIR__ . '/LocalHistoryModule.php';

return new LocalHistoryModule();
