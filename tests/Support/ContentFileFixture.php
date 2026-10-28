<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

class ContentFileFixture
{
    /** @return array<string, mixed> */
    public static function valid(): array
    {
        return [
            'slug' => 'test-content-path',
            'title' => 'Test Content Path',
            'summary' => 'Validierungsfixture',
            'objectives' => ['Ein prüfbares Lernziel erreichen'],
            'position' => 1,
            'difficulty' => 'einsteiger',
            'estimated_minutes' => 30,
            'pass_threshold' => 80,
            'status' => 'draft',
            'lessons' => [[
                'position' => 1,
                'title' => 'Erste Lektion',
                'source_reference' => [['source_id' => 'scriptum', 'page' => 12]],
                'blocks' => [[
                    'position' => 1,
                    'type' => 'markdown',
                    'content' => ['body' => 'Geerdeter Inhalt.'],
                ]],
                'questions' => [[
                    'position' => 1,
                    'type' => 'boolean',
                    'prompt' => 'Ist die Aussage richtig?',
                    'explanation' => 'Die Aussage folgt aus der Lektion.',
                    'source_reference' => [['source_id' => 'scriptum', 'page' => 12]],
                    'options' => [
                        ['position' => 1, 'label' => 'Wahr', 'is_correct' => true],
                        ['position' => 2, 'label' => 'Falsch', 'is_correct' => false],
                    ],
                ]],
            ]],
            'questions' => [
                [
                    'position' => 1,
                    'type' => 'single',
                    'prompt' => 'Welche Antwort ist korrekt?',
                    'explanation' => 'Antwort A ist belegt.',
                    'source_reference' => [['source_id' => 'it-osi', 'slide' => 4]],
                    'options' => [
                        ['position' => 1, 'label' => 'Antwort A', 'is_correct' => true],
                        ['position' => 2, 'label' => 'Antwort B', 'is_correct' => false],
                    ],
                ],
                [
                    'position' => 2,
                    'type' => 'multiple',
                    'prompt' => 'Welche Antworten sind korrekt?',
                    'explanation' => 'Antwort A und B sind belegt.',
                    'source_reference' => [['source_id' => 'scriptum', 'page' => 12]],
                    'options' => [
                        ['position' => 1, 'label' => 'Antwort A', 'is_correct' => true],
                        ['position' => 2, 'label' => 'Antwort B', 'is_correct' => true],
                    ],
                ],
            ],
            'quiz' => [
                'title' => 'Abschluss-Quiz',
                'description' => 'Prüft das Lernziel.',
                'pass_threshold' => 80,
                'status' => 'draft',
                'question_positions' => [1, 2],
            ],
        ];
    }

    /** @param array<string, mixed> $content */
    public static function write(array $content, string $name = 'content-validator'): string
    {
        $directory = storage_path('framework/testing/content-file-fixture');
        File::ensureDirectoryExists($directory);
        $path = "{$directory}/{$name}.json";
        File::put($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }

    public static function clean(): void
    {
        File::deleteDirectory(storage_path('framework/testing/content-file-fixture'));
    }
}
