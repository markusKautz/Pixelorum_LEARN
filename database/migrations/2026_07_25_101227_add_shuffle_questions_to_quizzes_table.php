<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ob die Fragen dieses Quiz je Versuch gemischt gezeigt werden
     * (Sprint 5.5, Feature 1). Default false — bestehende Quizze behalten ihre
     * kuratierte Pivot-Reihenfolge, das Mischen ist eine bewusste
     * Admin-Entscheidung je Quiz.
     *
     * Didaktischer Zweck: die Zufallsreihenfolge erschwert reines
     * Positions-Auswendiglernen der Antworten.
     */
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('shuffle_questions')->default(false)->after('pass_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('shuffle_questions');
        });
    }
};
