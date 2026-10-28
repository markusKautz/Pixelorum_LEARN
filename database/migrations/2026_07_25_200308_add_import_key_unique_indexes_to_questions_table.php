<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Die beiden fachlichen Schlüssel des Content-Imports werden zur DB-Regel.
 *
 * `ImportLearningPath::importiereFrage()` identifiziert eine Frage über *einen von
 * zwei* Schlüsseln — Zwischen-Checks über ihre Lektion, Pool-Fragen über ihren
 * Pfad — und wendet darauf `updateOrCreate` an. Das ist ein SELECT gefolgt von
 * einem INSERT; ohne Index behauptet der Code eine Eindeutigkeit, die niemand
 * durchsetzt. Läuft der Import einmal parallel zu sich selbst oder zum
 * Admin-UI, entstehen zwei Fragen mit demselben Schlüssel — und ab da verliert
 * jeder Re-Import seinen Anker: `updateOrCreate` trifft immer nur eine der
 * beiden, die andere driftet mit veraltetem Inhalt weiter. Die Indizes sind
 * damit weniger ein Race-Schutz als die Voraussetzung dafür, dass „Import
 * nochmal laufen lassen" eine Eigenschaft ist und keine Hoffnung (S6.1).
 *
 * Sie **beheben** die Race sogar, statt sie nur lauter zu machen: Laravels
 * `updateOrCreate` landet über `firstOrCreate` in `createOrFirst`, und das
 * fängt die `UniqueConstraintViolationException` ab und liest die Zeile neu.
 * Der Verlierer arbeitet also auf der Zeile des Gewinners weiter.
 *
 * **Warum zwei partielle Indizes und nicht einer über drei Spalten:** In einem
 * Unique-Index gilt NULL als von allem verschieden. Ein
 * `unique(learning_path_id, lesson_id, position)` ließe deshalb beliebig viele
 * Pool-Fragen auf derselben Pfad-Position zu, weil deren `lesson_id` NULL ist —
 * also genau den Fall, den der Import absichern muss. Die `WHERE`-Bedingung
 * nimmt NULL aus dem Schlüssel heraus und trennt die beiden Schlüsselarten
 * sauber; die Teilmengen sind komplementär, jede Zeile fällt in genau eine.
 *
 * **Warum das auf `position` überhaupt geht:** Ein Unique-Index auf einer
 * Positionsspalte verträgt sich normalerweise nicht mit Umsortieren, weil das
 * Positionen zeilenweise vertauscht und schon der Zwischenzustand die
 * Eindeutigkeit verletzt. Fragen werden aber nie umsortiert — Positionen
 * entstehen ausschließlich per max+1 im jeweiligen Scope, und der einzige Pfad,
 * der den Schlüssel wechselt (Lektion löschen → Zwischen-Checks werden
 * Pool-Fragen), nummeriert sie bereits ans Pool-Ende um. Für `lessons`,
 * `lesson_blocks` und `question_options` gilt das **nicht**: die schreiben beim
 * Sortieren zeilenweise um und bekommen deshalb bewusst keinen solchen Index.
 * Ein `DEFERRABLE`-Constraint wäre dort auch kein Ausweg — Deferral setzt einen
 * echten Constraint voraus, und Constraints kennen kein `WHERE`.
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
            CREATE UNIQUE INDEX questions_inline_position_unique
                ON questions (lesson_id, position)
                WHERE lesson_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX questions_pool_position_unique
                ON questions (learning_path_id, position)
                WHERE lesson_id IS NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS questions_inline_position_unique');
        DB::statement('DROP INDEX IF EXISTS questions_pool_position_unique');
    }

    /**
     * Fail with the offending keys instead of a bare constraint violation.
     *
     * Auf einer Bestandsdatenbank kann bereits ein Duplikat liegen — dann
     * scheitert `CREATE UNIQUE INDEX` mit einer Meldung, die die betroffene
     * Zeile nicht nennt. Diese Vorprüfung nennt sie, damit der Betreiber weiß,
     * welche Frage er von Hand umnummerieren muss.
     *
     * @throws RuntimeException
     */
    protected function guardAgainstExistingDuplicates(): void
    {
        $inline = DB::table('questions')
            ->whereNotNull('lesson_id')
            ->select('lesson_id', 'position')
            ->groupBy('lesson_id', 'position')
            ->havingRaw('count(*) > 1')
            ->get()
            ->map(fn (object $row): string => "lesson_id={$row->lesson_id}, position={$row->position}");

        // `learning_path_id IS NULL` bleibt außen vor: solche Waisen (Pfad
        // gelöscht) sind auch für den Index kein Duplikat, weil NULL dort als
        // von allem verschieden gilt.
        $pool = DB::table('questions')
            ->whereNull('lesson_id')
            ->whereNotNull('learning_path_id')
            ->select('learning_path_id', 'position')
            ->groupBy('learning_path_id', 'position')
            ->havingRaw('count(*) > 1')
            ->get()
            ->map(fn (object $row): string => "learning_path_id={$row->learning_path_id}, position={$row->position}");

        $conflicts = $inline->merge($pool);

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'Mehrfach belegte Import-Schlüssel in `questions` — bitte vor dieser '
                .'Migration umnummerieren: '.$conflicts->implode('; ')
            );
        }
    }
};
