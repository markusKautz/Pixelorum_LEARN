<?php

namespace Database\Factories;

use App\Enums\PathStatus;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LearningPath>
 */
class LearningPathFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Der Slug wird bewusst **nicht** gesetzt: das erledigt der `saving`-Hook
     * über `generateUniqueSlug()`. Stünde er hier, wäre er aus dem
     * *erwürfelten* Titel berechnet — ein `create(['title' => …])` ersetzt nur
     * `title` im fertigen Array und rechnet nicht neu, der Hook schweigt bei
     * nicht-leerem Slug. So läuft die Fixture durch denselben Pfad wie
     * Produktion (Muster TeamFactory).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::headline(rtrim(fake()->unique()->sentence(3), '.')),
            'summary' => fake()->sentence(),
            'objectives' => [
                'Die wichtigsten Begriffe erklären können',
                'Das Gelernte an einem Beispiel anwenden',
            ],
            'position' => fake()->numberBetween(0, 20),
            'difficulty' => fake()->randomElement(['einsteiger', 'fortgeschritten']),
            'estimated_minutes' => fake()->numberBetween(15, 90),
            'pass_threshold' => 80,
            'status' => PathStatus::Draft,
            'review_status' => ReviewStatus::Unreviewed,
        ];
    }

    /**
     * Indicate that the path is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PathStatus::Published,
        ]);
    }

    /**
     * Indicate that the path has been verified against its sources
     * (Grounding-Gate, Konzept e).
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'review_status' => ReviewStatus::Verified,
            'verified_at' => now(),
            'verified_by' => User::factory()->admin(),
        ]);
    }

    /**
     * Assign the path to every running group that exists at creation time,
     * like „Allen Gruppen zuweisen“ (#9, E14).
     *
     * Ohne Zuweisung sieht einen Pfad niemand (E12). Gruppen, die erst danach
     * entstehen, bekommen nichts; die Tests legen ihre Teilnehmer_innen deshalb vor
     * dem Pfad an.
     */
    public function assignedToAllGroups(): static
    {
        return $this->afterCreating(function (LearningPath $path): void {
            $path->teams()->attach(Team::query()->groups()->active()->pluck('id')->all());
        });
    }
}
