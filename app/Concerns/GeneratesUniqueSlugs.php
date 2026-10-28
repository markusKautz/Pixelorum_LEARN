<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Erzeugt eindeutige Slugs aus einem Namen. Setzt SoftDeletes auf dem
 * nutzenden Model voraus (withTrashed): auch der Slug eines gelöschten
 * Datensatzes bleibt belegt, solange er wiederherstellbar ist.
 */
trait GeneratesUniqueSlugs
{
    /**
     * Generate a unique slug from the given name.
     */
    protected static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $defaultSlug = Str::slug($name);

        $query = static::withTrashed()
            ->where(function ($query) use ($defaultSlug) {
                $query->where('slug', $defaultSlug)
                    ->orWhere('slug', 'like', $defaultSlug.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlugs = $query->pluck('slug');

        $maxSuffix = $existingSlugs
            ->map(function (string $slug) use ($defaultSlug): ?int {
                if ($slug === $defaultSlug) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($defaultSlug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $existingSlugs->isEmpty()
            ? $defaultSlug
            : $defaultSlug.'-'.($maxSuffix + 1);
    }
}
