<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bestehende Pfade allen Gruppen zuweisen (#9, E13).
     *
     * Seit E12 sieht einen Pfad ohne Zuweisung niemand mehr. Damit sich beim
     * Update für keine Gruppe etwas ändert, bekommt jeder Pfad, der vor dem
     * Stichtag angelegt wurde, alle zu diesem Zeitpunkt bestehenden Gruppen,
     * archivierte und Pfade im Papierkorb eingeschlossen. Neuere Pfade und
     * später angelegte Gruppen bekommen nichts: sie werden bewusst zugewiesen.
     *
     * Über den Query Builder statt über Models, damit die Migration nicht von
     * späteren Änderungen an Scopes oder Relationen abhängt.
     */
    public function up(): void
    {
        $pathIds = DB::table('learning_paths')
            ->where('created_at', '<', '2026-10-30 00:00:00')
            ->pluck('id');

        $teamIds = DB::table('teams')
            ->where('is_personal', false)
            ->whereNull('deleted_at')
            ->pluck('id');

        $now = now();

        foreach ($pathIds as $pathId) {
            DB::table('learning_path_team')->insertOrIgnore(
                $teamIds->map(fn (int $teamId): array => [
                    'learning_path_id' => $pathId,
                    'team_id' => $teamId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * Bewusst leer: welche Zuweisungen von hier stammen und welche später von
     * Hand gesetzt wurden, lässt sich nicht mehr unterscheiden.
     */
    public function down(): void
    {
        //
    }
};
