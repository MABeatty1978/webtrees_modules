<?php

/**
 * Family tree chatbot: an AI assistant (Google Gemini) that answers visitors'
 * questions about the people in the tree, in a panel on every page.
 *
 * Install by copying this folder to modules_v4/.  Remove the folder to uninstall.
 */

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

require_once __DIR__ . '/src/ChatbotException.php';
require_once __DIR__ . '/src/ChatLogger.php';
require_once __DIR__ . '/src/ContextBuilder.php';
require_once __DIR__ . '/src/FactSheetBuilder.php';
require_once __DIR__ . '/src/GeminiClient.php';
require_once __DIR__ . '/src/PersonFinder.php';
require_once __DIR__ . '/src/QueryPlanner.php';
require_once __DIR__ . '/src/UsageTracker.php';
require_once __DIR__ . '/ChatbotModule.php';

return new ChatbotModule();
