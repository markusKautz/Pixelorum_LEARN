<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Je Teilnehmer_in genau ein Übungsquiz" wird zur Datenbank-Regel.
 *
 * Bis hierher war die Invariante eine Absichtserklärung im Anwendungscode:
 * BuildPracticeQuiz las erst („gibt es schon einen Behälter?") und schrieb dann
 * — zwei Anläufe derselben Teilnehmer_in aus zwei Browser-Tabs konnten beide lesen,
 * beide nichts finden und beide anlegen. Die umgebende Transaktion verhindert
 * das nicht: beide lesen einen gültigen Zustand und schreiben etwas Erlaubtes.
 *
 * Ein **partieller** Unique-Index setzt die Regel dort durch, wo sie hingehört,
 * und zwar nur für Übungsquizze — redaktionelle Quizze eines Admins bleiben
 * unbegrenzt. Ein gewöhnliches `unique(kind, created_by)` wäre falsch: es
 * erlaubte je Admin nur ein einziges freies und ein einziges Abschluss-Quiz.
 *
 * **Engine-Anforderung:** partielle Indizes beherrschen PostgreSQL (alle
 * Versionen) und SQLite (ab 3.8) mit **identischer** Syntax — deshalb kein
 * Dialekt-Zweig. MySQL und MariaDB beherrschen sie nicht und scheiden als
 * Zielsystem aus.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 'practice' steht bewusst literal statt als QuizKind::Practice->value:
        // eine Migration beschreibt den Schema-Stand ihres Zeitpunkts und darf
        // nicht mitwandern, wenn der Enum später umbenannt wird.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX quizzes_practice_owner_unique
                ON quizzes (created_by)
                WHERE kind = 'practice'
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS quizzes_practice_owner_unique');
    }
};
