<?php

namespace Database\Factories;

use App\Models\LearningPath;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learning_path_id' => LearningPath::factory(),
            'title' => Str::headline(rtrim(fake()->unique()->sentence(3), '.')),
            'position' => 0,
            'source_reference' => null,
        ];
    }

    /**
     * Indicate that the lesson carries a source reference (Konzept e).
     */
    public function withSourceReference(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_reference' => [
                ['source_id' => 'scriptum', 'page' => fake()->numberBetween(1, 174)],
            ],
        ]);
    }
}
