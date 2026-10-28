<?php

use App\Enums\QuestionType;
use App\Models\LearningPath;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Livewire\Livewire;

/*
 * Sprint 5.5, Grundlage + Feature 1: „der Versuch kennt seine eigene
 * Fragenliste". Die Liste steht in `quiz_attempts.question_order` und ist
 * bewusst nullable — ungemischte Abschluss-Quizze behalten das
 * Sprint-4-Verhalten und werden gegen den aktuellen Quiz-Stand gewertet.
 */

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
    $this->path = LearningPath::factory()->published()->verified()->create();
});

/**
 * Attach a single-choice question, returning it with its correct option.
 *
 * @return array{0: Question, 1: QuestionOption}
 */
function orderedQuestionOn(Quiz $quiz, int $position): array
{
    $question = Question::factory()->create([
        'type' => QuestionType::Single,
        'prompt' => "Frage {$position}?",
    ]);

    $correct = QuestionOption::factory()->for($question)->create([
        'label' => "Richtig {$position}", 'is_correct' => true, 'position' => 1,
    ]);

    QuestionOption::factory()->for($question)->create([
        'label' => "Falsch {$position}", 'is_correct' => false, 'position' => 2,
    ]);

    $quiz->questions()->attach($question->id, ['position' => $position]);

    return [$question, $correct];
}

/**
 * Build a published quiz with the given number of questions.
 *
 * @return array{0: Quiz, 1: array<int, Question>, 2: array<int, QuestionOption>}
 */
function quizWithQuestions(LearningPath $path, int $count, bool $shuffle = false): array
{
    $quiz = Quiz::factory()->pathFinal($path)->published()->create([
        'title' => 'Abschlusstest',
        'pass_threshold' => 50,
        'shuffle_questions' => $shuffle,
    ]);

    $questions = [];
    $corrects = [];

    foreach (range(1, $count) as $position) {
        [$question, $correct] = orderedQuestionOn($quiz, $position);
        $questions[] = $question;
        $corrects[] = $correct;
    }

    return [$quiz, $questions, $corrects];
}

function orderPage(User $learner, Quiz $quiz)
{
    return Livewire::actingAs($learner)->test('pages::learn.quizzes.show', ['quiz' => $quiz]);
}

test('an unshuffled quiz stores no question order', function () {
    [$quiz, , $corrects] = quizWithQuestions($this->path, 2);

    orderPage($this->learner, $quiz)
        ->call('start')
        ->set('selection', [$corrects[0]->id])
        ->call('submitAnswer');

    // null heißt „nimm die aktuelle Pivot-Reihenfolge" — kein Backfill nötig,
    // Sprint-4-Versuche verhalten sich unverändert.
    expect(QuizAttempt::sole()->question_order)->toBeNull();
});

test('a shuffled quiz freezes its own question list on the first answer', function () {
    [$quiz, $questions, $corrects] = quizWithQuestions($this->path, 4, shuffle: true);

    $component = orderPage($this->learner, $quiz)->call('start');

    // Nach dem Mischen steht vorne nicht mehr zwangsläufig Frage 1 — die
    // Auswahl muss zu der Frage passen, die gerade dran ist.
    $correctById = collect($questions)->pluck('id')->combine(collect($corrects)->pluck('id'));

    $component
        ->set('selection', [$correctById->get($component->get('questionOrder')[0])])
        ->call('submitAnswer');

    $order = QuizAttempt::sole()->question_order;

    expect($order)->toBeArray()
        ->and($order)->toHaveCount(4)
        // Eine echte Permutation: dieselben Fragen, nur anders sortiert.
        ->and(collect($order)->sort()->values()->all())
        ->toBe(collect($questions)->pluck('id')->sort()->values()->all());
});

test('the shuffled order stays the same across reloads of a running attempt', function () {
    [$quiz, $questions, $corrects] = quizWithQuestions($this->path, 6, shuffle: true);

    $component = orderPage($this->learner, $quiz)->call('start');

    $correctById = collect($questions)->pluck('id')->combine(collect($corrects)->pluck('id'));

    $component
        ->set('selection', [$correctById->get($component->get('questionOrder')[0])])
        ->call('submitAnswer');

    $stored = QuizAttempt::sole()->question_order;

    // Neuer Seitenaufruf = neue Komponente: die Reihenfolge kommt jetzt aus
    // dem Versuch, nicht aus einem frischen shuffle().
    $resumed = orderPage($this->learner, $quiz)->call('start');

    expect($resumed->get('questionOrder'))->toBe($stored);

    $secondReload = orderPage($this->learner, $quiz)->call('start');

    expect($secondReload->get('questionOrder'))->toBe($stored);
});

