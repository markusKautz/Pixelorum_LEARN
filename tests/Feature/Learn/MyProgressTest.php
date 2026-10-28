<?php

use App\Enums\QuizKind;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->group = Team::factory()->create();
    $this->learner = User::factory()->apprentice($this->group)->create();
});

/**
 * Answer the question wrong once inside a completed attempt of the quiz.
 */
function answerWrongOnce(User $learner, Quiz $quiz, Question $question): QuizAttempt
{
    $attempt = QuizAttempt::factory()->failed()->create([
        'user_id' => $learner->id,
        'quiz_id' => $quiz->id,
    ]);

    QuestionResponse::factory()->for($attempt, 'quizAttempt')->for($question)->create([
        'is_correct' => false,
    ]);

    return $attempt;
}

test('the page shows the progress stats, weak spots and attempts', function () {
    $path = LearningPath::factory()->published()->verified()->create(['title' => 'Netzwerkgrundlagen']);
    $lessons = Lesson::factory()->for($path)->count(2)->sequence(
        ['position' => 1], ['position' => 2],
    )->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create(['title' => 'Abschluss Netzwerke']);

    LessonCompletion::factory()->create([
        'user_id' => $this->learner->id,
        'lesson_id' => $lessons[0]->id,
    ]);

    $question = Question::factory()->singleChoice()->for($path)->create([
        'prompt' => 'Wofür steht DNS?',
        'explanation' => 'DNS übersetzt Namen in Adressen.',
    ]);

    answerWrongOnce($this->learner, $quiz, $question);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.progress', $this->learner))
        ->assertOk()
        ->assertSee('Ø gelesen')
        ->assertSee('50 %')
        ->assertSee('Lernpfade abgeschlossen')
        // Zähler und Nenner zusammen — ein blankes „0" stünde auf jeder Seite
        // irgendwo und wäre keine Aussage.
        ->assertSee('0 von 1 fertig')
        ->assertSee('Wofür steht DNS?')
        ->assertSee('DNS übersetzt Namen in Adressen.')
        ->assertSee('Abschluss Netzwerke');
});

test('the page never shows another apprentices data', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create(['title' => 'Fremdes Quiz']);

    $question = Question::factory()->singleChoice()->for($path)->create([
        'prompt' => 'Fremde Schwachstelle?',
        'explanation' => 'Fremde Erklärung.',
    ]);

    $other = User::factory()->apprentice($this->group)->create();

    answerWrongOnce($other, $quiz, $question);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.progress', $this->learner))
        ->assertOk()
        ->assertDontSee('Fremde Schwachstelle?')
        ->assertDontSee('Fremde Erklärung.')
        ->assertSee('Noch keine Schwachstellen. Weiter so.')
        ->assertSee('Noch kein Quiz abgelegt.');
});

test('the correct answer is shown with the weak spot', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $question = Question::factory()->for($path)->create(['prompt' => 'Welcher Port gehört zu HTTPS?']);

    QuestionOption::factory()->for($question)->create(['label' => 'Port 443', 'is_correct' => true, 'position' => 1]);
    QuestionOption::factory()->for($question)->create(['label' => 'Port 21', 'is_correct' => false, 'position' => 2]);

    answerWrongOnce($this->learner, $quiz, $question);

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.progress', $this->learner))
        ->assertOk()
        ->assertSee('Richtige Antwort')
        ->assertSee('Port 443');
});

test('a failed attempt is shown in amber rather than red', function () {
    // Merkmal 3: ein misslungener Versuch ist eine Zwischenstation, kein
    // Verstoß — das Quiz ist beliebig wiederholbar.
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    QuizAttempt::factory()->failed()->create([
        'user_id' => $this->learner->id,
        'quiz_id' => $quiz->id,
    ]);

    $content = $this->actingAs($this->learner)
        ->get(learnRoute('learn.progress', $this->learner))
        ->assertOk()
        ->assertSee('4 von 10 (40 %)')
        ->getContent();

    // Flux färbt Badges über Utility-Klassen — `text-red-700` wäre der Beleg
    // für ein rotes Badge auf der Seite.
    expect($content)->toContain('text-amber-700')
        ->and($content)->not->toContain('text-red-700');
});

test('the dashboard card appears only when there are weak spots', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertDontSee('Das solltest du üben');

    $question = Question::factory()->singleChoice()->for($path)->create(['prompt' => 'Was ist ein Gateway?']);

    answerWrongOnce($this->learner, $quiz, $question);

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Das solltest du üben')
        ->assertSee('Was ist ein Gateway?')
        ->assertSee(learnRoute('learn.progress', $this->learner), escape: false);
});

