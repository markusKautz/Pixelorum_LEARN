<?php

namespace App\Actions\Content;

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Schreibt eine geprüfte Content-JSON als neuen Lernpfad in die Datenbank.
 *
 * Aufrufer ist der Admin-Upload. Er legt über `create()` ausschließlich neue
 * Pfade an; Korrekturen an einem importierten Pfad laufen im Admin-UI.
 *
 * Der `sources`-Block (ab Schema-Version 2) landet in derselben Transaktion in
 * `content_sources`, siehe `SyncContentSources`. Eine Datei der Version 1
 * bringt keinen Block mit; ihre Quellen müssen dann schon bekannt sein.
 */
class ImportLearningPath
{
    public function __construct(private readonly SyncContentSources $syncSources = new SyncContentSources) {}

    /**
     * Der Riegel, unter dem jede Änderung der Pfadreihenfolge läuft (E9).
     *
     * Anlegen, Sortieren, Wiederherstellen und der Upload berechnen alle
     * `max+1` beziehungsweise verdichtete Positionen. Ohne gemeinsamen Riegel
     * lesen zwei gleichzeitige Vorgänge dasselbe Maximum und vergeben dieselbe
     * Position.
     */
    public const ORDER_LOCK = 'learning-path-order';

    /**
     * Create a brand new learning path from a validated content file (E1).
     *
     * Legt ausschließlich an. Ist der Slug schon vergeben — auch durch eine
     * soft-gelöschte Zeile —, entsteht eine Kopie mit zufälligem Anhang am Slug
     * und „(Kopie)“ im Titel, statt einen bestehenden Pfad zu überschreiben
     * oder einen gelöschten wiederzubeleben. Dadurch entsteht nie eine
     * Zuordnung zwischen JSON-Position und bestehender Datenbankzeile, und der
     * Upload braucht keinen stabilen Lektionsschlüssel.
     *
     * Status und Reviewstatus setzt der Import selbst, unabhängig vom
     * Dateiinhalt (E6): Freigeben und Veröffentlichen bleiben Handgriffe im
     * Admin-UI, damit das Grounding-Gate nicht über eine Datei umgangen wird.
     *
     * @param  array<string, mixed>  $daten
     *
     * @throws LockTimeoutException wenn die Reihenfolge blockiert bleibt
     */
    public function create(array $daten): LearningPath
    {
        return Cache::lock(self::ORDER_LOCK, 10)->block(5, fn (): LearningPath => DB::transaction(
            function () use ($daten): LearningPath {
                $slug = (string) $daten['slug'];
                $title = (string) $daten['title'];

                // Innerhalb von Riegel und Transaktion: Die Vorschau im
                // Upload-Component kündigt die Kopie nur an, entschieden wird
                // hier. Letzter Riegel bleibt der Unique-Index auf `slug`.
                if ($this->isSlugTaken($slug)) {
                    $slug = $this->freeCopySlug($slug);
                    $title = $this->copyTitle($title);
                }

                $this->syncSources->handle($daten['sources'] ?? []);

                $pfad = LearningPath::create([
                    'slug' => $slug,
                    'title' => $title,
                    'summary' => $daten['summary'] ?? null,
                    'objectives' => $daten['objectives'] ?? [],
                    'position' => $this->resolvePosition(
                        isset($daten['position']) ? (int) $daten['position'] : null,
                    ),
                    'difficulty' => $daten['difficulty'] ?? null,
                    'estimated_minutes' => $daten['estimated_minutes'] ?? null,
                    'pass_threshold' => $daten['pass_threshold'] ?? 80,
                    'status' => PathStatus::Draft,
                    'review_status' => ReviewStatus::Unreviewed,
                ]);

                foreach ($daten['lessons'] ?? [] as $lektionsDaten) {
                    $this->importiereLektion($lektionsDaten, $pfad);
                }

                foreach ($daten['questions'] ?? [] as $frageDaten) {
                    $this->importiereFrage($frageDaten, $pfad, null);
                }

                if (isset($daten['quiz'])) {
                    $this->importiereAbschlussQuiz([...$daten['quiz'], 'status' => 'draft'], $pfad);
                }

                return $pfad;
            },
        ));
    }

