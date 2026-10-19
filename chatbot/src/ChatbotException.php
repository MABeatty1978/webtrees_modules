<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use RuntimeException;

/**
 * A problem that stops a question being answered.  The message is safe to show
 * to the visitor, and the code is the HTTP status to send.
 */
class ChatbotException extends RuntimeException
{
}
