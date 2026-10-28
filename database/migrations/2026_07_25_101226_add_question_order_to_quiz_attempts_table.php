<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Fragenliste, die *dieser* Versuch zeigt und gegen die er gewertet
     * wird (Sprint-5.5-Grundlage) — ein JSON-Array von `question_id`s.
     *
     * Bewusst nullable: `null` heißt „nimm die aktuelle Pivot-Reihenfolge des
     * Quiz". Bestehende Versuche und alle ungemischten Abschluss-Quizze
     * verhalten sich dadurch unverändert und brauchen keinen Backfill.
     *
     * Ohne die Spalte müsste ein wiederaufgenommener Versuch seine Reihenfolge
     * bei jedem Aufruf neu ableiten — bei gemischten oder zufällig gezogenen
     * Fragen wäre sie nach jedem Reload eine andere.
     */
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->json('question_order')->nullable()->after('quiz_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('question_order');
        });
    }
};
