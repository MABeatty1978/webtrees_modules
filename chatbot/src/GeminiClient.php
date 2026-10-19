<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function strpos;
use function substr;

/**
 * Call the Google Gemini "generateContent" API.
 *
 * Uses curl directly, rather than an HTTP library, because webtrees 2.1 ships
 * Guzzle and later versions ship Symfony's client.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $api_key;

    private string $last_error = '';

    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
    }

    /**
     * @param string                   $model    e.g. "gemini-2.5-flash-lite"
     * @param string                   $system   system instruction
     * @param array<array<string>>     $messages [['role' => 'user'|'model', 'text' => '...'], ...]
     * @param array<string,mixed>      $config   generationConfig, e.g. temperature, responseSchema
     *
     * @return array{text:string,tokens_in:int,tokens_out:int}|null null on failure; see lastError()
     */
    public function generate(string $model, string $system, array $messages, array $config): ?array
    {
        $contents = [];
        foreach ($messages as $message) {
            $contents[] = [
                'role'  => $message['role'],
                'parts' => [['text' => $message['text']]],
            ];
        }

        $thinking = $this->thinkingConfig($model);

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $contents,
            'generationConfig'  => $config + ($thinking === null ? [] : ['thinkingConfig' => $thinking]),
        ];

        [$status, $response] = $this->post($model, $body);

        // Older/newer models reject thinking settings they don't support, so try again without them.
        if ($status === 400 && $thinking !== null) {
            unset($body['generationConfig']['thinkingConfig']);
            [$status, $response] = $this->post($model, $body);
        }

        // If the API rejects the schema, plain JSON mode still works, as the prompt describes the format.
        if ($status === 400 && isset($body['generationConfig']['responseSchema'])) {
            unset($body['generationConfig']['responseSchema']);
            [$status, $response] = $this->post($model, $body);
        }

        if ($status === 0) {
            return null;
        }

        if ($status !== 200 || !is_array($response)) {
            $message = is_array($response) ? (string) ($response['error']['message'] ?? '') : '';

            $this->last_error = 'HTTP ' . $status . ' from ' . $model . ($message === '' ? '' : ': ' . $message);

            return null;
        }

        $text = '';
        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            // Skip any thought summaries; keep only the answer.
            if (is_string($part['text'] ?? null) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }

        if ($text === '') {
            $this->last_error = 'Empty response from ' . $model . ' (finish reason: ' . ($response['candidates'][0]['finishReason'] ?? 'unknown') . ')';

            return null;
        }

        $usage = $response['usageMetadata'] ?? [];

        return [
            'text'       => $text,
            'tokens_in'  => (int) ($usage['promptTokenCount'] ?? 0),
            'tokens_out' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
        ];
    }

    public function lastError(): string
    {
        return $this->last_error;
    }

    /**
     * Thinking improves hard reasoning, but is billed as output and slows the
     * reply.  Our questions are simple lookups, so keep it to a minimum.
     *
     * @return array<string,int|string>|null
     */
    private function thinkingConfig(string $model): ?array
    {
        if (strpos($model, 'gemini-2.5-flash') === 0) {
            return ['thinkingBudget' => 0];
        }

        if (strpos($model, 'gemini-3') === 0) {
            return ['thinkingLevel' => 'low'];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array{0:int,1:mixed} HTTP status (0 for a network error) and decoded JSON
     */
    private function post(string $model, array $body): array
    {
        $curl = curl_init(self::ENDPOINT . rawurlencode($model) . ':generateContent');

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                // A header, not the URL, so the key never appears in server or proxy logs.
                'x-goog-api-key: ' . $this->api_key,
            ],
        ]);

        $output = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error  = curl_error($curl);
        curl_close($curl);

        if (!is_string($output)) {
            $this->last_error = 'Network error: ' . substr($error, 0, 200);

            return [0, null];
        }

        return [$status, json_decode($output, true)];
    }
}
