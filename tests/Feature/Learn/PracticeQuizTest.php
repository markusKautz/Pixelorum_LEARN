<?php

use App\Actions\Learning\BuildPracticeQuiz;
use App\Enums\PathStatus;
use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Sprint 5.5, Feature 2 — das persönliche Übungsquiz (Variante B): genau ein
 * wiederverwendbarer Behälter je Teilnehmer_in, dessen Fragen-Pivot jede Sitzung
 * neu befüllt. Vergangene Versuche bleiben davon unberührt, weil sie ihre
 * eigene Fragenliste festhalten.
 */

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
});

/**
 * Create a visible path carrying the given number of answerable questions.
 */
function practicePath(int $questionCount, string $title = 'Netzwerke'): LearningPath
{
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => $title]);

    foreach (range(1, $questionCount) as $index) {
        $question = Question::factory()->for($path)->create([
            'type' => QuestionType::Single,
            'prompt' => "{$title} Frage {$index}?",
        ]);

        QuestionOption::factory()->for($question)->create(['is_correct' => true, 'position' => 1]);
        QuestionOption::factory()->for($question)->create(['is_correct' => false, 'position' => 2]);
    }

    return $path;
}

function buildPractice(User $learner, array $pathIds, int $count): Quiz
{
    return app(BuildPracticeQuiz::class)->handle($learner, $pathIds, $count);
}

test('the practice quiz draws only from paths released to apprentices', function () {
    $released = practicePath(3, 'Freigegeben');

    // Entwurf und ungeprüft — beides darf nie in einer Übung landen (Konzept e).
    $draft = LearningPath::factory()->verified()->create(['title' => 'Entwurf']);
    $unreviewed = LearningPath::factory()->published()->create(['title' => 'Ungeprüft']);

    foreach ([$draft, $unreviewed] as $hidden) {
        $question = Question::factory()->for($hidden)->create(['type' => QuestionType::Single]);
        QuestionOption::factory()->for($question)->create(['is_correct' => true]);
    }

    $quiz = buildPractice($this->learner, [$released->id, $draft->id, $unreviewed->id], 10);

    expect($quiz->questions()->pluck('learning_path_id')->unique()->all())
        ->toBe([$released->id]);
});

test('a question without options is never drawn', function () {
    $path = practicePath(2);

    // Unbeantwortbar — würde den Versuch blockieren.
    Question::factory()->for($path)->create(['type' => QuestionType::Single]);

    $quiz = buildPractice($this->learner, [$path->id], 10);

    expect($quiz->questions()->count())->toBe(2);
});

test('the number of questions is capped at what is available', function () {
    $path = practicePath(3);

    $quiz = buildPractice($this->learner, [$path->id], 25);

    expect($quiz->questions()->count())->toBe(3);
});

test('the requested number of questions is respected', function () {
    $path = practicePath(8);

    $quiz = buildPractice($this->learner, [$path->id], 4);

    expect($quiz->questions()->count())->toBe(4);
});

test('an apprentice has exactly one practice quiz, refilled each session', function () {
    $first = practicePath(4, 'Netzwerke');
    $second = practicePath(4, 'Datenbanken');

    $quiz = buildPractice($this->learner, [$first->id], 4);
    $refilled = buildPractice($this->learner, [$second->id], 4);

    expect($refilled->id)->toBe($quiz->id)
        ->and(Quiz::where('kind', QuizKind::Practice)->count())->toBe(1)
        // Der Pivot trägt danach nur noch die neue Auswahl.
        ->and($refilled->questions()->pluck('learning_path_id')->unique()->all())
        ->toBe([$second->id]);
});

