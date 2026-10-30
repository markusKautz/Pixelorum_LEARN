<?php

use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View as ViewInstance;
use Livewire\Livewire;

/*
 * Sprint 5.6 — freie Quizze werden gruppenweise ausgegeben. Die Ausgabe ist
 * zugleich das Sichtbarkeits-Gate: „veröffentlicht" heißt „fertig",
 * „ausgegeben" heißt „an diesen Jahrgang gegeben".
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
});

/**
 * Run the sidebar layout through its view composers and return the bound data.
 *
 * Volt-Seiten liefern keine View-Response, `assertViewHas` greift dort also
 * nicht. Dass der Composer im echten Seitenaufbau **feuert**, halten die
 * übrigen Seitentests fest: ohne ihn wäre `$openAssignedQuizCount` undefiniert
 * und jede Lehrlings-Seite liefe auf 500.
 *
 * @return array<string, mixed>
 */
function composedSidebarData(): array
{
    $factory = app(ViewFactory::class);

    /** @var ViewInstance $view */
    $view = $factory->make('layouts::app.sidebar');

    $factory->callComposer($view);

    return $view->getData();
}

/**
 * Create a published free quiz carrying one answerable question.
 */
function assignableQuiz(string $title = 'Wiederholung Netzwerke'): Quiz
{
    $quiz = Quiz::factory()->published()->create([
        'title' => $title,
        'kind' => QuizKind::Custom,
    ]);

    $question = Question::factory()->create();
    QuestionOption::factory()->for($question)->create(['is_correct' => true]);
    $quiz->questions()->attach($question->id, ['position' => 1]);

    return $quiz;
}

test('an admin hands a free quiz out to a group and withdraws it again', function () {
    $quiz = assignableQuiz();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertOk()
        ->assertSee('An Gruppen ausgeben')
        ->assertSee('Lehrjahr 2026')
        ->call('toggleGroup', $this->group->id)
        ->assertHasNoErrors();

    expect($quiz->teams()->pluck('teams.id')->all())->toBe([$this->group->id]);

    $component->call('toggleGroup', $this->group->id);

    expect($quiz->teams()->count())->toBe(0);
});

test('the assignment section stays hidden for final and practice quizzes', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $final = Quiz::factory()->pathFinal($path)->published()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $final])
        ->assertOk()
        // Das Abschluss-Quiz bezieht seine Zielgruppe aus seinem Pfad.
        ->assertDontSee('An Gruppen ausgeben');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $final])
        ->call('toggleGroup', $this->group->id)
        ->assertForbidden();

    expect($final->teams()->count())->toBe(0);
});

test('a personal team cannot be handed a quiz', function () {
    $quiz = assignableQuiz();
    $personal = Team::factory()->personal()->create();

    // Persönliche Teams sind keine Lehrlingsgruppen — sie stehen gar nicht
    // erst zur Auswahl …
    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertDontSee($personal->name);

    // … und ein untergeschobener Payload findet sie nicht (der Filter sitzt in
    // der Abfrage, nicht erst in der Ansicht).
    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->call('toggleGroup', $personal->id)
        ->assertNotFound();

    expect($quiz->teams()->count())->toBe(0);
});

test('an apprentice cannot hand out a quiz', function () {
    $quiz = assignableQuiz();
    $apprentice = User::factory()->apprentice($this->group)->create();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->call('toggleGroup', $this->group->id)
        ->assertForbidden();

    expect($quiz->teams()->count())->toBe(0);
});

test('the quiz list flags a published free quiz that nobody received', function () {
    $unassigned = assignableQuiz('Noch nicht ausgegeben');
    $assigned = assignableQuiz('Schon ausgegeben');

    $assigned->teams()->attach($this->group);

    $this->actingAs($this->admin)
        ->get(route('admin.quizzes.index'))
        ->assertOk()
        // Ohne den Hinweis wirkt „veröffentlicht" wie „die Lehrlinge haben es".
        ->assertSee('Nicht ausgegeben')
        ->assertSee('Lehrjahr 2026');

    expect($unassigned->teams()->count())->toBe(0)
        ->and($assigned->teams()->count())->toBe(1);
});

