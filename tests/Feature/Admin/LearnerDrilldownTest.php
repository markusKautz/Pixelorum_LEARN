<?php

use App\Actions\Analytics\LearnerProgressReport;
use App\Enums\PathStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $this->learner = User::factory()->apprentice($this->group)->create(['name' => 'Anna Auer']);
});

/**
 * Record a completed attempt in which the learner answered the given question
 * wrong the given number of times (one attempt per wrong answer).
 */
function answerWrong(User $learner, Quiz $quiz, Question $question, int $times): void
{
    foreach (range(1, $times) as $ignored) {
        $attempt = QuizAttempt::factory()->failed()->create([
            'user_id' => $learner->id,
            'quiz_id' => $quiz->id,
        ]);

        QuestionResponse::factory()->for($attempt, 'quizAttempt')->for($question)->create([
            'is_correct' => false,
        ]);
    }
}

/**
 * Counterpart to answerWrong() — nötig, seit die Rangfolge über die Quote
 * läuft: erst die richtigen Antworten machen aus „4× falsch" eine Quote.
 */
function answerRight(User $learner, Quiz $quiz, Question $question, int $times): void
{
    foreach (range(1, $times) as $ignored) {
        $attempt = QuizAttempt::factory()->passed()->create([
            'user_id' => $learner->id,
            'quiz_id' => $quiz->id,
        ]);

        QuestionResponse::factory()->for($attempt, 'quizAttempt')->for($question)->create([
            'is_correct' => true,
        ]);
    }
}

test('an apprentice is forbidden on the drilldown', function () {
    $this->actingAs($this->learner)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $this->learner]))
        ->assertForbidden();

    Livewire::actingAs($this->learner)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertForbidden();
});

test('an admin sees the drilldown of a group member', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $this->learner]))
        ->assertOk()
        ->assertSee('Anna Auer');
});

test('an apprentice of another group yields a 404', function () {
    // scopeBindings: der Lehrling muss Mitglied *dieser* Gruppe sein.
    $otherGroup = Team::factory()->create();
    $stranger = User::factory()->apprentice($otherGroup)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $stranger]))
        ->assertNotFound();
});

test('the path list includes untouched paths as not started', function () {
    $started = LearningPath::factory()->published()->verified()->create(['position' => 1]);
    $untouched = LearningPath::factory()->published()->verified()->create(['position' => 2]);

    $lessons = Lesson::factory()->for($started)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();
    Lesson::factory()->for($untouched)->create();

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertOk()
        ->assertSee('In Arbeit')
        ->assertSee('Nicht begonnen')
        ->instance()
        ->report;

    expect($report->paths)->toHaveCount(2)
        ->and($report->paths[0]->path->id)->toBe($started->id)
        ->and($report->paths[0]->percent)->toBe(50)
        ->and($report->paths[1]->path->id)->toBe($untouched->id)
        ->and($report->paths[1]->percent)->toBe(0);
});

test('the quiz history lists the newest attempt first', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->failed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'completed_at' => now()->subDays(3),
    ]);

    $latest = QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'completed_at' => now()->subDay(),
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report->attempts)->toHaveCount(2)
        ->and($report->attempts->first()->id)->toBe($latest->id)
        ->and($report->attempts->first()->passed)->toBeTrue();
});

test('the quiz history shows the achieved percentage of each attempt', function () {
    // Der Test darüber prüft die Reihenfolge am Report-Objekt und ließe eine
    // falsche Prozentzahl in der Tabelle durchgehen. Diese Zeile ist die
    // einzige der vier Anzeigestellen von QuizAttempt::$percent, die bisher
    // gerendert, aber nicht geprüft wurde.
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'score' => 5,
        'max_score' => 8,
        'passed' => false,
        'completed_at' => now()->subDay(),
    ]);

    // 5/8 sind 62,5 % — abgerundet 62, nicht gerundet 63 (Entscheidung 7).
    Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertOk()
        ->assertSee('5 von 8 (62 %)');
});

test('a running attempt does not show up in the history', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report->attempts)->toBeEmpty();
});