test('the database refuses a second practice container for the same apprentice', function () {
    practicePath(2);

    buildPractice($this->learner, [], 2);

    /*
     * Der partielle Unique-Index ist die eigentliche Garantie — nicht die
     * Lesen-dann-Schreiben-Disziplin in BuildPracticeQuiz. Zwei Tabs desselben
     * Teilnehmer_in könnten die umgehen, den Index nicht.
     *
     * Der Einsprung in DB::transaction() ist kein Beiwerk: auf PostgreSQL
     * bricht ein gescheitertes Statement die **ganze** umgebende Transaktion
     * ab, und das ist hier die von RefreshDatabase. Ohne den SAVEPOINT, den
     * Laravel für eine verschachtelte Transaktion setzt, liefe die Zählung
     * danach in „current transaction is aborted" statt in ihre Zusicherung.
     * Genau dieselbe Semantik hat BuildPracticeQuiz mit `firstOrCreate` im
     * Rücken (das setzt den SAVEPOINT selbst); der Test muss sie nachbilden,
     * weil er den Index absichtlich frontal anläuft.
     */
    expect(fn () => DB::transaction(fn () => Quiz::factory()->create([
        'kind' => QuizKind::Practice,
        'created_by' => $this->learner->id,
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(Quiz::where('kind', QuizKind::Practice)->count())->toBe(1);
});

test('the practice index leaves editorial quizzes of one admin alone', function () {
    $admin = User::factory()->admin()->create();

    // Ein gewöhnliches unique(kind, created_by) hätte hier zugeschlagen — der
    // Index greift ausdrücklich nur für kind = 'practice'.
    Quiz::factory()->count(3)->create(['created_by' => $admin->id]);
    Quiz::factory()->pathFinal()->count(2)->create(['created_by' => $admin->id]);

    expect(Quiz::where('created_by', $admin->id)->count())->toBe(5);
});

test('the loser of a race gets the existing container instead of an error', function () {
    practicePath(2);

    $first = buildPractice($this->learner, [], 2);

    // `createOrFirst` überspringt das einleitende SELECT und schreibt sofort —
    // das ist exakt der Weg des Verlierers im Zwei-Tab-Rennen, der beim Lesen
    // noch nichts gefunden hat. Er läuft in die Index-Verletzung, und Laravel
    // liest daraufhin den Behälter des Gewinners nach, statt einen 500 zu
    // werfen und eine Waise zu hinterlassen.
    $second = Quiz::createOrFirst(
        ['kind' => QuizKind::Practice, 'created_by' => $this->learner->id],
        ['title' => 'Sollte nie angelegt werden', 'status' => PathStatus::Draft],
    );

    expect($second->id)->toBe($first->id)
        ->and($second->title)->not->toBe('Sollte nie angelegt werden')
        ->and(Quiz::where('kind', QuizKind::Practice)->count())->toBe(1);
});

test('refilling the practice quiz does not falsify a finalised attempt', function () {
    $first = practicePath(3, 'Netzwerke');

    $quiz = buildPractice($this->learner, [$first->id], 3);
    $frozen = $quiz->questions()->pluck('questions.id')->all();

    // Ein abgeschlossener Versuch mit eigener Fragenliste.
    $attempt = QuizAttempt::factory()->passed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'question_order' => $frozen,
    ]);

    $second = practicePath(3, 'Datenbanken');
    buildPractice($this->learner, [$second->id], 3);

    // Die Liste des alten Versuchs zeigt weiterhin auf seine Fragen — die
    // Ersetzung des Pivots schreibt Vergangenes nicht um.
    expect($attempt->refresh()->question_order)->toBe($frozen);
});

test('a practice quiz carries no path and freezes its question list', function () {
    $path = practicePath(3);

    $quiz = buildPractice($this->learner, [$path->id], 3);

    expect($quiz->kind)->toBe(QuizKind::Practice)
        ->and($quiz->learning_path_id)->toBeNull()
        ->and($quiz->created_by)->toBe($this->learner->id)
        ->and($quiz->freezesQuestionOrder())->toBeTrue()
        ->and($quiz->reportsVerdict())->toBeFalse();
});

test('a practice quiz belongs to its owner alone', function () {
    $path = practicePath(3);
    $quiz = buildPractice($this->learner, [$path->id], 3);

    $stranger = User::factory()->apprentice()->create();

    $this->actingAs($this->learner)
        ->get(route('learn.quizzes.show', ['current_team' => $this->learner->currentTeam, 'quiz' => $quiz]))
        ->assertOk();

    $this->actingAs($stranger)
        ->get(route('learn.quizzes.show', ['current_team' => $stranger->currentTeam, 'quiz' => $quiz]))
        ->assertForbidden();

    Livewire::actingAs($stranger)
        ->test('pages::learn.quizzes.show', ['quiz' => $quiz])
        ->assertForbidden();
});

test('practice quizzes stay out of the admin lists', function () {
    $path = practicePath(3);
    $quiz = buildPractice($this->learner, [$path->id], 3);

    $admin = User::factory()->admin()->create();

    $listed = Livewire::actingAs($admin)
        ->test('pages::admin.quizzes.index')
        ->assertOk()
        ->instance()
        ->quizzes;

    expect($listed->contains('id', $quiz->id))->toBeFalse();

    $this->actingAs($admin)
        ->get(route('admin.quizzes.index'))
        ->assertOk()
        ->assertDontSee('Übungsquiz');
});

test('the practice draw does not inflate the usage counter in the question pool', function () {
    $path = practicePath(2);
    $editorial = Quiz::factory()->published()->create();
    $question = Question::query()->where('learning_path_id', $path->id)->firstOrFail();
    $editorial->questions()->attach($question->id, ['position' => 1]);

    buildPractice($this->learner, [$path->id], 2);

    $admin = User::factory()->admin()->create();

    $pooled = Livewire::actingAs($admin)
        ->test('pages::admin.questions.index')
        ->instance()
        ->questions
        ->firstWhere('id', $question->id);

    // Das eine redaktionelle Quiz zählt, der Übungsbehälter nicht.
    expect($pooled->quizzes_count)->toBe(1);
});

test('practice answers show up in the weak spots', function () {
    $path = practicePath(2);
    $quiz = buildPractice($this->learner, [$path->id], 2);

    $questions = $quiz->questions()->with('options')->get();

    $attempt = QuizAttempt::factory()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
        'question_order' => $questions->modelKeys(),
        'score' => 1,
        'max_score' => 2,
    ]);

    $wrongQuestion = $questions->first();

    $attempt->responses()->create([
        'question_id' => $wrongQuestion->id,
        'selected_option_ids' => [$wrongQuestion->options->firstWhere('is_correct', false)->id],
        'is_correct' => false,
    ]);

    $weakSpots = Livewire::actingAs($this->learner)
        ->test('pages::learn.progress')
        ->assertOk()
        ->instance()
        ->report
        ->weakSpots;

    // E3: Übungs-Antworten zählen mit — mehr Daten, bessere Diagnose.
    expect($weakSpots)->toHaveCount(1)
        ->and($weakSpots[0]->question->id)->toBe($wrongQuestion->id)
        // …aber der Wiederholen-Link zeigt nie auf den Übungsbehälter: dessen
        // Fragen sind nach der nächsten Sitzung andere.
        ->and($weakSpots[0]->quizId)->toBeNull();
});

