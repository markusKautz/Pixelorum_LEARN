<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Je Teilnehmer_in und Quiz höchstens ein **laufender** Versuch" wird zur DB-Regel.
 *
 * Abgeschlossene Versuche bleiben ausdrücklich unbegrenzt — die Auswertung
 * lebt davon (bester Versuch, Anzahl Durchgänge). Eingeschränkt wird nur der
 * offene Versuch, und dafür ist `completed_at IS NULL` der natürliche Filter.
 *
 * Bisher war das eine Annahme des Anwendungscodes: `RecordAnswer` legt den
 * Versuch mit der ersten Antwort an (Sprint-4-Entscheidung 2), und die
 * Quiz-Seite sucht sich mit `whereNull('completed_at')->latest('started_at')`
 * den laufenden heraus. Das `latest()` ist der Fingerzeig — es beantwortet eine
 * Frage, die es gar nicht geben dürfte. Zwei fast gleichzeitige erste Antworten
 * aus zwei Tabs erzeugten zwei Versuche; von da an schrieb die Teilnehmer_in in den
 * einen und sah womöglich den anderen, und beim Abschluss zählte nur einer.
 *
 * Ein Unique-Index allein hätte hier nicht gereicht: mehrere abgeschlossene
 * Versuche desselben Paares sind erlaubt und richtig. Erst die
 * `WHERE`-Bedingung trennt „laufend" von „abgeschlossen" und macht die Regel
 * ausdrückbar.
 *
 * **Engine-Anforderung:** partielle Indizes beherrschen PostgreSQL (alle
 * Versionen) und SQLite (ab 3.8) mit identischer Syntax.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX quiz_attempts_running_unique
                ON quiz_attempts (user_id, quiz_id)
                WHERE completed_at IS NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS quiz_attempts_running_unique');
    }

    /**
     * Fail with the offending pairs instead of a bare constraint violation.
     *
     * Auf einer Bestandsdatenbank können bereits zwei offene Versuche desselben
     * Paares liegen. Welcher davon der „richtige" ist, kann diese Migration
     * nicht wissen — sie nennt deshalb die Paare und überlässt die Entscheidung
     * dem Betreiber, statt still einen davon abzuräumen.
     *
     * @throws RuntimeException
     */
    protected function guardAgainstExistingDuplicates(): void
    {
        $conflicts = DB::table('quiz_attempts')
            ->whereNull('completed_at')
            ->select('user_id', 'quiz_id')
            ->groupBy('user_id', 'quiz_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->map(fn (object $row): string => "user_id={$row->user_id}, quiz_id={$row->quiz_id}");

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'Mehrere offene Quiz-Versuche derselben Teilnehmer_in — bitte vor dieser '
                .'Migration bereinigen (abschließen oder löschen): '.$conflicts->implode('; ')
            );
        }
    }
};