    /**
     * Whether a path, live or soft-deleted, already holds this slug.
     *
     * withTrashed, weil der Unique-Index auf `slug` gelöschte Zeilen
     * einschließt.
     */
    public function isSlugTaken(string $slug): bool
    {
        return LearningPath::withTrashed()->where('slug', $slug)->exists();
    }

    /**
     * A free slug for the copy: the original plus four random characters.
     *
     * Der Anhang besteht aus `[a-z0-9]` und erfüllt damit die Slug-Regel des
     * Validators. Der Stamm wird gekürzt, damit das Ergebnis in 255 Zeichen
     * passt.
     */
    protected function freeCopySlug(string $slug): string
    {
        $stem = rtrim(mb_substr($slug, 0, 250), '-');

        do {
            $candidate = $stem.'-'.Str::lower(Str::random(4));
        } while ($this->isSlugTaken($candidate));

        return $candidate;
    }

    /**
     * The title for the copy, marked so that both paths stay apart in the list.
     */
    protected function copyTitle(string $title): string
    {
        $suffix = ' '.__('(copy)');

        return mb_substr($title, 0, 255 - mb_strlen($suffix)).$suffix;
    }

    /**
     * The position a fresh path takes: the one from the file if it is free (E5).
     *
     * Die Position stammt aus der Pfadlandkarte und stimmt im Regelfall. Hält
     * sie aber schon ein lebender Pfad, landet der Import am Ende statt eine
     * Position doppelt zu vergeben. Gelöschte Pfade blockieren nichts: Sie
     * kämen ohnehin über `max+1` zurück.
     */
    public function resolvePosition(?int $desired): int
    {
        if ($desired !== null && ! LearningPath::where('position', $desired)->exists()) {
            return $desired;
        }

        return ((int) LearningPath::max('position')) + 1;
    }

    /**
     * Import one lesson including its blocks and inline checks.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function importiereLektion(array $daten, LearningPath $pfad): void
    {
        $lektion = Lesson::updateOrCreate(
            ['learning_path_id' => $pfad->id, 'position' => $daten['position']],
            [
                'title' => $daten['title'],
                'source_reference' => $daten['source_reference'] ?? null,
            ]
        );

        foreach ($daten['blocks'] ?? [] as $blockDaten) {
            LessonBlock::updateOrCreate(
                ['lesson_id' => $lektion->id, 'position' => $blockDaten['position']],
                [
                    'type' => $blockDaten['type'],
                    'content' => $blockDaten['content'],
                ]
            );
        }

        // An einem Block hängen keine Lernendendaten, er darf ohne Rückfrage weg.
        $lektion->blocks()
            ->whereNotIn('position', $this->positionen($daten['blocks'] ?? []))
            ->delete();

        foreach ($daten['questions'] ?? [] as $frageDaten) {
            $this->importiereFrage($frageDaten, $pfad, $lektion);
        }

        $this->entferneVerwaisteFragen(
            $lektion->questions(),
            $this->positionen($daten['questions'] ?? []),
            "Lektion {$lektion->position} von '{$pfad->slug}'",
        );
    }

    /**
     * Positions of the entries in a content list.
     *
     * @param  list<array<string, mixed>>  $eintraege
     * @return list<int>
     */
    protected function positionen(array $eintraege): array
    {
        return array_map(
            static fn (array $eintrag): int => (int) $eintrag['position'],
            $eintraege,
        );
    }