test('the builder warns when an assigned quiz is still a draft', function () {
    $quiz = assignableQuiz();
    $quiz->update(['status' => PathStatus::Draft]);
    $quiz->teams()->attach($this->group);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertOk()
        ->assertSee('noch nicht sichtbar');

    $quiz->update(['status' => PathStatus::Published]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->assertDontSee('noch nicht sichtbar');
});

test('an apprentice reaches a quiz handed to their group and no other', function () {
    $mine = assignableQuiz('Mein Quiz');
    $foreign = assignableQuiz('Fremdes Quiz');
    $unassigned = assignableQuiz('Herrenlos');

    $apprentice = User::factory()->apprentice($this->group)->create();

    $mine->teams()->attach($this->group);
    $foreign->teams()->attach(Team::factory()->create());

    $this->actingAs($apprentice)
        ->get(route('learn.quizzes.show', $mine))
        ->assertOk();

    // Auch per Direkt-URL: die Ausgabe ist das Gate, nicht bloß die Verlinkung.
    $this->actingAs($apprentice)
        ->get(route('learn.quizzes.show', $foreign))
        ->assertForbidden();

    $this->actingAs($apprentice)
        ->get(route('learn.quizzes.show', $unassigned))
        ->assertForbidden();
});

test('the practise page lists the quizzes handed to the group', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    $mine = assignableQuiz('Wiederholung Netzwerke');
    $foreign = assignableQuiz('Fremdes Quiz');

    $mine->teams()->attach($this->group);
    $foreign->teams()->attach(Team::factory()->create());

    $this->actingAs($apprentice)
        ->get(learnRoute('learn.practice.create', $apprentice))
        ->assertOk()
        ->assertSee('Von deinem Ausbildner')
        ->assertSee('Wiederholung Netzwerke')
        ->assertDontSee('Fremdes Quiz');

    // Das Dashboard zeigt seit dem Sichttest ausschliesslich Lernpfade.
    $this->actingAs($apprentice)
        ->get(learnRoute('dashboard', $apprentice))
        ->assertOk()
        ->assertDontSee('Wiederholung Netzwerke');
});

test('the practise page section disappears without any handed out quiz', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    assignableQuiz('Herrenlos');

    $this->actingAs($apprentice)
        ->get(learnRoute('learn.practice.create', $apprentice))
        ->assertOk()
        ->assertDontSee('Von deinem Ausbildner')
        ->assertDontSee('Herrenlos');
});

test('the group analytics aggregate the handed out quizzes over the whole group', function () {
    $quiz = assignableQuiz('Wiederholung Netzwerke');
    $quiz->update(['pass_threshold' => 80]);
    $quiz->teams()->attach($this->group);

    $passed = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);
    $failed = User::factory()->apprentice($this->group)->create(['name' => 'Bruno']);
    User::factory()->apprentice($this->group)->create(['name' => 'Clara']);
    User::factory()->apprentice($this->group)->create(['name' => 'Dario']);

    QuizAttempt::factory()->create([
        'user_id' => $passed->id, 'quiz_id' => $quiz->id,
        'score' => 10, 'max_score' => 10, 'passed' => true,
    ]);

    QuizAttempt::factory()->create([
        'user_id' => $failed->id, 'quiz_id' => $quiz->id,
        'score' => 5, 'max_score' => 10, 'passed' => false,
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->assertOk()
        ->instance()
        ->report;

    expect($report->quizzes)->toHaveCount(1);

    $row = $report->quizzes[0];

    // Bestehensquote zählt über die ganze Gruppe; der Ø Punktestand nur über
    // die Angetretenen: (100+50)/2 = 75.
    expect($row->averagePercent)->toBe(75)
        ->and($row->attemptedCount)->toBe(2)
        ->and($row->memberCount)->toBe(4)
        ->and($row->passedCount)->toBe(1)
        ->and($row->passRate())->toBe(25)
        ->and($row->wasAttempted())->toBeTrue();
});

test('only the best attempt of a member counts towards the quiz average', function () {
    $quiz = assignableQuiz();
    $quiz->teams()->attach($this->group);

    $learner = User::factory()->apprentice($this->group)->create();

    QuizAttempt::factory()->create([
        'user_id' => $learner->id, 'quiz_id' => $quiz->id,
        'score' => 3, 'max_score' => 10, 'passed' => false,
    ]);

    QuizAttempt::factory()->create([
        'user_id' => $learner->id, 'quiz_id' => $quiz->id,
        'score' => 9, 'max_score' => 10, 'passed' => true,
    ]);

    // Ein laufender Versuch zählt nicht mit — sonst zöge er den Ø nach unten.
    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $learner->id, 'quiz_id' => $quiz->id,
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->instance()
        ->report;

    expect($report->quizzes[0]->averagePercent)->toBe(90)
        ->and($report->quizzes[0]->attemptedCount)->toBe(1)
        ->and($report->quizzes[0]->passedCount)->toBe(1);
});

test('the best attempt is taken row-wise when max score varies between attempts', function () {
    $quiz = assignableQuiz();
    $quiz->teams()->attach($this->group);

    $learner = User::factory()->apprentice($this->group)->create();

    // Erster Versuch: 8 von 10 (80 %) — der beste, höchste Quote und Score.
    QuizAttempt::factory()->create([
        'user_id' => $learner->id, 'quiz_id' => $quiz->id,
        'score' => 8, 'max_score' => 10, 'passed' => true,
    ]);

    // Zweiter Versuch: 7 von 11 (64 %) — schlechter, aber höherer max_score,
    // weil der Admin zwischenzeitlich eine Frage angehängt hat. Unabhängige
    // MAX(score)/MAX(max_score) hätten 8/11 = 72 % statt 80 % geliefert.
    QuizAttempt::factory()->create([
        'user_id' => $learner->id, 'quiz_id' => $quiz->id,
        'score' => 7, 'max_score' => 11, 'passed' => false,
    ]);

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->instance()
        ->report;

    expect($report->quizzes[0]->averagePercent)->toBe(80)
        ->and($report->quizzes[0]->attemptedCount)->toBe(1)
        // ever_passed gilt, sobald je bestanden — unabhängig vom Besten.
        ->and($report->quizzes[0]->passedCount)->toBe(1);
});

test('the practise page shows the best attempt row-wise when max score varies', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    $quiz = assignableQuiz();
    $quiz->teams()->attach($this->group);

    // Dieselbe Konstellation wie im Gruppenreport, hier aus der Lehrlings-Sicht:
    // Zähler und Nenner der Badge müssen aus demselben Versuch stammen.
    QuizAttempt::factory()->create([
        'user_id' => $apprentice->id, 'quiz_id' => $quiz->id,
        'score' => 8, 'max_score' => 10, 'passed' => true,
    ]);

    QuizAttempt::factory()->create([
        'user_id' => $apprentice->id, 'quiz_id' => $quiz->id,
        'score' => 7, 'max_score' => 11, 'passed' => false,
    ]);

    $this->actingAs($apprentice)
        ->get(learnRoute('learn.practice.create', $apprentice))
        ->assertOk()
        ->assertSee('Bester Versuch: 8 von 10')
        ->assertDontSee('Bester Versuch: 8 von 11');
});

