<?php

declare(strict_types=1);

namespace MichaelBeatty\WebtreesModules\Chatbot;

use function array_slice;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function mb_substr;
use function trim;

/**
 * Work out what a question is asking for, using a small, cheap model.
 *
 * This replaces free-text name extraction with schema-enforced JSON, and sees
 * the recent conversation, so follow-ups such as "Who were his parents?" work.
 */
class QueryPlanner
{
    public const INTENTS = ['person', 'relationship', 'surname', 'general'];

    public const REFERS_TO = ['none', 'discussed', 'page'];

    private const SYSTEM = <<<'TEXT'
        You analyse questions sent to the chat assistant of a family history (genealogy) website.
        Work out what the latest question is asking for. Use the recent conversation to understand
        follow-up questions and pronouns. Reply only with JSON that matches the schema.

        intent:
        - "person": about specific individuals (their life, dates, places, parents, spouses, children, siblings).
        - "relationship": how two individuals are related to each other.
        - "surname": about a family name in general, e.g. "Tell me about the Smith family".
        - "general": anything else, e.g. greetings, how to use the website, genealogy advice, off-topic questions.

        people: the individuals named in the latest question, in the order they are mentioned, at most two.
        Split each name into given names and surname. Set birth_year only if the user gives a year for the
        person (e.g. "born in 1850", "the one from 1850"), and place only if the user mentions a place for them.
        For "surname" questions, put the surname in people[0].surname and leave given empty.
        If the user chooses one of several people the assistant listed (e.g. "the one born in 1850"),
        repeat that person's name and set birth_year.

        refers_to: does the question refer to a person without naming them (he, she, his, her, him,
        "this person", or no name at all)?
        - "discussed": the person currently being discussed in the conversation.
        - "page": the person shown on the page the user is looking at (e.g. "this person", "him" when
          nobody has been discussed yet).
        - "none": the question names everyone it is about, or is not about a person.

        Example reply for "Who were John Smith's parents? He was born in 1850.":
        {"intent": "person", "people": [{"given": "John", "surname": "Smith", "birth_year": 1850, "place": ""}], "refers_to": "none"}
        TEXT;

