<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Die States halten score/max_score/passed konsistent zueinander.
 *
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quiz_id' => Quiz::factory(),
            'score' => 0,
            'max_score' => 10,
            'passed' => false,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ];
    }

    /**
     * Indicate a passed attempt (score at or above the 80 % default threshold).
     */
    public function passed(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => 9,
            'max_score' => 10,
            'passed' => true,
        ]);
    }

    /**
     * Indicate a failed attempt.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => 4,
            'max_score' => 10,
            'passed' => false,
        ]);
    }

    /**
     * Indicate an attempt that is still running.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => 0,
            'max_score' => 10,
            'passed' => false,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }
}