test('a quiz nobody attempted is told apart from one everybody failed', function () {
    $untouched = assignableQuiz('Unberührt');
    $untouched->teams()->attach($this->group);

    User::factory()->apprentice($this->group)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.groups.analytics', $this->group))
        ->assertOk()
        ->assertSee('Ausgegebene Quizze')
        ->assertSee('Unberührt')
        ->assertSee('Noch niemand');
});

test('a quiz handed to another group stays out of this analytics', function () {
    $foreign = assignableQuiz('Fremdes Quiz');
    $foreign->teams()->attach(Team::factory()->create());

    User::factory()->apprentice($this->group)->create();

    $report = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->instance()
        ->report;

    expect($report->quizzes)->toBe([]);

    $this->actingAs($this->admin)
        ->get(route('admin.groups.analytics', $this->group))
        ->assertDontSee('Ausgegebene Quizze');
});

test('the learner drilldown lists attempts on handed out quizzes', function () {
    $quiz = assignableQuiz('Wiederholung Netzwerke');
    $quiz->teams()->attach($this->group);

    $learner = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);

    QuizAttempt::factory()->passed()->create(['user_id' => $learner->id, 'quiz_id' => $quiz->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $learner]))
        ->assertOk()
        ->assertSee('Wiederholung Netzwerke');
});

test('withdrawing a quiz does not erase the attempts already made', function () {
    $quiz = assignableQuiz('Wiederholung Netzwerke');
    $quiz->teams()->attach($this->group);

    $learner = User::factory()->apprentice($this->group)->create(['name' => 'Anna']);

    QuizAttempt::factory()->passed()->create(['user_id' => $learner->id, 'quiz_id' => $quiz->id]);

    // Der Ausbildner nimmt die Ausgabe zurück — das Quiz ist damit nicht mehr
    // zu bearbeiten, die geleistete Arbeit bleibt aber bestehen.
    $quiz->teams()->detach();

    $this->actingAs($this->admin)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $learner]))
        ->assertOk()
        ->assertSee('Wiederholung Netzwerke');

    // Auch der Lehrling behält seine Historie …
    $this->actingAs($learner)
        ->get(learnRoute('learn.progress', $learner))
        ->assertOk()
        ->assertSee('Wiederholung Netzwerke');

    // … aber das Quiz ist nicht mehr zu erreichen und steht nicht mehr auf „Üben".
    $this->actingAs($learner)
        ->get(route('learn.quizzes.show', $quiz))
        ->assertForbidden();

    $this->actingAs($learner)
        ->get(learnRoute('learn.practice.create', $learner))
        ->assertOk()
        ->assertDontSee('Wiederholung Netzwerke');
});