    private const SCHEMA = [
        'type'       => 'OBJECT',
        'properties' => [
            'intent' => [
                'type'   => 'STRING',
                'format' => 'enum',
                'enum'   => self::INTENTS,
            ],
            'people' => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'given'      => ['type' => 'STRING'],
                        'surname'    => ['type' => 'STRING'],
                        'birth_year' => ['type' => 'INTEGER', 'nullable' => true],
                        'place'      => ['type' => 'STRING'],
                    ],
                    'required'   => ['given', 'surname'],
                ],
            ],
            'refers_to' => [
                'type'   => 'STRING',
                'format' => 'enum',
                'enum'   => self::REFERS_TO,
            ],
        ],
        'required'   => ['intent', 'people', 'refers_to'],
    ];

    private GeminiClient $gemini;

    private string $model;

    public function __construct(GeminiClient $gemini, string $model)
    {
        $this->gemini = $gemini;
        $this->model  = $model;
    }

    /**
     * @param string               $question
     * @param array<array<string>> $history    [['role' => 'user'|'assistant', 'text' => '...'], ...]
     * @param string               $discussed  name of the person being discussed, or ''
     * @param string               $page       name of the person on the current page, or ''
     *
     * @return array{intent:string,people:array<array{given:string,surname:string,birth_year:int|null,place:string}>,refers_to:string,tokens_in:int,tokens_out:int}
     */
    public function plan(string $question, array $history, string $discussed, string $page): array
    {
        $prompt = '';

        if ($discussed !== '') {
            $prompt .= 'Person being discussed: ' . $discussed . "\n";
        }

        if ($page !== '') {
            $prompt .= 'Person shown on the current page: ' . $page . "\n";
        }

        if ($history !== []) {
            $prompt .= "\nRecent conversation:\n";
            foreach (array_slice($history, -4) as $message) {
                // The assistant's long answers don't help here; their start is enough for context.
                $text    = $message['role'] === 'user' ? $message['text'] : mb_substr($message['text'], 0, 400);
                $prompt .= ($message['role'] === 'user' ? 'User: ' : 'Assistant: ') . $text . "\n";
            }
        }

        $prompt .= "\nLatest question: " . $question;

        $result = $this->gemini->generate($this->model, self::SYSTEM, [['role' => 'user', 'text' => $prompt]], [
            'temperature'      => 0,
            'maxOutputTokens'  => 512,
            'responseMimeType' => 'application/json',
            'responseSchema'   => self::SCHEMA,
        ]);

        $data = $result === null ? null : json_decode($this->stripCodeFence($result['text']), true);
        $plan = $this->normalize($data);

        // If the planner fails, still answer: look for names in the question, or
        // assume a follow-up about whoever we were discussing.
        if (!is_array($data)) {
            $plan['people']    = $this->namesIn($question);
            $plan['intent']    = $plan['people'] !== [] || $discussed !== '' || $page !== '' ? 'person' : 'general';
            $plan['refers_to'] = $plan['people'] !== [] ? 'none' : ($discussed !== '' ? 'discussed' : ($page !== '' ? 'page' : 'none'));
            $plan['error']     = $result === null ? $this->gemini->lastError() : 'Planner reply was not JSON: ' . mb_substr($result['text'], 0, 200);
        }

        $plan['tokens_in']  = $result['tokens_in'] ?? 0;
        $plan['tokens_out'] = $result['tokens_out'] ?? 0;

        return $plan;
    }

    /**
     * Without a schema, models sometimes wrap JSON in a Markdown code block.
     */
    private function stripCodeFence(string $text): string
    {
        return trim((string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', $text));
    }

    /**
     * A fallback for when the planner is unavailable: capitalised words in a row look like names.
     *
     * @return array<array{given:string,surname:string,birth_year:int|null,place:string}>
     */
    private function namesIn(string $question): array
    {
        $people = [];

        preg_match_all('/\b(\p{Lu}[\p{L}\'’-]+(?:\s+\p{Lu}[\p{L}\'’.-]*)+)/u', $question, $matches);

        foreach ($matches[1] as $name) {
            $words = preg_split('/\s+/', $name);

            // Skip words that start a sentence, e.g. "Who", "Tell", "And".
            while ($words !== [] && in_array(mb_strtolower($words[0]), ['who', 'what', 'when', 'where', 'why', 'how', 'tell', 'was', 'were', 'is', 'are', 'did', 'does', 'do', 'show', 'find', 'and', 'or', 'but', 'also', 'please', 'can', 'could'], true)) {
                array_shift($words);
            }

            if (count($words) >= 2 && count($people) < 2) {
                // "Smith's parents" -> "Smith"
                $surname  = (string) preg_replace("/['’]s?$/u", '', array_pop($words));
                $people[] = ['given' => implode(' ', $words), 'surname' => $surname, 'birth_year' => null, 'place' => ''];
            }
        }

        return $people;
    }

    /**
     * Never trust the shape of a model's output.
     *
     * @param mixed $data
     *
     * @return array{intent:string,people:array<array{given:string,surname:string,birth_year:int|null,place:string}>,refers_to:string}
     */
    private function normalize($data): array
    {
        $data = is_array($data) ? $data : [];

        $intent    = $data['intent'] ?? 'general';
        $refers_to = $data['refers_to'] ?? 'none';
        $people    = [];

        foreach (array_slice(is_array($data['people'] ?? null) ? $data['people'] : [], 0, 2) as $person) {
            if (!is_array($person)) {
                continue;
            }

            $given   = is_string($person['given'] ?? null) ? trim($person['given']) : '';
            $surname = is_string($person['surname'] ?? null) ? trim($person['surname']) : '';

            if ($given === '' && $surname === '') {
                continue;
            }

            $people[] = [
                'given'      => mb_substr($given, 0, 100),
                'surname'    => mb_substr($surname, 0, 100),
                'birth_year' => is_int($person['birth_year'] ?? null) ? $person['birth_year'] : null,
                'place'      => is_string($person['place'] ?? null) ? mb_substr(trim($person['place']), 0, 100) : '',
            ];
        }

        return [
            'intent'    => in_array($intent, self::INTENTS, true) ? $intent : 'general',
            'people'    => $people,
            'refers_to' => in_array($refers_to, self::REFERS_TO, true) ? $refers_to : 'none',
        ];
    }
}
