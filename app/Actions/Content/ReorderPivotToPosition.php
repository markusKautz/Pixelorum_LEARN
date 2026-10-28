<?php

namespace App\Actions\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Gegenstück zu ReorderToPosition für Pivot-Positionen (quiz_questions):
 * verlässt sich auf die konfigurierte Ordnung der Relation (orderByPivot)
 * und schreibt kompakte 1-basierte Positionen über updateExistingPivot.
 */
class ReorderPivotToPosition
{
    /**
     * Move the related record to the position within the pivot's row set.
     *
     * @param  BelongsToMany<covariant Model, covariant Model>  $relation
     */
    public function handle(BelongsToMany $relation, int $movedId, int $position): void
    {
        DB::transaction(function () use ($relation, $movedId, $position) {
            $ordered = (clone $relation)
                ->whereKeyNot($movedId)
                ->pluck($relation->getRelated()->getQualifiedKeyName())
                ->values()
                ->all();

            array_splice($ordered, $position, 0, [$movedId]);

            foreach ($ordered as $index => $id) {
                $relation->updateExistingPivot($id, ['position' => $index + 1]);
            }
        });
    }
}