test('the dashboard card lists at most the top three weak spots', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    foreach (range(1, 4) as $index) {
        $question = Question::factory()->singleChoice()->for($path)->create([
            'prompt' => "Schwachstelle Nummer {$index}?",
        ]);

        // Absteigende Fehlerzahl legt die Reihenfolge fest.
        foreach (range(1, 5 - $index) as $ignored) {
            answerWrongOnce($this->learner, $quiz, $question);
        }
    }

    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Schwachstelle Nummer 1?')
        ->assertSee('Schwachstelle Nummer 3?')
        ->assertDontSee('Schwachstelle Nummer 4?');
});

test('the sidebar offers my progress to apprentices but not to admins', function () {
    $this->actingAs($this->learner)
        ->get(learnRoute('dashboard', $this->learner))
        ->assertOk()
        ->assertSee('Mein Fortschritt');

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.groups.index'))
        ->assertOk()
        ->assertDontSee('Mein Fortschritt');
});

test('only the five most recent attempts are shown', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    foreach (range(1, 6) as $index) {
        QuizAttempt::factory()->passed()->create([
            'user_id' => $this->learner->id,
            'quiz_id' => $quiz->id,
            'completed_at' => now()->subDays(6 - $index),
        ]);
    }

    $content = $this->actingAs($this->learner)
        ->get(learnRoute('learn.progress', $this->learner))
        ->assertOk()
        ->assertSee('Letzte Versuche')
        ->getContent();

    expect(substr_count($content, 'data-test="my-attempt-row"'))->toBe(5);
});

test('practising a weak spot builds a round from its path and pins the question', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $weakQuestion = Question::factory()->singleChoice()->for($path)->create();
    QuestionOption::factory()->for($weakQuestion)->create(['is_correct' => true, 'position' => 1]);
    QuestionOption::factory()->for($weakQuestion)->create(['is_correct' => false, 'position' => 2]);

    foreach (range(1, 4) as $ignored) {
        $q = Question::factory()->singleChoice()->for($path)->create();
        QuestionOption::factory()->for($q)->create(['is_correct' => true, 'position' => 1]);
        QuestionOption::factory()->for($q)->create(['is_correct' => false, 'position' => 2]);
    }

    answerWrongOnce($this->learner, $quiz, $weakQuestion);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.progress')
        ->call('practiseAgain', $weakQuestion->id)
        ->assertRedirect();

    $practiceQuiz = Quiz::where('kind', QuizKind::Practice)->sole();
    $questionIds = $practiceQuiz->questions()->pluck('questions.id')->all();

    expect($questionIds)->toContain($weakQuestion->id)
        ->and(count($questionIds))->toBeLessThanOrEqual(5);
});

test('practising a weak spot keeps the pinned question even when the path has fewer than ten', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $weakQuestion = Question::factory()->singleChoice()->for($path)->create();
    QuestionOption::factory()->for($weakQuestion)->create(['is_correct' => true, 'position' => 1]);
    QuestionOption::factory()->for($weakQuestion)->create(['is_correct' => false, 'position' => 2]);

    answerWrongOnce($this->learner, $quiz, $weakQuestion);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.progress')
        ->call('practiseAgain', $weakQuestion->id)
        ->assertRedirect();

    $practiceQuiz = Quiz::where('kind', QuizKind::Practice)->sole();

    expect($practiceQuiz->questions()->pluck('questions.id')->all())->toContain($weakQuestion->id)
        ->and($practiceQuiz->questions()->count())->toBe(1);
});

test('a weak spot without a path falls back to the quiz of the latest wrong attempt', function () {
    $path = LearningPath::factory()->published()->verified()->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();

    $pooled = Question::factory()->singleChoice()->create();
    QuestionOption::factory()->for($pooled)->create(['is_correct' => true, 'position' => 1]);
    QuestionOption::factory()->for($pooled)->create(['is_correct' => false, 'position' => 2]);

    $quiz->questions()->attach($pooled->id, ['position' => 1]);
    answerWrongOnce($this->learner, $quiz, $pooled);

    Livewire::actingAs($this->learner)
        ->test('pages::learn.progress')
        ->call('practiseAgain', $pooled->id)
        ->assertRedirect(route('learn.quizzes.show', $quiz));

    expect(Quiz::where('kind', QuizKind::Practice)->count())->toBe(0);
});

test('my progress stays within its query budget', function () {
    foreach (range(1, 3) as $index) {
        $path = LearningPath::factory()->published()->verified()->create(['position' => $index]);
        Lesson::factory()->for($path)->count(3)->create();
        $quiz = Quiz::factory()->pathFinal($path)->published()->create();

        foreach (range(1, 3) as $questionIndex) {
            $question = Question::factory()->singleChoice()->for($path)->create();

            answerWrongOnce($this->learner, $quiz, $question);
        }
    }

    $this->actingAs($this->learner)->get(learnRoute('learn.progress', $this->learner))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs($this->learner)->get(learnRoute('learn.progress', $this->learner))->assertOk();

    expect($queries)->toBeLessThan(20);
});
