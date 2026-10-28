<?php

use App\Actions\Learning\RecordAnswer;
use App\Enums\QuestionType;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
    $this->path = LearningPath::factory()->published()->verified()->create();
    $this->quiz = Quiz::factory()->pathFinal($this->path)->published()->create([
        'title' => 'Abschlusstest',
        'pass_threshold' => 80,
    ]);
});

/**
 * Attach a single-choice question with one correct and one wrong option.
 *
 * @return array{0: Question, 1: QuestionOption, 2: QuestionOption} Frage, richtig, falsch
 */
function questionOn(Quiz $quiz, int $position): array
{
    $question = Question::factory()->create([
        'type' => QuestionType::Single,
        'prompt' => "Frage {$position}?",
        'explanation' => "Begründung {$position}.",
    ]);

    $correct = QuestionOption::factory()->for($question)->create([
        'label' => "Richtig {$position}", 'is_correct' => true, 'position' => 1,
    ]);

    $wrong = QuestionOption::factory()->for($question)->create([
        'label' => "Falsch {$position}", 'is_correct' => false, 'position' => 2,
    ]);

    $quiz->questions()->attach($question->id, ['position' => $position]);

    return [$question, $correct, $wrong];
}

function quizPage(User $learner, Quiz $quiz)
{
    return Livewire::actingAs($learner)->test('pages::learn.quizzes.show', ['quiz' => $quiz]);
}

test('opening the page creates no attempt', function () {
    questionOn($this->quiz, 1);

    quizPage($this->learner, $this->quiz)
        ->assertSee('Abschlusstest')
        ->call('start');

    expect(QuizAttempt::count())->toBe(0);
});

test('the first answer creates the attempt and the response', function () {
    [$question, $correct] = questionOn($this->quiz, 1);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer')
        ->assertSee('Richtig!');

    $attempt = QuizAttempt::sole();

    expect($attempt->user_id)->toBe($this->learner->id)
        ->and($attempt->quiz_id)->toBe($this->quiz->id)
        ->and($attempt->completed_at)->toBeNull()
        ->and($attempt->responses()->count())->toBe(1)
        ->and($attempt->responses()->sole()->is_correct)->toBeTrue()
        ->and($attempt->responses()->sole()->question_id)->toBe($question->id);
});

test('answering twice creates no second response and does not overwrite', function () {
    [$question, $correct, $wrong] = questionOn($this->quiz, 1);

    $component = quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$wrong->id])
        ->call('submitAnswer');

    // Zweiter Klick auf dieselbe Frage — Antworten sind endgültig.
    $component->set('selection', [$correct->id])->call('submitAnswer');

    $responses = QuestionResponse::all();

    expect($responses)->toHaveCount(1)
        ->and($responses->first()->is_correct)->toBeFalse()
        ->and($responses->first()->selected_option_ids)->toBe([$wrong->id]);
});

test('an option of a foreign question is rejected without a response', function () {
    questionOn($this->quiz, 1);

    $foreign = Question::factory()->singleChoice()->create();

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$foreign->options->first()->id])
        ->call('submitAnswer')
        ->assertHasErrors('selection');

    expect(QuizAttempt::count())->toBe(0)
        ->and(QuestionResponse::count())->toBe(0);
});

test('an empty selection is rejected', function () {
    questionOn($this->quiz, 1);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->call('submitAnswer')
        ->assertHasErrors('selection')
        ->assertSee('Bitte wähle eine Antwort aus.');

    expect(QuizAttempt::count())->toBe(0);
});

test('a reload resumes the attempt at the first unanswered question', function () {
    [, $firstCorrect] = questionOn($this->quiz, 1);
    questionOn($this->quiz, 2);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$firstCorrect->id])
        ->call('submitAnswer');

    // Neu geladene Seite: Einstieg steht auf Frage 2, der Stand wird genannt.
    quizPage($this->learner, $this->quiz)
        ->assertSet('index', 1)
        ->assertSee('Du hast 1 von 2 Fragen beantwortet.')
        ->assertSee('Versuch fortsetzen')
        ->call('start')
        ->assertSee('Frage 2 von 2');
});

