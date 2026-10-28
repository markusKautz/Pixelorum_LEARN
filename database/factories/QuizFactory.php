<?php

namespace Database\Factories;

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::headline(rtrim(fake()->unique()->sentence(3), '.')),
            'description' => fake()->sentence(),
            'kind' => QuizKind::Custom,
            'learning_path_id' => null,
            'pass_threshold' => 80,
            'shuffle_questions' => false,
            'created_by' => User::factory()->admin(),
            'status' => PathStatus::Draft,
        ];
    }

    /**
     * Indicate that the quiz concludes a learning path. Der Pfad ist Pflicht:
     * der saving-Guard auf dem Model weist ein path_final-Quiz ohne Pfad ab.
     */
    public function pathFinal(?LearningPath $path = null): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => QuizKind::PathFinal,
            'learning_path_id' => $path !== null ? $path->id : LearningPath::factory(),
        ]);
    }

    /**
     * Indicate that the quiz is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PathStatus::Published,
        ]);
    }
}