test('the builder starts the practice quiz and lands on the quiz page', function () {
    $path = practicePath(5);

    $component = Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertOk()
        ->set('selectedPathIds', [$path->id])
        ->set('questionCount', 3)
        ->call('startPractice')
        ->assertHasNoErrors();

    $quiz = Quiz::where('kind', QuizKind::Practice)->sole();

    $component->assertRedirect(route('learn.quizzes.show', $quiz));

    expect($quiz->questions()->count())->toBe(3);
});

test('an admin is sent to their own dashboard instead of the practice builder', function () {
    practicePath(3);

    $admin = User::factory()->admin()->create();

    // Über HTTP statt über Livewire::test: die Weiche sitzt seit F3 in
    // EnsureTeamMembership und nicht mehr in mount(). Nur so hätte sie auch
    // beim Aufruf mit einer *fremden* Gruppe gegriffen — vorher schlug dort
    // die Mitgliedsprüfung mit 403 zu, bevor mount() lief.
    $this->actingAs($admin)
        ->get(learnRoute('learn.practice.create', $admin))
        ->assertRedirect(route('admin.dashboard'));

    // Kein Behälter unter Admin-Namen — die Seite hat gar nicht erst gebaut.
    expect(Quiz::where('kind', QuizKind::Practice)->count())->toBe(0);
});

