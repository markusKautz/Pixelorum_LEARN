<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Welchen Gruppen ein Lernpfad zugewiesen ist (#9).
     *
     * Anders als bei `quiz_team` ist eine fehlende Zeile hier kein Verbot:
     * ein Pfad ohne Zuweisung sieht jede Gruppe (E8). Erst eine Zuweisung
     * schränkt ihn auf die genannten Gruppen ein. Bestehende Pfade bleiben
     * damit unverändert sichtbar.
     *
     * Wird eine Gruppe endgültig gelöscht, fallen ihre Zeilen mit weg; war sie
     * die einzige Zuweisung, ist der Pfad danach wieder für alle sichtbar.
     */
    public function up(): void
    {
        Schema::create('learning_path_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['learning_path_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learning_path_team');
    }
};
