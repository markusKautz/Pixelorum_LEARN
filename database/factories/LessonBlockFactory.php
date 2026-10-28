<?php

namespace Database\Factories;

use App\Enums\LessonBlockType;
use App\Models\Lesson;
use App\Models\LessonBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Jeder State liefert shape-konformen Beispiel-Content nach Konzept d.
 *
 * @extends Factory<LessonBlock>
 */
class LessonBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'type' => LessonBlockType::Markdown,
            'content' => [
                'body' => fake()->paragraph().PHP_EOL.PHP_EOL.fake()->paragraph(),
            ],
            'position' => 0,
        ];
    }

    /**
     * Indicate that the block is an accordion.
     */
    public function accordion(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::Accordion,
            'content' => [
                'items' => [
                    [
                        'heading' => 'Was ist eine Variable?',
                        'body' => 'Ein benannter Platzhalter für einen Wert im Speicher.',
                    ],
                    [
                        'heading' => 'Wann brauche ich eine Konstante?',
                        'body' => 'Wenn sich ein Wert zur Laufzeit **nicht** ändern darf.',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Indicate that the block is a callout.
     */
    public function callout(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::Callout,
            'content' => [
                'variant' => 'exam',
                'body' => '**Prüfungsrelevant:** Der Unterschied zwischen Wert- und Referenztypen wird häufig gefragt.',
            ],
        ]);
    }

    /**
     * Indicate that the block is a code snippet.
     */
    public function codeSnippet(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::CodeSnippet,
            'content' => [
                'language' => 'php',
                'code' => "<?php\n\n\$name = 'Welt';\n\necho \"Hallo {\$name}!\";",
                'filename' => 'hallo.php',
            ],
        ]);
    }

    /**
     * Indicate that the block is a diagram.
     */
    public function diagram(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::Diagram,
            'content' => [
                'format' => 'mermaid',
                'source' => "graph TD;\n  Anfrage-->Controller;\n  Controller-->Datenbank;",
                'caption' => 'Ablauf einer Anfrage',
            ],
        ]);
    }

    /**
     * Indicate that the block is a media element.
     */
    public function media(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::Media,
            'content' => [
                'kind' => 'image',
                'url' => 'https://example.test/bilder/schleifen.png',
                'caption' => 'Aufbau einer Schleife',
                'alt' => 'Schematische Darstellung einer Schleife',
            ],
        ]);
    }

    /**
     * Indicate that the block is a glossary.
     */
    public function glossary(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::Glossary,
            'content' => [
                'terms' => [
                    [
                        'term' => 'Kompilieren',
                        'definition' => 'Das Übersetzen von Quellcode in Maschinencode.',
                    ],
                    [
                        'term' => 'Laufzeitfehler',
                        'definition' => 'Ein Fehler, der erst bei der Ausführung des Programms auftritt.',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Indicate that the block is a section break.
     */
    public function sectionBreak(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonBlockType::SectionBreak,
            'content' => ['title' => 'Vertiefung'],
        ]);
    }
}