test('a foreign open attempt is never adopted', function () {
    [, $correct] = questionOn($this->quiz, 1);

    $foreignAttempt = QuizAttempt::factory()->inProgress()->create([
        'user_id' => User::factory()->apprentice()->create()->id,
        'quiz_id' => $this->quiz->id,
    ]);

    quizPage($this->learner, $this->quiz)
        ->assertDontSee('Du hast')
        ->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer');

    expect(QuizAttempt::count())->toBe(2)
        ->and($foreignAttempt->refresh()->responses()->count())->toBe(0);
});

test('finishing writes score, max score, passed and the completion time', function () {
    [, $firstCorrect] = questionOn($this->quiz, 1);
    [, , $secondWrong] = questionOn($this->quiz, 2);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$firstCorrect->id])
        ->call('submitAnswer')
        ->call('next')
        ->set('selection', [$secondWrong->id])
        ->call('submitAnswer')
        ->call('next')
        ->assertSee('Noch nicht bestanden.')
        ->assertSee('1 von 2 richtig (50 %)');

    $attempt = QuizAttempt::sole();

    expect($attempt->score)->toBe(1)
        ->and($attempt->max_score)->toBe(2)
        ->and($attempt->passed)->toBeFalse()
        ->and($attempt->completed_at)->not->toBeNull();
});

test('exactly at the threshold the attempt passes', function () {
    // 4 von 5 = 80 % bei einer Schwelle von 80 %.
    $corrects = [];

    foreach (range(1, 5) as $position) {
        [, $correct, $wrong] = questionOn($this->quiz, $position);
        $corrects[$position] = $position === 5 ? $wrong : $correct;
    }

    $component = quizPage($this->learner, $this->quiz)->call('start');

    foreach (range(1, 5) as $position) {
        $component->set('selection', [$corrects[$position]->id])
            ->call('submitAnswer')
            ->call('next');
    }

    $component->assertSee('Bestanden!')->assertSee('4 von 5 richtig (80 %)');

    expect(QuizAttempt::sole()->passed)->toBeTrue();
});

test('one point below the threshold the attempt fails', function () {
    // 3 von 4 = 75 % bei einer Schwelle von 80 %.
    $chosen = [];

    foreach (range(1, 4) as $position) {
        [, $correct, $wrong] = questionOn($this->quiz, $position);
        $chosen[$position] = $position === 4 ? $wrong : $correct;
    }

    $component = quizPage($this->learner, $this->quiz)->call('start');

    foreach (range(1, 4) as $position) {
        $component->set('selection', [$chosen[$position]->id])
            ->call('submitAnswer')
            ->call('next');
    }

    $component->assertSee('Noch nicht bestanden.');

    expect(QuizAttempt::sole()->passed)->toBeFalse();
});

test('the result lists the wrong questions with the correct answer and the explanation', function () {
    [, $correct] = questionOn($this->quiz, 1);
    [, , $wrong] = questionOn($this->quiz, 2);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer')
        ->call('next')
        ->set('selection', [$wrong->id])
        ->call('submitAnswer')
        ->call('next')
        ->assertSee('Das lohnt sich noch einmal anzuschauen')
        ->assertSee('Frage 2?')
        ->assertSee('Richtige Antwort: Richtig 2')
        ->assertSee('Begründung 2.')
        // Die richtig beantwortete Frage taucht in der Liste nicht auf.
        ->assertDontSee('Begründung 1.');
});

