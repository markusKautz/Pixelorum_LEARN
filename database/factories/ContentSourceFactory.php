<?php

namespace Database\Factories;

use App\Enums\SourceKind;
use App\Models\ContentSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentSource>
 */
class ContentSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Str::slug(fake()->unique()->sentence(3)),
            'kind' => SourceKind::Pdf,
            'title' => Str::headline(rtrim(fake()->sentence(4), '.')),
            'publisher' => fake()->company(),
            'url' => null,
            'version' => null,
            'published_at' => null,
            'accessed_at' => null,
            'content_sha256' => null,
        ];
    }

    /**
     * Indicate that the source is a slide deck.
     */
    public function slides(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => SourceKind::Slides,
            'version' => '2024',
        ]);
    }

    /**
     * Indicate that the source is an approved web page.
     */
    public function web(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => SourceKind::Web,
            'url' => fake()->url(),
            'published_at' => '2024-08-01',
            'accessed_at' => '2026-08-07',
            'content_sha256' => hash('sha256', fake()->sentence()),
        ]);
    }
}
