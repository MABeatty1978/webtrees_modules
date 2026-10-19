<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use DateTimeImmutable;

use function basename;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function glob;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function trim;
use function unlink;

/**
 * Keep a log of questions and answers, one file per month.
 *
 * The log is kept in the webtrees data folder, which webtrees already
 * protects from being read over the web.
 */
class ChatLogger
{
    private string $folder;

    private int $keep_days;

    public function __construct(string $folder, int $keep_days)
    {
        $this->folder    = $folder;
        $this->keep_days = $keep_days;
    }

    /**
     * @param array<string,string> $details e.g. ['Tree' => 'smith', 'User' => 'visitor']
     */
    public function log(DateTimeImmutable $now, array $details, string $question, string $answer): void
    {
        if (!is_dir($this->folder)) {
            @mkdir($this->folder, 0755, true);
        }

        $header = [$now->format('Y-m-d H:i:s T')];
        foreach ($details as $label => $value) {
            $header[] = $label . ': ' . $value;
        }

        $entry = "=================================================\n" .
            implode(' | ', $header) . "\n" .
            'Q: ' . trim($question) . "\n" .
            'A: ' . trim($answer) . "\n\n";

        @file_put_contents($this->folder . 'chat-' . $now->format('Y-m') . '.log', $entry, FILE_APPEND | LOCK_EX);

        $this->prune($now);
    }

    /**
     * Delete old logs, at most once a day.
     */
    private function prune(DateTimeImmutable $now): void
    {
        $marker = $this->folder . 'pruned.txt';
        $today  = $now->format('Y-m-d');

        if (is_file($marker) && file_get_contents($marker) === $today) {
            return;
        }

        @file_put_contents($marker, $today);

        $cutoff = $now->getTimestamp() - $this->keep_days * 86400;

        foreach (glob($this->folder . 'chat-*.log') ?: [] as $file) {
            // Never delete this month's log, even if it was last written long ago.
            if (basename($file) !== 'chat-' . $now->format('Y-m') . '.log' && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