test('a withdrawn learning path takes its attempts out of the evaluation', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['title' => 'Netzwerke']);
    $final = Quiz::factory()->pathFinal($path)->published()->create(['title' => 'Abschluss Netzwerke']);

    $learner = User::factory()->apprentice($this->group)->create();

    QuizAttempt::factory()->passed()->create(['user_id' => $learner->id, 'quiz_id' => $final->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $learner]))
        ->assertSee('Abschluss Netzwerke');

    // Bewusst anders als beim freien Quiz (Auftraggeber, 2026-07-25): ein
    // zurückgezogener Pfad ist nicht mehr in der Wertung — samt seiner Versuche.
    $path->update(['status' => PathStatus::Draft]);

    $this->actingAs($this->admin)
        ->get(route('admin.groups.learner', ['team' => $this->group, 'member' => $learner]))
        ->assertOk()
        ->assertDontSee('Abschluss Netzwerke');
});

test('the group analytics stay within their query budget with handed out quizzes', function () {
    foreach (range(1, 5) as $index) {
        assignableQuiz("Quiz {$index}")->teams()->attach($this->group);
    }

    foreach (range(1, 4) as $index) {
        $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => $index]);
        Quiz::factory()->pathFinal($path)->published()->create();
    }

    User::factory()->apprentice($this->group)->count(6)->create();

    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->assertOk()
        ->instance()
        ->report;

    // Die Quiz-Aggregate kosten zwei Abfragen zusätzlich (Liste + bester
    // Versuch je Mitglied), nicht eine je Quiz.
    expect($queries)->toBeLessThan(15);
});

test('the sidebar counts handed out quizzes the apprentice has not finished', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    $open = assignableQuiz('Offenes Quiz');
    $done = assignableQuiz('Erledigtes Quiz');
    $foreign = assignableQuiz('Fremdes Quiz');

    $open->teams()->attach($this->group);
    $done->teams()->attach($this->group);
    $foreign->teams()->attach(Team::factory()->create());

    QuizAttempt::factory()->create(['user_id' => $apprentice->id, 'quiz_id' => $done->id]);

    // Ein laufender Versuch erledigt nichts — das Quiz bleibt offen.
    QuizAttempt::factory()->inProgress()->create([
        'user_id' => $apprentice->id,
        'quiz_id' => $open->id,
    ]);

    expect($apprentice->openAssignedQuizCount())->toBe(1);

    $this->actingAs($apprentice)
        ->get(learnRoute('dashboard', $apprentice))
        ->assertOk()
        // Das Zeichen steht an der Sidebar, nicht im Dashboard-Inhalt.
        ->assertSee('data-flux-navlist-badge', escape: false);
});

test('the view composer binds the open quiz count to the sidebar layout', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    assignableQuiz('Eins')->teams()->attach($this->group);
    assignableQuiz('Zwei')->teams()->attach($this->group);

    $this->actingAs($apprentice);

    expect(composedSidebarData()['openAssignedQuizCount'])->toBe(2);

    // Der Ausbildner sieht den „Üben"-Eintrag gar nicht (`@cannot('admin')`) —
    // für ihn darf der Composer die Abfrage also gar nicht erst stellen.
    $this->actingAs($this->admin);

    $counted = 0;

    DB::listen(function (QueryExecuted $query) use (&$counted) {
        if (str_contains($query->sql, 'quizzes')) {
            $counted++;
        }
    });

    expect(composedSidebarData()['openAssignedQuizCount'])->toBe(0)
        ->and($counted)->toBe(0);
});

test('the counter drops to zero once every handed out quiz is finished', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    $quiz = assignableQuiz();
    $quiz->teams()->attach($this->group);

    expect($apprentice->openAssignedQuizCount())->toBe(1);

    QuizAttempt::factory()->create(['user_id' => $apprentice->id, 'quiz_id' => $quiz->id]);

    expect($apprentice->fresh()->openAssignedQuizCount())->toBe(0);
});

test('another apprentices attempt does not clear the counter', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();
    $classmate = User::factory()->apprentice($this->group)->create();

    $quiz = assignableQuiz();
    $quiz->teams()->attach($this->group);

    QuizAttempt::factory()->create(['user_id' => $classmate->id, 'quiz_id' => $quiz->id]);

    expect($apprentice->openAssignedQuizCount())->toBe(1)
        ->and($classmate->openAssignedQuizCount())->toBe(0);
});

