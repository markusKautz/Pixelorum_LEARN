<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Welche Gruppe welches freie Quiz bekommen hat (Sprint 5.6).
     *
     * Die Zuweisung ist zugleich das Sichtbarkeits-Gate: ein freies Quiz ohne
     * Zeile hier sieht kein Lehrling. „Veröffentlicht" heißt damit „fertig",
     * „zugewiesen" heißt „ausgegeben" — dieselbe Zweistufigkeit wie
     * Entwurf/Veröffentlicht bei Pfaden.
     *
     * Abschluss-Quizze beziehen ihre Zielgruppe aus ihrem Pfad und
     * Übungsquizze gehören einem einzelnen Lehrling; beide tauchen hier
     * bewusst nie auf.
     */
    public function up(): void
    {
        Schema::create('quiz_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['quiz_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_team');
    }
};