test('weak spots are ranked by how often the question was answered wrong', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $often = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Was ist ein Subnetz?']);
    $once = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Was ist DNS?']);

    answerWrong($this->learner, $quiz, $often, times: 3);
    answerWrong($this->learner, $quiz, $once, times: 1);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertOk()
        ->assertSee('Was ist ein Subnetz?')
        ->instance()
        ->report;

    expect($report->weakSpots)->toHaveCount(2)
        ->and($report->weakSpots[0]->question->id)->toBe($often->id)
        ->and($report->weakSpots[0]->wrongCount)->toBe(3)
        ->and($report->weakSpots[0]->pathTitle)->toBe($path->title)
        ->and($report->weakSpots[1]->question->id)->toBe($once->id)
        ->and($report->weakSpots[1]->wrongCount)->toBe(1);
});

test('a withdrawn path takes its weak spots out of the evaluation', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $question = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Was ist ein Subnetz?']);

    answerWrong($this->learner, $quiz, $question, times: 3);

    $report = fn () => Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report()->weakSpots)->toHaveCount(1);

    // Derselbe Grundsatz wie bei den Versuchen: zurückgezogen heißt „nicht in
    // der Wertung" — sonst zeigte die Auswertung Lücken zu Stoff, den der
    // Lehrling gerade gar nicht bearbeiten soll.
    $path->update(['status' => PathStatus::Draft]);

    expect($report()->weakSpots)->toBeEmpty();

    // Gelöscht wird dabei nichts: Freigeben stellt den Befund wieder her.
    $path->update(['status' => PathStatus::Published]);

    expect($report()->weakSpots)->toHaveCount(1);
});

test('a pool question without a path stays a weak spot', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    // `learning_path_id` ist laut Konzept d) eine bloße Herkunftsangabe und
    // darf leer sein — so eine Frage hat keinen Pfad, der wegfallen könnte.
    $pooled = Question::factory()->singleChoice()->create(['prompt' => 'Frage ohne Herkunft?']);

    answerWrong($this->learner, $quiz, $pooled, times: 2);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report->weakSpots)->toHaveCount(1)
        ->and($report->weakSpots[0]->question->id)->toBe($pooled->id)
        ->and($report->weakSpots[0]->pathTitle)->toBeNull();
});

test('a correctly answered question is no weak spot', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $question = Question::factory()->singleChoice()->for($path)->create();

    $attempt = QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    QuestionResponse::factory()->for($attempt, 'quizAttempt')->for($question)->correct()->create();

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report->weakSpots)->toBeEmpty();
});

test('answers of another apprentice never enter the drilldown', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $question = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Fremde Schwachstelle?']);
    $other = User::factory()->apprentice($this->group)->create(['name' => 'Bruno Berger']);

    answerWrong($other, $quiz, $question, times: 4);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertOk()
        ->assertDontSee('Fremde Schwachstelle?')
        ->instance()
        ->report;

    expect($report->weakSpots)->toBeEmpty()
        ->and($report->attempts)->toBeEmpty();
});

test('the weak spots stop at the top ten, without an expander', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    foreach (range(1, 12) as $index) {
        $question = Question::factory()->singleChoice()->for($path)->create([
            'prompt' => "Frage Nummer {$index}?",
        ]);

        // Absteigende Fehlerzahl, damit die Reihenfolge feststeht.
        answerWrong($this->learner, $quiz, $question, times: 13 - $index);
    }

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertOk()
        ->assertSee('Frage Nummer 10?')
        ->assertDontSee('Frage Nummer 11?')
        // Kein Aufklappen mehr: der Schwanz hinter den größten Baustellen ist
        // weder für den Ausbildner noch für den Lehrling handlungsleitend.
        ->assertDontSee('weitere anzeigen');

    // Und schon die Abfrage holt nur zehn — nicht erst die Ansicht schneidet ab.
    expect($component->instance()->report->weakSpots)
        ->toHaveCount(LearnerProgressReport::TOP_WEAK_SPOTS);
});

test('weak spots are ranked by error rate, not by absolute mistakes', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $stubborn = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Hartnäckig?']);
    $slip = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Ausrutscher?']);

    // 4 von 5 falsch — eine echte Lücke.
    answerWrong($this->learner, $quiz, $stubborn, times: 4);
    answerRight($this->learner, $quiz, $stubborn, times: 1);

    // 4 von 20 falsch — dieselbe Fehlerzahl, aber weitgehend beherrscht.
    answerWrong($this->learner, $quiz, $slip, times: 4);
    answerRight($this->learner, $quiz, $slip, times: 16);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report->weakSpots[0]->question->id)->toBe($stubborn->id)
        ->and($report->weakSpots[0]->wrongCount)->toBe(4)
        ->and($report->weakSpots[0]->askedCount)->toBe(5)
        // Gleiche Fehlerzahl, niedrigere Quote — also dahinter.
        ->and($report->weakSpots[1]->question->id)->toBe($slip->id)
        ->and($report->weakSpots[1]->wrongCount)->toBe(4);
});

