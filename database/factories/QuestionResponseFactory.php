<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionResponse;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<QuestionResponse>
 */
class QuestionResponseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_attempt_id' => QuizAttempt::factory(),
            'question_id' => Question::factory(),
            'selected_option_ids' => [],
            'is_correct' => false,
        ];
    }

    /**
     * Indicate that the answer picked exactly the correct options of its
     * question.
     *
     * Setzt eine Frage **mit** Antwortoptionen voraus (z.B. singleChoice,
     * multipleChoice, boolean). Eine Default-Frage ohne Optionen führt zu
     * einem inkonsistenten Fixture — der Guard verhindert das lautlos (Review N1).
     */
    public function correct(): static
    {
        return $this->afterMaking(function (QuestionResponse $response) {
            if ($response->question->options->isEmpty()) {
                throw new RuntimeException(
                    'Der correct()-State benötigt eine Frage mit Antwortoptionen '
                    .'(z.B. singleChoice/multipleChoice/boolean).'
                );
            }

            $response->selected_option_ids = $response->question
                ->options
                ->where('is_correct', true)
                ->pluck('id')
                ->all();

            $response->is_correct = true;
        });
    }
}