test('a question removed mid-attempt cannot push the score above the maximum', function () {
    // Begründung für „gegen die aktuellen Fragen werten" statt gegen eine
    // eingefrorene Liste: sonst entstünde hier score > max_score und damit
    // ein Prozentwert über 100.
    [$first, $firstCorrect] = questionOn($this->quiz, 1);
    [, $secondCorrect] = questionOn($this->quiz, 2);

    $component = quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$firstCorrect->id])
        ->call('submitAnswer')
        ->call('next')
        ->set('selection', [$secondCorrect->id])
        ->call('submitAnswer');

    // Der Admin nimmt die erste Frage währenddessen aus dem Quiz.
    $this->quiz->questions()->detach($first->id);

    $component->call('next');

    $attempt = QuizAttempt::sole();

    expect($attempt->max_score)->toBe(1)
        ->and($attempt->score)->toBe(1)
        ->and($attempt->passed)->toBeTrue();
});

test('a second run creates a second attempt', function () {
    [, $correct] = questionOn($this->quiz, 1);

    $component = quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer')
        ->call('next')
        ->assertSee('Bestanden!');

    // „Nochmal versuchen" setzt nur den Seitenzustand zurück; der neue
    // Versuch entsteht wieder erst mit der ersten Antwort.
    $component->call('restart');

    expect(QuizAttempt::count())->toBe(1);

    $component->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer');

    expect(QuizAttempt::count())->toBe(2);
});

test('browsing back shows the answered question read-only', function () {
    [, $firstWrong] = questionOn($this->quiz, 1);
    [, $secondCorrect] = questionOn($this->quiz, 2);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$firstWrong->id])
        ->call('submitAnswer')
        ->call('next')
        ->assertSee('Frage 2 von 2')
        ->call('previous')
        ->assertSee('Frage 1 von 2')
        // Statt eines Abgabe-Knopfes steht dort nur noch der Weg vorwärts.
        ->assertDontSee('Antwort abgeben')
        ->assertSee('Weiter');
});

test('a quiz without questions offers no start', function () {
    quizPage($this->learner, $this->quiz)
        ->assertSee('Dieses Quiz hat noch keine Fragen.')
        ->assertDontSee('Quiz starten')
        ->call('start')
        ->assertSet('started', false);

    expect(QuizAttempt::count())->toBe(0);
});

test('an apprentice is forbidden on an unpublished quiz', function () {
    $draft = Quiz::factory()->pathFinal($this->path)->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.quizzes.show', $this->learner, ['quiz' => $draft]))
        ->assertForbidden();
});

test('an apprentice is forbidden on a quiz of an unreleased path', function () {
    $draftPath = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($draftPath)->published()->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.quizzes.show', $this->learner, ['quiz' => $quiz]))
        ->assertForbidden();
});

test('correct answers never appear in the html before the evaluation', function () {
    questionOn($this->quiz, 1);

    $html = quizPage($this->learner, $this->quiz)
        ->call('start')
        // Beide Optionen stehen da — aber nichts verrät, welche stimmt.
        ->assertSee('Richtig 1')
        ->assertSee('Falsch 1')
        ->assertDontSee('is_correct')
        ->assertDontSee('Richtige Antwort:')
        ->assertDontSee('data-correct')
        ->html();

    // Kein checked-Attribut markiert die richtige Option vor. Die Prüfung
    // sucht das Attribut, nicht die Zeichenkette: Tailwinds Varianten-Klasse
    // `data-checked:` steht in jedem Options-Markup.
    expect(preg_match('/\schecked(\s|>|=)/', $html))->toBe(0);
});

