<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Die Default-Definition liefert alle Pflichtfelder (inkl. `explanation`);
 * die States ergänzen ausschließlich typkonsistente Antwortoptionen.
 *
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Bereits vergebene Positionen dieses Factory-Aufrufs, je Scope.
     *
     * Nötig für `count(n)`: Laravel baut erst **alle** Instanzen und speichert
     * sie danach, ein reines `max(position)` aus der Datenbank gäbe allen
     * dieselbe Position. Der Zustand hängt am Objekt, das `Question::factory()`
     * zurückgibt — jeder Aufruf beginnt also frisch und liest den aktuellen
     * DB-Stand.
     *
     * @var array<string, int>
     */
    protected array $vergebenePositionen = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learning_path_id' => null,
            'lesson_id' => null,
            'type' => QuestionType::Single,
            'prompt' => rtrim(fake()->sentence(8), '.').'?',
            'explanation' => fake()->paragraph(),
            'source_reference' => null,
            // 0 heißt „noch nicht vergeben" — configure() setzt die nächste
            // freie Position im jeweiligen Scope ein.
            'position' => 0,
        ];
    }

    /**
     * Hand out a free position within the question's own key scope.
     *
     * Ohne das erzeugte jede Fixture ohne ausdrückliche Position eine 0, und
     * zwei Pool-Fragen desselben Pfades kollidierten auf den Import-Schlüsseln
     * (`questions_pool_position_unique` / `questions_inline_position_unique`).
     * Die Factory soll keine Zustände bauen können, die die Datenbank zu Recht
     * verweigert — dieselbe Linie wie der Guard in
     * {@see QuestionResponseFactory::correct()}.
     *
     * `afterMaking` statt `definition()`, weil der Scope erst feststeht, wenn
     * States wie `asLessonCheck()` oder `for($path)` angewandt sind.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Question $question): void {
            if ($question->position !== 0) {
                return;
            }

            $question->position = $this->naechstePosition($question);
        });
    }

    /**
     * Indicate a single-choice question: four options, exactly one correct.
     */
    public function singleChoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => QuestionType::Single,
        ])->afterCreating(function (Question $question) {
            $this->createOptions($question, total: 4, correct: [fake()->numberBetween(0, 3)]);
        });
    }

    /**
     * Indicate a multiple-choice question: five options, two or three correct.
     */
    public function multipleChoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => QuestionType::Multiple,
        ])->afterCreating(function (Question $question) {
            $correct = fake()->randomElements(range(0, 4), fake()->numberBetween(2, 3));

            $this->createOptions($question, total: 5, correct: $correct);
        });
    }

    /**
     * Indicate a true/false question: exactly two options, one correct.
     */
    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => QuestionType::Boolean,
        ])->afterCreating(function (Question $question) {
            $wahrIstKorrekt = fake()->boolean();

            foreach (['Wahr', 'Falsch'] as $index => $label) {
                QuestionOption::factory()->for($question)->create([
                    'label' => $label,
                    'is_correct' => $index === 0 ? $wahrIstKorrekt : ! $wahrIstKorrekt,
                    'position' => $index + 1,
                ]);
            }
        });
    }

    /**
     * Indicate that the question is an inline check on the given lesson
     * (Verwendung 1 aus Konzept d).
     */
    public function asLessonCheck(Lesson $lesson): static
    {
        return $this->state(fn (array $attributes) => [
            'lesson_id' => $lesson->id,
            'learning_path_id' => $lesson->learning_path_id,
        ]);
    }

    /**
     * Indicate that the question carries a source reference (Konzept e).
     */
    public function withSourceReference(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_reference' => [
                ['source_id' => 'it-osi', 'slide' => fake()->numberBetween(1, 15)],
            ],
        ]);
    }

    /**
     * Resolve the next free position for this question's key scope.
     *
     * Zwei Schlüssel, zwei Scopes — genau die Aufteilung, die auch der
     * Content-Import und die beiden partiellen Unique-Indizes verwenden. Fragen
     * ganz ohne Herkunft (beide Spalten null) sind Waisen; für sie gilt der
     * Index nicht, weil NULL dort als von allem verschieden zählt.
     */
    protected function naechstePosition(Question $question): int
    {
        if ($question->lesson_id !== null) {
            $scope = "lesson:{$question->lesson_id}";
            $bereitsBelegt = fn (): int => (int) Question::query()
                ->where('lesson_id', $question->lesson_id)
                ->max('position');
        } elseif ($question->learning_path_id !== null) {
            $scope = "path:{$question->learning_path_id}";
            $bereitsBelegt = fn (): int => (int) Question::query()
                ->where('learning_path_id', $question->learning_path_id)
                ->whereNull('lesson_id')
                ->max('position');
        } else {
            return 0;
        }

        $letzte = $this->vergebenePositionen[$scope] ?? $bereitsBelegt();

        return $this->vergebenePositionen[$scope] = $letzte + 1;
    }

    /**
     * Create the given number of options, marking the listed indexes correct.
     *
     * @param  array<int, int>  $correct
     */
    protected function createOptions(Question $question, int $total, array $correct): void
    {
        foreach (range(0, $total - 1) as $index) {
            QuestionOption::factory()->for($question)->create([
                'is_correct' => in_array($index, $correct, strict: true),
                'position' => $index + 1,
            ]);
        }
    }
}
