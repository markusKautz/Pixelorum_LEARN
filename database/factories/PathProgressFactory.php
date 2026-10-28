<?php

namespace Database\Factories;

use App\Enums\ProgressStatus;
use App\Models\LearningPath;
use App\Models\PathProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Die States halten status und percent konsistent zueinander.
 *
 * @extends Factory<PathProgress>
 */
class PathProgressFactory extends Factory
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
            'learning_path_id' => LearningPath::factory(),
            'status' => ProgressStatus::NotStarted,
            'percent' => 0,
            'last_activity_at' => null,
        ];
    }

    /**
     * Indicate that the learner has started, but not finished, the path.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProgressStatus::InProgress,
            'percent' => fake()->numberBetween(1, 99),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Indicate that the learner has finished the path.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProgressStatus::Completed,
            'percent' => 100,
            'last_activity_at' => now(),
        ]);
    }
}