test('the builder accepts up to one hundred questions', function () {
    $path = practicePath(100);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertSeeHtml('max="100"')
        ->set('selectedPathIds', [$path->id])
        ->set('questionCount', 100)
        ->call('startPractice')
        ->assertHasNoErrors();

    expect(Quiz::where('kind', QuizKind::Practice)->sole()->questions()->count())->toBe(100);
});

test('the builder rejects more than one hundred questions', function () {
    $path = practicePath(3);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->set('selectedPathIds', [$path->id])
        ->set('questionCount', 101)
        ->call('startPractice')
        ->assertHasErrors(['questionCount' => 'max']);

    expect(Quiz::where('kind', QuizKind::Practice)->count())->toBe(0);
});

test('the builder rejects an empty topic selection', function () {
    practicePath(3);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->set('selectedPathIds', [])
        ->call('startPractice')
        ->assertHasErrors(['selectedPathIds']);

    expect(Quiz::where('kind', QuizKind::Practice)->count())->toBe(0);
});

test('the builder refuses topics without any questions', function () {
    $empty = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Leer']);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->set('selectedPathIds', [$empty->id])
        ->call('startPractice')
        ->assertHasErrors(['selectedPathIds']);

    expect(Quiz::where('kind', QuizKind::Practice)->count())->toBe(0);
});

test('the builder offers only released paths with their question counts', function () {
    $released = practicePath(3, 'Freigegeben');
    LearningPath::factory()->verified()->create(['title' => 'Entwurf']);

    $component = Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertOk()
        ->assertSee('Freigegeben')
        ->assertDontSee('Entwurf');

    expect($component->instance()->availableCounts)->toBe([$released->id => 3]);
});

test('the builder selects all paths that contain practice questions', function () {
    $first = practicePath(2, 'Netzwerke');
    $second = practicePath(2, 'Datenbanken');
    $empty = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Leer']);
    $expectedPathIds = collect([$first, $second])
        ->sortBy('position')
        ->pluck('id')
        ->all();

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertSee('Alle auswählen')
        ->set('selectedPathIds', [])
        ->call('startPractice')
        ->assertHasErrors(['selectedPathIds'])
        ->call('toggleAllPaths')
        ->assertSet('selectedPathIds', $expectedPathIds)
        ->assertHasNoErrors(['selectedPathIds']);

    expect($expectedPathIds)->not->toContain($empty->id);
});

test('the select-all button toggles the selection back off on the second press', function () {
    practicePath(2, 'Netzwerke');
    practicePath(2, 'Datenbanken');

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->set('selectedPathIds', [])
        ->assertSee('Alle auswählen')
        ->call('toggleAllPaths')
        ->assertSee('Alle abwählen')
        ->call('toggleAllPaths')
        ->assertSet('selectedPathIds', [])
        ->assertSee('Alle auswählen');
});

test('the select-all button offers to deselect once every drawable path is already ticked', function () {
    $first = practicePath(2, 'Netzwerke');
    $second = practicePath(2, 'Datenbanken');
    LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Leer']);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->set('selectedPathIds', [$first->id])
        ->assertSee('Alle auswählen')
        ->set('selectedPathIds', [(string) $first->id, (string) $second->id])
        ->assertSee('Alle abwählen');
});

test('the builder prefills the paths given in the query string', function () {
    $first = practicePath(2, 'Netzwerke');
    $second = practicePath(2, 'Datenbanken');
    $hidden = LearningPath::factory()->verified()->create();

    $this->withoutExceptionHandling();

    Livewire::actingAs($this->learner)
        ->withQueryParams(['paths' => "{$first->id},{$hidden->id}"])
        ->test('pages::learn.practice.create')
        // Der unsichtbare Pfad fällt still heraus, der zweite war nicht dabei.
        ->assertSet('selectedPathIds', [$first->id])
        ->assertDontSee('__never__');

    expect($second->id)->not->toBe($first->id);
});

