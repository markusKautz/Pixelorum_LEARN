<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Der Slug wird bewusst **nicht** gesetzt: das erledigt der
     * `creating`-Hook über `generateUniqueSlug()`. Stünde er hier, wäre er aus
     * dem *erwürfelten* Namen berechnet — ein `create(['name' => 'Lehrjahr
     * 2026'])` ersetzt nämlich nur `name` im fertigen Array und rechnet nicht
     * neu, und der Hook schweigt, weil der Slug nicht leer ist. Ergebnis wäre
     * eine Gruppe „Lehrjahr 2026" unter `/schnabl-ogh/…`. So läuft die Fixture
     * durch denselben Pfad wie Produktion.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_personal' => false,
        ];
    }

    /**
     * Indicate that the team is a personal team.
     */
    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_personal' => true,
        ]);
    }

    /**
     * Indicate that the year has been closed (Sprint 5.7).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Indicate that the team has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