test('a single wrong answer does not outrank a well evidenced gap', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $gap = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Echte Lücke?']);
    $once = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Einmal daneben?']);

    // 8 von 8 falsch gegen 1 von 1. Die zeitgewichtete Quote belohnt zwar
    // gelernte Fragen, aber eine noch nie richtig beantwortete Lücke bleibt
    // vor einem einzelnen Ausrutscher.
    answerWrong($this->learner, $quiz, $gap, times: 8);
    answerWrong($this->learner, $quiz, $once, times: 1);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report;

    expect($report->weakSpots[0]->question->id)->toBe($gap->id)
        ->and($report->weakSpots[1]->question->id)->toBe($once->id);
});

test('a learned question drops out of the top weak spots', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    // Zehn andere Schwachstellen mit jeweils einer einzigen Fehlantwort halten
    // die Liste voll — genug, damit eine herabfallende Frage hinter Top 10
    // verschwindet.
    $fillers = [];

    foreach (range(1, 10) as $index) {
        $fillers[] = Question::factory()->singleChoice()->for($path)->create([
            'prompt' => "Füller {$index}?",
        ]);
    }

    $learned = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Gelernt?']);

    // Zuerst 10× falsch — das ist Platz 1.
    answerWrong($this->learner, $quiz, $learned, times: 10);

    foreach ($fillers as $filler) {
        answerWrong($this->learner, $quiz, $filler, times: 1);
    }

    $reportBefore = app(LearnerProgressReport::class)->weakSpots($this->learner, limit: LearnerProgressReport::TOP_WEAK_SPOTS);
    expect($reportBefore)->toHaveCount(10)
        ->and(collect($reportBefore)->first(fn ($s) => $s->question->id === $learned->id))->not->toBeNull();

    // Vier richtige Antworten am Stück senken den gewichteten Score stark.
    answerRight($this->learner, $quiz, $learned, times: 4);

    $reportAfter = app(LearnerProgressReport::class)->weakSpots($this->learner, limit: LearnerProgressReport::TOP_WEAK_SPOTS);
    expect(collect($reportAfter)->first(fn ($s) => $s->question->id === $learned->id))->toBeNull();
});

test('a relapse lifts a learned question back up', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $learned = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Rückfall?']);
    $rival = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Rivale?']);

    // 10× falsch, dann 4× richtig — die Frage sinkt unter den Rivalen.
    answerWrong($this->learner, $quiz, $learned, times: 10);
    answerRight($this->learner, $quiz, $learned, times: 4);

    // Der Rivale hat eine niedrigere Quote (1/3), liegt aber über dem
    // gelernten Wert, solange kein neuer Fehler dazukommt.
    answerWrong($this->learner, $quiz, $rival, times: 1);
    answerRight($this->learner, $quiz, $rival, times: 2);

    $reportBefore = app(LearnerProgressReport::class)->weakSpots($this->learner, limit: LearnerProgressReport::TOP_WEAK_SPOTS);
    expect(collect($reportBefore)->first()->question->id)->toBe($rival->id);

    // Eine frische falsche Antwort hebt die gelernte Frage wieder an die Spitze.
    answerWrong($this->learner, $quiz, $learned, times: 1);

    $reportAfter = app(LearnerProgressReport::class)->weakSpots($this->learner, limit: LearnerProgressReport::TOP_WEAK_SPOTS);
    expect(collect($reportAfter)->first()->question->id)->toBe($learned->id);
});

test('the drilldown stays within its query budget', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();
    Lesson::factory()->for($path)->count(3)->create();

    foreach (range(1, 5) as $index) {
        $question = Question::factory()->singleChoice()->for($path)->create();

        answerWrong($this->learner, $quiz, $question, times: 2);
    }

    $url = route('admin.groups.learner', ['team' => $this->group, 'member' => $this->learner]);

    $this->actingAs($this->admin)->get($url)->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->admin)->get($url)->assertOk();

    expect($queries)->toBeLessThan(20);
});