    /**
     * Remove questions that vanished from the content file.
     *
     * Eine Frage mit Antworten bleibt stehen und wird gemeldet, weil
     * `question_responses` kaskadierend gelöscht würde.
     *
     * @param  HasMany<Question, covariant \Illuminate\Database\Eloquent\Model>|Builder<Question>  $vorhandene
     * @param  list<int>  $positionen
     */
    protected function entferneVerwaisteFragen(mixed $vorhandene, array $positionen, string $herkunft): void
    {
        foreach ($vorhandene->whereNotIn('position', $positionen)->get() as $frage) {
            if (QuestionResponse::where('question_id', $frage->id)->exists()) {
                $this->melde(
                    "Frage {$frage->position} im {$herkunft} steht nicht mehr in der Inhaltsdatei, "
                    .'hat aber Antworten von Lernenden und bleibt deshalb stehen.'
                );

                continue;
            }

            $frage->delete();
        }
    }

    /**
     * Report a finding that needs a human decision.
     */
    protected function melde(string $meldung): void
    {
        logger()->warning($meldung);
    }

    /**
     * Import one question with its options.
     *
     * Zwischen-Checks werden über ihre Lektion identifiziert, Pool-Fragen über
     * den Pfad — sonst kollidierten beide auf demselben Positions-Schlüssel.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function importiereFrage(array $daten, LearningPath $pfad, ?Lesson $lektion): Question
    {
        $schluessel = $lektion !== null
            ? ['lesson_id' => $lektion->id, 'position' => $daten['position']]
            : ['learning_path_id' => $pfad->id, 'lesson_id' => null, 'position' => $daten['position']];

        $frage = Question::updateOrCreate($schluessel, [
            'learning_path_id' => $pfad->id,
            'lesson_id' => $lektion?->id,
            'type' => $daten['type'],
            'prompt' => $daten['prompt'],
            'explanation' => $daten['explanation'],
            'source_reference' => $daten['source_reference'] ?? null,
        ]);

        foreach ($daten['options'] ?? [] as $optionsDaten) {
            QuestionOption::updateOrCreate(
                ['question_id' => $frage->id, 'position' => $optionsDaten['position']],
                [
                    'label' => $optionsDaten['label'],
                    'is_correct' => $optionsDaten['is_correct'] ?? false,
                ]
            );
        }

        // `question_responses.selected_option_ids` ist eine JSON-Spalte ohne
        // Fremdschlüssel, eine entfernte Option reißt also nichts mit. Alte
        // Antworten auf eine geänderte Frage sind ohnehin nicht mehr
        // vergleichbar.
        $frage->options()
            ->whereNotIn('position', $this->positionen($daten['options'] ?? []))
            ->delete();

        return $frage;
    }

    /**
     * Import the path's final quiz and its question order.
     *
     * @param  array<string, mixed>  $daten
     *
     * @throws RuntimeException wenn eine question_position keine Pool-Frage trifft
     */
    protected function importiereAbschlussQuiz(array $daten, LearningPath $pfad): void
    {
        $quiz = Quiz::firstOrNew([
            'learning_path_id' => $pfad->id,
            'kind' => QuizKind::PathFinal,
        ]);

        $quiz->fill([
            'title' => $daten['title'],
            'description' => $daten['description'] ?? null,
            'pass_threshold' => $daten['pass_threshold'] ?? 80,
        ]);

        if (! $quiz->exists) {
            $quiz->status = $daten['status'] ?? 'draft';
        }

        $quiz->save();

        $pivot = [];

        foreach ($daten['question_positions'] ?? [] as $index => $fragePosition) {
            $frage = Question::where('learning_path_id', $pfad->id)
                ->whereNull('lesson_id')
                ->where('position', $fragePosition)
                ->first();

            // Ein Tippfehler in der JSON darf kein Quiz mit stillschweigend
            // fehlender Frage hinterlassen — die Transaktion rollt alles zurück.
            if ($frage === null) {
                throw new RuntimeException(
                    "Abschluss-Quiz von '{$pfad->slug}': question_position {$fragePosition} "
                    .'verweist auf keine Pool-Frage dieses Pfades.'
                );
            }

            $pivot[$frage->id] = ['position' => $index + 1];
        }

        $quiz->questions()->sync($pivot);
    }
}
