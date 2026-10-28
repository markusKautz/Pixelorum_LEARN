<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein endgültig gelöschter Lernpfad nimmt seine Fragen mit (S6.4, E3).
 *
 * Bis hierher war `questions.learning_path_id` auf `nullOnDelete()` gesetzt:
 * Die Frage überlebte ihren Pfad als herkunftslose Pool-Frage. Das war richtig,
 * solange es kein endgültiges Löschen gab — der Papierkorb macht daraus einen
 * bewussten zweiten Schritt, und wer ihn geht, will den Pfad samt Inhalt los
 * sein, nicht ein Feld verwaister Fragen zurückbehalten.
 *
 * `lesson_id` bleibt bewusst `nullOnDelete()`: Wer eine einzelne Lektion
 * löscht, verliert ihren Zwischen-Check nicht, die Frage wandert in den Pool.
 * Ebenso löscht das Löschen eines Quiz weiterhin **keine** Fragen, sondern nur
 * seine Pivots, Versuche und Antworten. Die Besitzsemantik ist also: Der Pfad
 * besitzt seine Fragen, Lektion und Quiz verweisen nur auf sie.
 *
 * **SQLite:** Die Engine kann Fremdschlüssel nicht ändern, Laravel baut die
 * Tabelle deshalb neu auf und legt die Indizes anschließend wieder an — aus
 * `Schema::getIndexes()`, das keine `WHERE`-Klausel kennt. Die beiden
 * partiellen Unique-Indizes aus `add_import_key_unique_indexes_to_questions_table`
 * kämen dabei als vollständige Indizes zurück und verböten ab da, was sie
 * gerade erlauben sollen. Sie werden deshalb vorher entfernt und danach in
 * ihrer ursprünglichen Form neu angelegt.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->withoutPartialImportKeyIndexes(function (): void {
            Schema::table('questions', function (Blueprint $table): void {
                $table->dropForeign(['learning_path_id']);
                $table->foreign('learning_path_id')
                    ->references('id')
                    ->on('learning_paths')
                    ->cascadeOnDelete();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->withoutPartialImportKeyIndexes(function (): void {
            Schema::table('questions', function (Blueprint $table): void {
                $table->dropForeign(['learning_path_id']);
                $table->foreign('learning_path_id')
                    ->references('id')
                    ->on('learning_paths')
                    ->nullOnDelete();
            });
        });
    }

    /**
     * Run the callback with the partial import-key indexes taken off the table.
     *
     * @param  callable(): void  $callback
     */
    protected function withoutPartialImportKeyIndexes(callable $callback): void
    {
        DB::statement('DROP INDEX IF EXISTS questions_inline_position_unique');
        DB::statement('DROP INDEX IF EXISTS questions_pool_position_unique');

        $callback();

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
};
