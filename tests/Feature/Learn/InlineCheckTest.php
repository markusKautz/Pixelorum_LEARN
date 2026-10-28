<?php

use App\Enums\LessonBlockType;
use App\Enums\QuestionType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionResponse;
use App\Models\QuizAttempt;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
    $this->path = LearningPath::factory()->published()->verified()->create();
    $this->lesson = Lesson::factory()->for($this->path)->create(['position' => 1]);

    LessonBlock::factory()->for($this->lesson)->create([
        'type' => LessonBlockType::Markdown,
        'content' => ['body' => 'Der Lektionstext'],
        'position' => 1,
    ]);

    $this->question = Question::factory()->create([
        'lesson_id' => $this->lesson->id,
        'learning_path_id' => $this->path->id,
        'type' => QuestionType::Single,
        'prompt' => 'Was ist eine Variable?',
        'explanation' => 'Eine Variable ist ein benannter Platzhalter.',
    ]);

    $this->wrongOption = QuestionOption::factory()->for($this->question)->create([
        'label' => 'Eine Konstante', 'is_correct' => false, 'position' => 1,
    ]);

    $this->correctOption = QuestionOption::factory()->for($this->question)->create([
        'label' => 'Ein benannter Platzhalter', 'is_correct' => true, 'position' => 2,
    ]);
});

function readerWithChecks(User $learner, LearningPath $path, Lesson $lesson)
{
    return Livewire::actingAs($learner)->test('pages::learn.lessons.show', [
        'learningPath' => $path,
        'lesson' => $lesson,
    ]);
}

test('the inline check is shown at the end of the lesson', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->assertSee('Zwischen-Check')
        ->assertSee('Was ist eine Variable?')
        ->assertSee('Nur für dich: Diese Fragen zählen in keiner Quiz-Wertung.');
});

test('inline checks appear only on the last step', function () {
    LessonBlock::factory()->for($this->lesson)->create([
        'type' => LessonBlockType::SectionBreak,
        'content' => ['title' => 'Vertiefung'],
        'position' => 2,
    ]);

    LessonBlock::factory()->for($this->lesson)->create([
        'type' => LessonBlockType::Markdown,
        'content' => ['body' => 'Zweiter Abschnitt'],
        'position' => 3,
    ]);

    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->assertDontSee('Was ist eine Variable?')
        ->call('nextStep')
        ->assertSee('Was ist eine Variable?');
});

test('a wrong answer shows the explanation', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$this->question->id}", [$this->wrongOption->id])
        ->call('submitCheck', $this->question->id)
        ->assertSet("checkResult.{$this->question->id}", false)
        ->assertSee('Noch nicht ganz.')
        ->assertSee('Eine Variable ist ein benannter Platzhalter.');
});

test('a correct answer is confirmed', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$this->question->id}", [$this->correctOption->id])
        ->call('submitCheck', $this->question->id)
        ->assertSet("checkResult.{$this->question->id}", true)
        ->assertSee('Richtig!')
        ->assertSee('Eine Variable ist ein benannter Platzhalter.')
        ->assertDontSee('Warum?');
});

test('an inline check creates neither an attempt nor a response', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$this->question->id}", [$this->correctOption->id])
        ->call('submitCheck', $this->question->id)
        ->set("checkSelection.{$this->question->id}", [$this->wrongOption->id])
        ->call('submitCheck', $this->question->id);

    expect(QuizAttempt::count())->toBe(0)
        ->and(QuestionResponse::count())->toBe(0);
});

test('an empty selection is rejected with a hint', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->call('submitCheck', $this->question->id)
        ->assertHasErrors("checkSelection.{$this->question->id}")
        ->assertSee('Bitte wähle eine Antwort aus.');
});

test('an option of a foreign question is rejected', function () {
    $foreign = Question::factory()->singleChoice()->create();

    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$this->question->id}", [$foreign->options->first()->id])
        ->call('submitCheck', $this->question->id)
        ->assertHasErrors("checkSelection.{$this->question->id}")
        // Kein Ergebnis — die Frage bleibt unbeantwortet, statt als „falsch"
        // gewertet zu werden.
        ->assertSet('checkResult', []);
});

test('a single choice question rejects a multiple selection', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$this->question->id}", [$this->wrongOption->id, $this->correctOption->id])
        ->call('submitCheck', $this->question->id)
        ->assertHasErrors("checkSelection.{$this->question->id}");
});

test('try again clears the local state only', function () {
    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$this->question->id}", [$this->wrongOption->id])
        ->call('submitCheck', $this->question->id)
        ->assertSee('Noch nicht ganz.')
        ->call('retryCheck', $this->question->id)
        ->assertSet('checkResult', [])
        ->assertSet('checkSelection', [])
        ->assertDontSee('Noch nicht ganz.');
});

test('correct flags never reach the rendered html before the evaluation', function () {
    $response = readerWithChecks($this->learner, $this->path, $this->lesson);

    // Weder das Flag selbst noch eine Markierung an der richtigen Option.
    $response->assertDontSee('is_correct')
        ->assertDontSee('Richtige Antwort:')
        ->assertDontSee('data-correct');
});

test('a multiple choice check requires the exact set', function () {
    $question = Question::factory()->create([
        'lesson_id' => $this->lesson->id,
        'learning_path_id' => $this->path->id,
        'type' => QuestionType::Multiple,
        'prompt' => 'Welche Aussagen stimmen?',
        'position' => 2,
    ]);

    $first = QuestionOption::factory()->for($question)->create(['is_correct' => true, 'position' => 1]);
    $second = QuestionOption::factory()->for($question)->create(['is_correct' => true, 'position' => 2]);
    QuestionOption::factory()->for($question)->create(['is_correct' => false, 'position' => 3]);

    readerWithChecks($this->learner, $this->path, $this->lesson)
        ->set("checkSelection.{$question->id}", [$first->id])
        ->call('submitCheck', $question->id)
        ->assertSet("checkResult.{$question->id}", false)
        ->call('retryCheck', $question->id)
        ->set("checkSelection.{$question->id}", [$first->id, $second->id])
        ->call('submitCheck', $question->id)
        ->assertSet("checkResult.{$question->id}", true);
});