test('the practice quiz reports no verdict', function () {
    $path = practicePath(2);
    $quiz = buildPractice($this->learner, [$path->id], 2);

    $component = Livewire::actingAs($this->learner)
        ->test('pages::learn.quizzes.show', ['quiz' => $quiz])
        ->assertOk()
        // Einstieg ohne Bestehensgrenze (E2).
        ->assertDontSee('Zum Bestehen')
        ->assertSee('Hier gibt es keine Bestehensgrenze');

    $questions = $quiz->questions()->with('options')->get()->keyBy('id');

    $component->call('start');

    foreach ($component->get('questionOrder') as $position => $questionId) {
        $component
            ->set('selection', [$questions[$questionId]->options->firstWhere('is_correct', true)->id])
            ->call('submitAnswer');

        if ($position < $questions->count() - 1) {
            $component->call('next');
        }
    }

    $component->call('next')
        ->assertSee('Übungsrunde geschafft.')
        ->assertDontSee('Bestanden!')
        ->assertSee('2 von 2 richtig')
        // Der Knopf sagt, was nochmal kommt — „Nochmal versuchen" ließe eine
        // frische Ziehung erwarten, die es daneben gibt.
        ->assertSee('Dieselben Fragen nochmal')
        ->assertSee('Neue Fragen ziehen')
        ->assertDontSee('Nochmal versuchen');
});

test('a finished practice run claims no verdict in the database', function () {
    $path = practicePath(2);
    $quiz = buildPractice($this->learner, [$path->id], 2);

    $component = Livewire::actingAs($this->learner)
        ->test('pages::learn.quizzes.show', ['quiz' => $quiz])
        ->call('start');

    $questions = $quiz->questions()->with('options')->get()->keyBy('id');

    foreach ($component->get('questionOrder') as $position => $questionId) {
        $component
            ->set('selection', [$questions[$questionId]->options->firstWhere('is_correct', true)->id])
            ->call('submitAnswer');

        if ($position < $questions->count() - 1) {
            $component->call('next');
        }
    }

    $component->call('next');

    $attempt = QuizAttempt::sole();

    // Alles richtig — und trotzdem kein „bestanden": beim Üben gibt es kein
    // Urteil (E2), also darf auch keines in der Datenbank stehen. Sonst zählte
    // eine spätere Aggregation über `passed` Übungsrunden mit.
    expect($attempt->score)->toBe(2)
        ->and($attempt->max_score)->toBe(2)
        ->and($attempt->passed)->toBeFalse();
});

test('the practise page counts the rounds already done', function () {
    $path = practicePath(3);
    $quiz = buildPractice($this->learner, [$path->id], 3);

    // Ohne Runde steht dort nichts — eine 0 wäre kein Ansporn.
    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertOk()
        ->assertDontSee('Übungsrunde');

    $this->travelTo(now()->setDate(2026, 7, 20)->setTime(9, 0));

    QuizAttempt::factory()->count(2)->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    // Ein laufender Versuch ist keine geleistete Runde.
    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertSee('2 Übungsrunden bisher')
        ->assertSee('zuletzt am 20.07.2026');
});

test('the round counter ignores other apprentices', function () {
    $path = practicePath(3);
    $quiz = buildPractice($this->learner, [$path->id], 3);

    $stranger = User::factory()->apprentice()->create();
    $strangerQuiz = buildPractice($stranger, [$path->id], 3);

    QuizAttempt::factory()->create(['user_id' => $this->learner->id, 'quiz_id' => $quiz->id]);
    QuizAttempt::factory()->count(4)->create(['user_id' => $stranger->id, 'quiz_id' => $strangerQuiz->id]);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.practice.create')
        ->assertSee('1 Übungsrunde bisher')
        ->assertDontSee('5 Übungsrunden');
});

test('the practice draw stays within its query budget', function () {
    foreach (range(1, 5) as $index) {
        practicePath(6, "Thema {$index}");
    }

    $pathIds = LearningPath::query()->visibleToLearners()->pluck('id')->all();

    DB::enableQueryLog();

    buildPractice($this->learner, $pathIds, 20);

    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    // Konstant: Ziehung, Behälter suchen/anlegen, Pivot ersetzen — nichts
    // wächst mit der Zahl der Pfade oder Fragen.
    expect(count($queries))->toBeLessThan(12);
});
