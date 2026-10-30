<?php

namespace Database\Seeders;

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Beispiel-Inhalte für die lokale Entwicklung. Echte Lernpfade kommen über
 * den Admin-Upload (Lernpfad importieren).
 */
class DemoContentSeeder extends Seeder
{
    /**
     * Seed a complete demo path plus a draft path.
     */
    public function run(): void
    {
        // Wiederholbar wie die Dev-Zugänge: Steht der Demo-Pfad schon da, auch
        // gelöscht, liefe ein zweiter Lauf in den Unique-Index auf `slug`.
        if (LearningPath::withTrashed()->where('slug', 'grundlagen-der-programmierung')->exists()) {
            return;
        }

        // Aller Demo-Content gehört einem Admin: sonst legten die verified()-
        // und created_by-Defaults der Factories je einen eigenen Admin mit
        // Zufalls-E-Mail an, die in der Benutzerliste nur verwirren.
        $admin = User::where('is_admin', true)->first()
            ?? User::factory()->admin()->create();

        $this->seedFreigegebenenPfad($admin);

        // Für Teilnehmer_innen unsichtbar — macht den visibleToLearners-Scope im UI
        // demonstrierbar.
        LearningPath::factory()
            ->has(Lesson::factory()->count(2)->has(LessonBlock::factory()->count(2), 'blocks'))
            ->create([
                'title' => 'Entwurf: Datenbanken vertiefen',
                'slug' => 'entwurf-datenbanken-vertiefen',
                'position' => 2,
            ]);
    }

    /**
     * Seed the published and verified demo path.
     *
     * Reihenfolge Pfad → Lektionen/Fragen → Quiz ist Pflicht: der
     * path_final-Guard verlangt beim Speichern einen existierenden Pfad.
     */
    protected function seedFreigegebenenPfad(User $admin): void
    {
        $pfad = LearningPath::factory()->published()->verified()->create([
            'verified_by' => $admin->id,
            'title' => 'Grundlagen der Programmierung',
            'slug' => 'grundlagen-der-programmierung',
            'summary' => 'Variablen, Kontrollstrukturen und Funktionen — die Basis für alle weiteren Lernpfade.',
            'objectives' => [
                'Variablen und Datentypen unterscheiden können',
                'Verzweigungen und Schleifen lesen und schreiben',
                'Eine Funktion mit Parametern und Rückgabewert bauen',
            ],
            'position' => 1,
            'difficulty' => 'einsteiger',
            'estimated_minutes' => 60,
        ]);

        // Ohne Zuweisung sieht den Pfad niemand (#9, E12): wie „Allen Gruppen
        // zuweisen“ an die laufenden Gruppen, also an die Dev-Gruppe des
        // DatabaseSeeders.
        $pfad->teams()->attach(Team::query()->groups()->active()->pluck('id')->all());

        collect([
            [
                'titel' => 'Variablen und Datentypen',
                'blockStates' => ['callout', 'glossary'],
                // Fester Text statt Faker-Latein: er enthält die Begriffe des
                // Glossar-Blocks, damit die Tooltip-Verlinkung des Readers im
                // Demo-Inhalt überhaupt sichtbar wird.
                'intro' => <<<'MARKDOWN'
                    Eine Variable ist ein benannter Platzhalter für einen Wert im Speicher.
                    Beim **Kompilieren** prüft der Übersetzer, ob die Typen zusammenpassen —
                    ein Tippfehler fällt dadurch schon vor dem Programmstart auf.

                    Erst zur Laufzeit auftretende Probleme heißen dagegen Laufzeitfehler und
                    lassen sich nur durch Testen finden.
                    MARKDOWN,
            ],
            ['titel' => 'Verzweigungen und Schleifen', 'blockStates' => ['codeSnippet', 'sectionBreak', 'accordion']],
            ['titel' => 'Funktionen', 'blockStates' => ['diagram', 'media']],
        ])->each(function (array $daten, int $index) use ($pfad) {
            $lektion = Lesson::factory()->for($pfad)->withSourceReference()->create([
                'title' => $daten['titel'],
                'position' => $index + 1,
            ]);

            // Jede Lektion beginnt mit Fließtext, danach folgen die
            // strukturierten Blöcke — so ist jeder Block-Typ einmal vertreten.
            LessonBlock::factory()->for($lektion)->create(array_filter([
                'position' => 1,
                'content' => isset($daten['intro']) ? ['body' => $daten['intro']] : null,
            ]));

            foreach ($daten['blockStates'] as $blockIndex => $state) {
                LessonBlock::factory()->for($lektion)->{$state}()->create([
                    'position' => $blockIndex + 2,
                ]);
            }

            // Zwischen-Check an der Lektion (Verwendung 1 aus Konzept d).
            Question::factory()
                ->asLessonCheck($lektion)
                ->withSourceReference()
                ->singleChoice()
                ->create(['position' => 1]);
        });

        $poolFragen = collect();

        foreach (range(1, 8) as $position) {
            $state = match ($position % 3) {
                0 => 'boolean',
                1 => 'singleChoice',
                default => 'multipleChoice',
            };

            $poolFragen->push(
                Question::factory()
                    ->for($pfad, 'learningPath')
                    ->withSourceReference()
                    ->{$state}()
                    ->create(['position' => $position])
            );
        }

        $abschlussQuiz = Quiz::factory()->pathFinal($pfad)->published()->create([
            'created_by' => $admin->id,
            'title' => 'Abschluss-Quiz: Grundlagen der Programmierung',
            'description' => 'Prüfe, ob du die Grundlagen sicher beherrschst.',
        ]);

        $abschlussQuiz->questions()->sync(
            $poolFragen->mapWithKeys(fn (Question $frage, int $index) => [
                $frage->id => ['position' => $index + 1],
            ])->all()
        );

        $freiesQuiz = Quiz::factory()->published()->create([
            'created_by' => $admin->id,
            'title' => 'Gemischte Wiederholung',
            'description' => 'Ein frei zusammengestelltes Quiz aus dem Fragen-Pool.',
        ]);

        $freiesQuiz->questions()->sync(
            $poolFragen->take(4)->mapWithKeys(fn (Question $frage, int $index) => [
                $frage->id => ['position' => $index + 1],
            ])->all()
        );
    }
}