test('the database refuses a second running attempt on the same quiz', function () {
    QuizAttempt::factory()->inProgress()->for($this->learner)->for($this->quiz)->create();

    // Der partielle Index ist die eigentliche Garantie — nicht die Disziplin in
    // RecordAnswer. Zwei Tabs desselben Lehrlings könnten die umgehen, ihn nicht.
    expect(fn () => QuizAttempt::factory()->inProgress()->for($this->learner)->for($this->quiz)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('completed attempts on the same quiz stay unlimited', function () {
    // Die Auswertung lebt davon: bester Versuch, Anzahl Durchgänge. Nur der
    // laufende Versuch ist eingeschränkt.
    QuizAttempt::factory()->count(3)->for($this->learner)->for($this->quiz)->create();

    expect(QuizAttempt::where('quiz_id', $this->quiz->id)->count())->toBe(3);
});

test('the loser of a race gets the running attempt instead of an error', function () {
    $running = QuizAttempt::factory()->inProgress()->for($this->learner)->for($this->quiz)->create();

    // `createOrFirst` überspringt das führende SELECT und läuft damit wirklich
    // in den Index — genau das Fenster, das zwei parallele erste Antworten
    // haben. `firstOrCreate` in RecordAnswer nimmt denselben Weg, sobald die
    // vorgeschaltete Suche ins Leere greift.
    $loser = QuizAttempt::createOrFirst(
        ['user_id' => $this->learner->id, 'quiz_id' => $this->quiz->id, 'completed_at' => null],
        ['score' => 0, 'max_score' => 0, 'passed' => false, 'started_at' => now()],
    );

    expect($loser->id)->toBe($running->id)
        ->and(QuizAttempt::where('quiz_id', $this->quiz->id)->count())->toBe(1);
});

test('the loser of a race on the same question keeps the first answer', function () {
    [$question, $correct, $wrong] = questionOn($this->quiz, 1);

    $attempt = app(RecordAnswer::class)->handle(
        $this->learner,
        $this->quiz,
        $question,
        [$correct->id],
    );

    // Zweiter Tab, dieselbe Frage: ohne das Abfangen der Verletzung endete das
    // in einem 500 statt in der stillen Rückgabe (Entscheidung 1).
    $loser = $attempt->responses()->createOrFirst(
        ['question_id' => $question->id],
        ['selected_option_ids' => [$wrong->id], 'is_correct' => false],
    );

    expect($loser->is_correct)->toBeTrue()
        ->and($loser->selected_option_ids)->toBe([$correct->id])
        ->and($attempt->responses()->count())->toBe(1);
});

test('result actions appear above and below the wrong questions list', function () {
    [, $correct] = questionOn($this->quiz, 1);
    [, , $wrong] = questionOn($this->quiz, 2);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer')
        ->call('next')
        ->set('selection', [$wrong->id])
        ->call('submitAnswer')
        ->call('next')
        ->assertSee('data-test="result-actions-top"', escape: false)
        ->assertSee('data-test="result-actions-bottom"', escape: false)
        // Auch die Knöpfe selbst tragen eindeutige Namen: ein Selektor auf
        // „restart" träfe sonst zwei Elemente, was einen Browser-Test in
        // Strict Mode scheitern lässt.
        ->assertSee('data-test="result-actions-top-restart"', escape: false)
        ->assertSee('data-test="result-actions-top-back"', escape: false)
        ->assertSee('data-test="result-actions-bottom-restart"', escape: false)
        ->assertSee('data-test="result-actions-bottom-back"', escape: false);
});

test('a flawless result shows only the bottom action block', function () {
    [, $correct] = questionOn($this->quiz, 1);

    quizPage($this->learner, $this->quiz)
        ->call('start')
        ->set('selection', [$correct->id])
        ->call('submitAnswer')
        ->call('next')
        ->assertSee('data-test="result-actions-bottom"', escape: false)
        ->assertDontSee('data-test="result-actions-top"', escape: false);
});

test('a second tab joins the running attempt instead of starting another', function () {
    [$first, $firstCorrect] = questionOn($this->quiz, 1);
    [$second, $secondCorrect] = questionOn($this->quiz, 2);

    $attempt = app(RecordAnswer::class)->handle(
        $this->learner,
        $this->quiz,
        $first,
        [$firstCorrect->id],
    );

    // Der zweite Tab kennt den laufenden Versuch nicht und übergibt null.
    // Vorher legte RecordAnswer dafür bedingungslos einen zweiten an.
    $joined = app(RecordAnswer::class)->handle(
        $this->learner,
        $this->quiz,
        $second,
        [$secondCorrect->id],
    );

    expect($joined->id)->toBe($attempt->id)
        ->and(QuizAttempt::count())->toBe(1)
        ->and($attempt->responses()->count())->toBe(2);
});
