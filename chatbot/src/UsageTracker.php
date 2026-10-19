<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use function fclose;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function rewind;
use function stream_get_contents;

/**
 * Count the questions answered today, for the daily limit.
 *
 * The file is locked while it is updated, so that questions arriving at the
 * same time are all counted.
 */
class UsageTracker
{
    private string $file;

    public function __construct(string $folder)
    {
        if (!is_dir($folder)) {
            @mkdir($folder, 0755, true);
        }

        $this->file = $folder . 'usage.json';
    }

    /**
     * Add one to today's count, unless the limit has been reached.
     *
     * @param int $limit 0 for no limit
     *
     * @return int|null the new count, or null if the limit has been reached
     */
    public function increment(string $today, int $limit): ?int
    {
        return $this->update($today, 1, $limit);
    }

    public function count(string $today): int
    {
        return (int) $this->update($today, 0, 0);
    }

    private function update(string $today, int $add, int $limit): ?int
    {
        $handle = @fopen($this->file, 'c+');

        // If we cannot count, do not stop the chatbot working.
        if ($handle === false) {
            return 0;
        }

        flock($handle, LOCK_EX);

        $data  = json_decode((string) stream_get_contents($handle), true);
        $count = is_array($data) && ($data['date'] ?? '') === $today ? (int) ($data['count'] ?? 0) : 0;

        if ($limit > 0 && $count >= $limit) {
            $count = null;
        } elseif ($add !== 0) {
            $count += $add;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode(['date' => $today, 'count' => $count]));
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return $count;
    }
}
