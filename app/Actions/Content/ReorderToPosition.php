<?php

namespace App\Actions\Content;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Gemeinsame Umsortier-Logik der Admin-Listen (Entscheidung 10) für Zeilen
 * mit eigener position-Spalte: Restmenge position-geordnet laden, das
 * verschobene Element an die Zielposition splicen, kompakte 1-basierte
 * Positionen zurückschreiben — transaktional, nur tatsächlich geänderte
 * Zeilen updatend. Löschungen dürfen Lücken lassen; Neuzugänge landen per
 * max+1 weiterhin am Ende. Pivot-Positionen sortiert ReorderPivotToPosition.
 */
class ReorderToPosition
{
    /**
     * Move the given row to the position within the query's row set.
     *
     * @param  Builder<covariant Model>|HasMany<covariant Model, covariant Model>  $query
     */
    public function handle(Builder|HasMany $query, Model $moved, int $position): void
    {
        DB::transaction(function () use ($query, $moved, $position) {
            $ordered = $query
                ->whereKeyNot($moved->getKey())
                ->orderBy('position')
                ->get()
                ->values();

            $ordered->splice($position, 0, [$moved]);

            $ordered->values()->each(function (Model $item, int $index) {
                if ($item->getAttribute('position') !== $index + 1) {
                    $item->update(['position' => $index + 1]);
                }
            });
        });
    }
}
