<?php

use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->path = LearningPath::factory()->create();
});

test('creating a lesson appends it with a compact position', function () {
    Lesson::factory()->for($this->path)->create(['position' => 4]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $this->path])
        ->set('newLessonTitle', 'Neue Lektion')
        ->call('createLesson')
        ->assertHasNoErrors();

    $lesson = $this->path->lessons()->firstWhere('title', 'Neue Lektion');

    expect($lesson)->not->toBeNull()
        ->and($lesson->position)->toBe(5);
});

test('renaming a lesson persists the new title', function () {
    $lesson = Lesson::factory()->for($this->path)->create(['title' => 'Alter Titel']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $this->path])
        ->call('startRenamingLesson', $lesson->id)
        ->assertSet('editingLessonTitle', 'Alter Titel')
        ->set('editingLessonTitle', 'Neuer Titel')
        ->call('renameLesson')
        ->assertHasNoErrors();

    expect($lesson->refresh()->title)->toBe('Neuer Titel');
});

test('sorting lessons writes compact one-based positions', function () {
    $first = Lesson::factory()->for($this->path)->create(['position' => 1]);
    $second = Lesson::factory()->for($this->path)->create(['position' => 2]);
    $third = Lesson::factory()->for($this->path)->create(['position' => 3]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $this->path])
        ->call('sortLessons', $third->id, 0);

    expect($third->refresh()->position)->toBe(1)
        ->and($first->refresh()->position)->toBe(2)
        ->and($second->refresh()->position)->toBe(3);
});

test('deleting a lesson moves its check questions into the pool without collisions', function () {
    $lesson = Lesson::factory()->for($this->path)->create(['position' => 1]);
    $otherLesson = Lesson::factory()->for($this->path)->create(['position' => 2]);

    LessonBlock::factory()->for($lesson)->count(2)->create();

    // Pool-Fragen des Pfades auf den Positionen 1–2
    $poolQuestions = collect([1, 2])->map(
        fn (int $position) => Question::factory()
            ->for($this->path, 'learningPath')
            ->singleChoice()
            ->create(['position' => $position])
    );

    // Zwischen-Checks der zu löschenden Lektion — Positionen kollidieren mit dem Pool
    $checkOne = Question::factory()->asLessonCheck($lesson)->singleChoice()->create(['position' => 1]);
    $checkTwo = Question::factory()->asLessonCheck($lesson)->singleChoice()->create(['position' => 2]);

    // Zwischen-Check einer anderen Lektion bleibt unberührt
    $foreignCheck = Question::factory()->asLessonCheck($otherLesson)->singleChoice()->create(['position' => 1]);

    // Quiz-Zuordnung einer umziehenden Frage bleibt bestehen
    $quiz = Quiz::factory()->create();
    $quiz->questions()->attach($checkOne->id, ['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $this->path])
        ->call('deleteLesson', $lesson->id);

    expect(Lesson::find($lesson->id))->toBeNull()
        ->and(LessonBlock::where('lesson_id', $lesson->id)->count())->toBe(0);

    // Umgezogene Checks: lesson_id genullt, kollisionsfreie Pool-Positionen 3 und 4
    expect($checkOne->refresh()->lesson_id)->toBeNull()
        ->and($checkOne->position)->toBe(3)
        ->and($checkOne->learning_path_id)->toBe($this->path->id)
        ->and($checkTwo->refresh()->lesson_id)->toBeNull()
        ->and($checkTwo->position)->toBe(4);

    // Pool-Fragen und fremde Zwischen-Checks unverändert
    $poolQuestions->each(fn (Question $question, int $index) => expect($question->refresh()->position)->toBe($index + 1));
    expect($foreignCheck->refresh()->lesson_id)->toBe($otherLesson->id)
        ->and($foreignCheck->position)->toBe(1);

    // Quiz-Zuordnung überlebt den Umzug
    expect($quiz->questions()->pluck('questions.id')->all())->toBe([$checkOne->id]);
});

test('the block editor page is scoped to the path', function () {
    $lesson = Lesson::factory()->for($this->path)->create();
    $foreignLesson = Lesson::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $lesson]))
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $foreignLesson]))
        ->assertNotFound();
});

test('apprentices are forbidden on lesson pages and actions', function () {
    $apprentice = User::factory()->apprentice()->create();
    $lesson = Lesson::factory()->for($this->path)->create();

    $this->actingAs($apprentice)
        ->get(route('admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $lesson]))
        ->assertForbidden();

    $page = fn () => Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $this->path]);

    $page()->set('newLessonTitle', 'Versuch')->call('createLesson')->assertForbidden();
    $page()->call('sortLessons', $lesson->id, 0)->assertForbidden();
    $page()->call('deleteLesson', $lesson->id)->assertForbidden();

    expect(Lesson::find($lesson->id))->not->toBeNull();
});