test('the practise page stays within its query budget with handed out quizzes', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    foreach (range(1, 6) as $index) {
        assignableQuiz("Quiz {$index}")->teams()->attach($this->group);
    }

    foreach (range(1, 4) as $index) {
        $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create(['position' => $index]);
        Quiz::factory()->pathFinal($path)->published()->create();
    }

    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($apprentice)
        ->get(learnRoute('learn.practice.create', $apprentice))
        ->assertOk();

    // Konstant je Seite: die Quiz-Liste und ihre besten Versuche kommen aus je
    // einer Abfrage, nicht einer pro Karte.
    expect($queries)->toBeLessThan(25);
});

test('a failed handed out quiz does not move the group reading average', function () {
    $learner = User::factory()->apprentice($this->group)->create();

    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $lesson = Lesson::factory()->for($path)->create();
    LessonCompletion::factory()->create([
        'user_id' => $learner->id,
        'lesson_id' => $lesson->id,
    ]);

    $quiz = assignableQuiz('Zusatzquiz');
    $quiz->teams()->attach($this->group);

    $before = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->instance()
        ->report
        ->summary
        ->averagePercent;

    // Der Lehrling fällt durch das ausgegebene Quiz.
    QuizAttempt::factory()->create([
        'user_id' => $learner->id,
        'quiz_id' => $quiz->id,
        'score' => 2,
        'max_score' => 10,
        'passed' => false,
    ]);

    $afterReport = Livewire::actingAs($this->admin)
        ->test('pages::admin.analytics.group', ['team' => $this->group])
        ->instance()
        ->report;

    // Der Ø-Lesefortschritt berücksichtigt Quizze bewusst nicht (Sprint-5.6).
    expect($afterReport->summary->averagePercent)->toBe($before)
        // Der Fehlversuch erscheint trotzdem im Block „Ausgegebene Quizze".
        ->and($afterReport->quizzes)->toHaveCount(1)
        ->and($afterReport->quizzes[0]->attemptedCount)->toBe(1)
        ->and($afterReport->quizzes[0]->passedCount)->toBe(0)
        ->and($afterReport->quizzes[0]->averagePercent)->toBe(20);
});

test('switching to custom without saving shows a warning instead of the group list', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.quizzes.edit', ['quiz' => $quiz])
        ->set('kind', QuizKind::Custom->value)
        ->assertSee('An Gruppen ausgeben')
        ->assertSee('Speichere zuerst die Typ-Änderung')
        ->assertDontSeeHtml('data-test="quiz-group-row"');
});

test('the quiz list caps the handed out groups at two badges plus a counter', function () {
    $quiz = assignableQuiz('An viele Gruppen');
    $groups = Team::factory()->count(5)->create();
    $quiz->teams()->attach($groups->pluck('id'));

    $content = $this->actingAs($this->admin)
        ->get(route('admin.quizzes.index'))
        ->assertOk()
        ->getContent();

    expect(substr_count($content, 'data-test="quiz-teams-badges"'))->toBe(1)
        // Genau zwei sichtbare Gruppen-Badges plus der Zähler.
        ->and(substr_count($content, 'data-test="quiz-teams-more-badge"'))->toBe(1)
        ->and($content)->toContain('+3 weitere');
});

test('the quiz list shows all group badges when there are only two', function () {
    $quiz = assignableQuiz('An zwei Gruppen');

    /*
     * Feste Namen statt der erwürfelten Firmennamen aus der Factory: die
     * Behauptung unten vergleicht mit dem *gerenderten* HTML, und Blade escapt.
     * `fake()->company()` liefert irgendwann „Kerluke, Herman and D'Amore" —
     * daraus wird „D&#039;Amore" und der Rohvergleich scheitert. Der Test war
     * damit latent flackernd und hat CI am 2026-07-30 auf einem Commit rot
     * gemacht, der nur .gitignore anfasste.
     */
    // Nicht „Lehrjahr 2026": so heißt die Gruppe aus dem beforeEach, und die
    // Behauptung unten könnte deren Namen erwischen statt der ausgegebenen.
    $groups = Team::factory()->count(2)->sequence(
        ['name' => 'Lehrjahr 2027'],
        ['name' => 'Lehrjahr 2028'],
    )->create();

    $quiz->teams()->attach($groups->pluck('id'));

    $content = $this->actingAs($this->admin)
        ->get(route('admin.quizzes.index'))
        ->assertOk()
        ->getContent();

    expect(substr_count($content, 'data-test="quiz-teams-more-badge"'))->toBe(0)
        ->and($content)->toContain('Lehrjahr 2027')
        ->and($content)->toContain('Lehrjahr 2028');
});