test('two runs of a shuffled quiz do not all get the same order', function () {
    [$quiz] = quizWithQuestions($this->path, 8, shuffle: true);

    // Fünf Anläufe bei 8! möglichen Reihenfolgen — dass alle fünf gleich
    // ausfallen, ist praktisch ausgeschlossen (rund 1 zu 2,6 · 10^18).
    $orders = collect(range(1, 5))->map(
        fn (): array => orderPage($this->learner, $quiz)->call('start')->get('questionOrder'),
    );

    expect($orders->unique(fn (array $order): string => implode(',', $order)))
        ->not->toHaveCount(1);
});

test('an unshuffled quiz keeps the curated pivot order', function () {
    [$quiz, $questions] = quizWithQuestions($this->path, 3);

    $component = orderPage($this->learner, $quiz)->call('start');

    // Leer heißt „Pivot-Reihenfolge" — die kuratierte Abfolge des Admins.
    expect($component->get('questionOrder'))->toBe([]);

    $component->assertSee($questions[0]->prompt);
});

test('a shuffled attempt is scored against its own list, not the changed quiz', function () {
    [$quiz, $questions, $corrects] = quizWithQuestions($this->path, 3, shuffle: true);

    $component = orderPage($this->learner, $quiz)->call('start');

    // In der gemischten Reihenfolge durchspielen und alles richtig beantworten.
    $order = $component->get('questionOrder');
    $correctById = collect($questions)->pluck('id')->combine(collect($corrects)->pluck('id'));

    foreach ($order as $position => $questionId) {
        $component->set('selection', [$correctById->get($questionId)])->call('submitAnswer');

        if ($position < count($order) - 1) {
            $component->call('next');
        }
    }

    // Der Admin hängt dem Quiz kurz vor dem Abschluss eine vierte Frage an.
    orderedQuestionOn($quiz, 4);

    $component->call('next');

    $attempt = QuizAttempt::sole();

    // Gewertet wird gegen die eingefrorenen drei, nicht gegen die neuen vier —
    // sonst stünde hier 3 von 4 für einen fehlerfreien Durchgang.
    expect($attempt->max_score)->toBe(3)
        ->and($attempt->score)->toBe(3)
        ->and($attempt->passed)->toBeTrue();
});

test('a question deleted mid-attempt drops out of the frozen list', function () {
    [$quiz, $questions, $corrects] = quizWithQuestions($this->path, 3, shuffle: true);

    $component = orderPage($this->learner, $quiz)->call('start');

    $order = $component->get('questionOrder');
    $correctById = collect($questions)->pluck('id')->combine(collect($corrects)->pluck('id'));

    foreach ($order as $position => $questionId) {
        $component->set('selection', [$correctById->get($questionId)])->call('submitAnswer');

        if ($position < count($order) - 1) {
            $component->call('next');
        }
    }

    // Die Frage verschwindet aus dem Pool, nicht nur aus dem Quiz.
    $questions[0]->delete();

    $component->call('next');

    $attempt = QuizAttempt::sole();

    // max_score zählt nur noch die Fragen, die es gibt: eine gelöschte Frage
    // darf dem Lehrling nicht als Fehler angerechnet werden.
    expect($attempt->max_score)->toBe(2)
        ->and($attempt->score)->toBe(2);
});

test('restarting a shuffled quiz draws a fresh order', function () {
    [$quiz, $questions, $corrects] = quizWithQuestions($this->path, 4, shuffle: true);

    $component = orderPage($this->learner, $quiz)->call('start');

    $correctById = collect($questions)->pluck('id')->combine(collect($corrects)->pluck('id'));

    expect($component->get('questionOrder'))->toHaveCount(4);

    $component->call('restart');

    // Nach dem Zurücksetzen steht wieder „noch nicht entschieden" —
    // start() zieht dann neu.
    expect($component->get('questionOrder'))->toBe([]);

    $component->call('start');

    expect($component->get('questionOrder'))->toHaveCount(4);

    // Und der zweite Versuch schreibt seine eigene Liste fest.
    $component
        ->set('selection', [$correctById->get($component->get('questionOrder')[0])])
        ->call('submitAnswer');

    expect(QuizAttempt::sole()->question_order)
        ->toBe($component->get('questionOrder'));
});