test('attempts are aggregated per quiz in the drilldown', function () {
    $pathA = LearningPath::factory()->published()->verified()->create();
    $pathB = LearningPath::factory()->published()->verified()->create();
    $quizA = Quiz::factory()->pathFinal($pathA)->published()->create();
    $quizB = Quiz::factory()->pathFinal($pathB)->published()->create();

    // Quiz A: zwei Versuche, der spätere ist besser.
    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quizA->id,
        'score' => 3,
        'max_score' => 10,
        'passed' => false,
        'completed_at' => now()->subDays(2),
    ]);
    $bestA = QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quizA->id,
        'score' => 8,
        'max_score' => 10,
        'passed' => true,
        'completed_at' => now()->subDay(),
    ]);

    // Quiz B: ein einziger Versuch.
    $bestB = QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quizB->id,
        'score' => 5,
        'max_score' => 10,
        'passed' => false,
        'completed_at' => now()->subHours(6),
    ]);

    $rows = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report
        ->attemptsByQuiz();

    // Reihenfolge: Quiz B war zuletzt aktiv, also oben.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->quiz->id)->toBe($quizB->id)
        ->and($rows[0]->attemptCount)->toBe(1)
        ->and($rows[0]->bestAttempt->id)->toBe($bestB->id)
        ->and($rows[1]->quiz->id)->toBe($quizA->id)
        ->and($rows[1]->attemptCount)->toBe(2)
        ->and($rows[1]->bestAttempt->id)->toBe($bestA->id);
});

test('the rows are ordered by the latest attempt, not by the best one', function () {
    $pathA = LearningPath::factory()->published()->verified()->create();
    $pathB = LearningPath::factory()->published()->verified()->create();
    $quizA = Quiz::factory()->pathFinal($pathA)->published()->create();
    $quizB = Quiz::factory()->pathFinal($pathB)->published()->create();

    // Quiz A: guter Versuch am Montag, misslungener Wiederholungsversuch heute.
    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quizA->id,
        'score' => 9,
        'max_score' => 10,
        'passed' => true,
        'completed_at' => now()->subDays(3),
    ]);
    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quizA->id,
        'score' => 2,
        'max_score' => 10,
        'passed' => false,
        'completed_at' => now()->subHour(),
    ]);

    // Quiz B liegt dazwischen: jünger als A's bester, älter als A's letzter.
    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quizB->id,
        'score' => 5,
        'max_score' => 10,
        'passed' => false,
        'completed_at' => now()->subDays(2),
    ]);

    $rows = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report
        ->attemptsByQuiz();

    // Sortiert nach dem besten Versuch stünde B oben — zuletzt gearbeitet hat
    // der Lehrling aber an A.
    expect($rows[0]->quiz->id)->toBe($quizA->id)
        ->and($rows[0]->bestAttempt->score)->toBe(9)
        ->and($rows[1]->quiz->id)->toBe($quizB->id);
});

test('the best attempt breaks ties by score then recency', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    // Gleicher Prozentwert (50 %), aber unterschiedlicher Score.
    $olderBetterScore = QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'score' => 5,
        'max_score' => 10,
        'passed' => false,
        'completed_at' => now()->subDays(2),
    ]);

    QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'score' => 1,
        'max_score' => 2,
        'passed' => false,
        'completed_at' => now()->subDay(),
    ]);

    $rows = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->instance()
        ->report
        ->attemptsByQuiz();

    expect($rows[0]->bestAttempt->id)->toBe($olderBetterScore->id);
});

test('everything but the path table is wrapped in the accordion', function () {
    $path = LearningPath::factory()->published()->verified()->create(['title' => 'Netzwerke']);
    Lesson::factory()->for($path)->create();

    $html = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.learner', ['team' => $this->group, 'member' => $this->learner])
        ->assertOk()
        ->html();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $insideAccordion = fn (string $test): bool => (bool) $xpath->query(
        '//*[@data-test="learner-accordion"]//*[@data-test="'.$test.'"]'
    )->length;

    // Gegenstück zur Gruppen-Auswertung: dort bleibt die Lehrlings-Tabelle
    // offen, hier die Pfad-Tabelle (Retest 2026-07-29).
    expect($insideAccordion('learner-attempts-table'))->toBeTrue()
        ->and($insideAccordion('learner-weak-spots-table'))->toBeTrue()
        ->and($insideAccordion('learner-paths-table'))->toBeFalse()
        ->and((bool) $xpath->query('//*[@data-test="learner-paths-table"]')->length)->toBeTrue();
});
