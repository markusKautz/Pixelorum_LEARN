<?php

use App\Enums\LessonBlockType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->learner = User::factory()->apprentice()->create();
    $this->path = LearningPath::factory()->published()->verified()->create();
    $this->lesson = Lesson::factory()->for($this->path)->create(['title' => 'Erste Lektion', 'position' => 1]);
});

/**
 * Markdown-Block mit erkennbarem Text an fester Position.
 */
function markdownBlock(Lesson $lesson, string $body, int $position): LessonBlock
{
    return LessonBlock::factory()->for($lesson)->create([
        'type' => LessonBlockType::Markdown,
        'content' => ['body' => $body],
        'position' => $position,
    ]);
}

/**
 * Abschnitts-Trenner mit optionalem Titel an fester Position.
 */
function sectionBreak(Lesson $lesson, ?string $title, int $position): LessonBlock
{
    return LessonBlock::factory()->for($lesson)->create([
        'type' => LessonBlockType::SectionBreak,
        'content' => ['title' => $title],
        'position' => $position,
    ]);
}

function reader(User $learner, LearningPath $path, Lesson $lesson)
{
    return Livewire::actingAs($learner)->test('pages::learn.lessons.show', [
        'learningPath' => $path,
        'lesson' => $lesson,
    ]);
}

test('a lesson without section breaks is one step', function () {
    markdownBlock($this->lesson, 'Erster Absatz', 1);
    markdownBlock($this->lesson, 'Zweiter Absatz', 2);

    reader($this->learner, $this->path, $this->lesson)
        ->assertSee('Erster Absatz')
        ->assertSee('Zweiter Absatz')
        ->assertDontSee('Schritt 1 von');
});

test('section breaks group the blocks into steps', function () {
    markdownBlock($this->lesson, 'Einleitung', 1);
    sectionBreak($this->lesson, 'Vertiefung', 2);
    markdownBlock($this->lesson, 'Der zweite Abschnitt', 3);

    $component = reader($this->learner, $this->path, $this->lesson)
        ->assertSee('Schritt 1 von 2')
        ->assertSee('Einleitung')
        ->assertDontSee('Der zweite Abschnitt');

    $component->call('nextStep')
        ->assertSet('step', 2)
        ->assertSee('Der zweite Abschnitt')
        ->assertSee('Vertiefung')
        ->assertDontSee('Einleitung');
});

test('the section break itself is not rendered but its title is', function () {
    markdownBlock($this->lesson, 'Einleitung', 1);
    sectionBreak($this->lesson, 'Vertiefung', 2);
    markdownBlock($this->lesson, 'Weiter geht es', 3);

    reader($this->learner, $this->path, $this->lesson)
        ->call('nextStep')
        ->assertSee('Vertiefung')
        ->assertDontSeeHtml('data-test="lesson-block-section-break"');
});

test('a leading section break does not create an empty first step', function () {
    sectionBreak($this->lesson, 'Los geht es', 1);
    markdownBlock($this->lesson, 'Der einzige Absatz', 2);

    reader($this->learner, $this->path, $this->lesson)
        ->assertDontSee('Schritt 1 von 2')
        ->assertSee('Los geht es')
        ->assertSee('Der einzige Absatz');
});

test('two consecutive section breaks do not create an empty step', function () {
    markdownBlock($this->lesson, 'Erster Abschnitt', 1);
    sectionBreak($this->lesson, 'Übersprungen', 2);
    sectionBreak($this->lesson, 'Zweiter Titel', 3);
    markdownBlock($this->lesson, 'Zweiter Abschnitt', 4);

    reader($this->learner, $this->path, $this->lesson)
        ->assertSee('Schritt 1 von 2')
        ->call('nextStep')
        ->assertSee('Zweiter Titel')
        ->assertSee('Zweiter Abschnitt');
});

test('a trailing section break does not create an empty last step', function () {
    markdownBlock($this->lesson, 'Der einzige Absatz', 1);
    sectionBreak($this->lesson, 'Kommt nicht mehr', 2);

    reader($this->learner, $this->path, $this->lesson)
        ->assertDontSee('Schritt 1 von 2')
        ->assertDontSee('Kommt nicht mehr')
        ->assertSee('Der einzige Absatz');
});

test('a lesson without any complete block shows a hint', function () {
    reader($this->learner, $this->path, $this->lesson)
        ->assertSee('Diese Lektion hat noch keinen Inhalt.')
        ->assertSee('Lektion abschließen');
});

test('incomplete blocks are skipped', function (LessonBlockType $type, array $emptyContent) {
    markdownBlock($this->lesson, 'Vollständiger Block', 1);

    $incomplete = LessonBlock::factory()->for($this->lesson)->create([
        'type' => $type,
        'content' => $emptyContent,
        'position' => 2,
    ]);

    reader($this->learner, $this->path, $this->lesson)
        ->assertSee('Vollständiger Block')
        ->assertDontSeeHtml('wire:key="block-'.$incomplete->id.'"');
})->with([
    'leerer Fließtext' => [LessonBlockType::Markdown, ['body' => '']],
    'leerer Aufklappbereich' => [LessonBlockType::Accordion, ['items' => [['heading' => '', 'body' => '']]]],
    'leere Hinweisbox' => [LessonBlockType::Callout, ['variant' => 'info', 'body' => '']],
    'leeres Code-Beispiel' => [LessonBlockType::CodeSnippet, ['language' => 'php', 'code' => '', 'filename' => null]],
    'leeres Diagramm' => [LessonBlockType::Diagram, ['format' => 'mermaid', 'source' => '', 'caption' => null]],
    'leere Medien' => [LessonBlockType::Media, ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => null]],
    'leeres Glossar' => [LessonBlockType::Glossary, ['terms' => [['term' => '', 'definition' => '']]]],
]);

test('a step out of range is clamped instead of rejected', function (int $requested, int $expected) {
    markdownBlock($this->lesson, 'Eins', 1);
    sectionBreak($this->lesson, null, 2);
    markdownBlock($this->lesson, 'Zwei', 3);

    Livewire::actingAs($this->learner)
        ->withQueryParams(['step' => $requested])
        ->test('pages::learn.lessons.show', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->assertSet('step', $expected);
})->with([
    'weit hinter dem Ende' => [99, 2],
    'null' => [0, 1],
    'negativ' => [-5, 1],
]);

test('a lesson of another path results in a 404', function () {
    $otherPath = LearningPath::factory()->published()->verified()->create();
    $otherLesson = Lesson::factory()->for($otherPath)->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.lessons.show', $this->learner, [
            'learningPath' => $this->path,
            'lesson' => $otherLesson,
        ]))
        ->assertNotFound();
});

test('an apprentice is forbidden on a lesson of an unreleased path', function () {
    $draft = LearningPath::factory()->create();
    $lesson = Lesson::factory()->for($draft)->create();

    $this->actingAs($this->learner)
        ->get(learnRoute('learn.lessons.show', $this->learner, [
            'learningPath' => $draft,
            'lesson' => $lesson,
        ]))
        ->assertForbidden();
});

test('completing a lesson writes exactly one row, even when clicked twice', function () {
    markdownBlock($this->lesson, 'Inhalt', 1);

    reader($this->learner, $this->path, $this->lesson)
        ->call('completeLesson')
        ->call('completeLesson');

    expect(LessonCompletion::where('user_id', $this->learner->id)
        ->where('lesson_id', $this->lesson->id)
        ->count())->toBe(1);
});

test('completing a lesson leads to the next lesson', function () {
    $second = Lesson::factory()->for($this->path)->create(['title' => 'Zweite Lektion', 'position' => 2]);

    reader($this->learner, $this->path, $this->lesson)
        ->call('completeLesson')
        ->assertRedirect(route('learn.lessons.show', [$this->path, $second]));
});

test('completing the last lesson leads to the final quiz', function () {
    $quiz = Quiz::factory()->pathFinal($this->path)->published()->create();

    reader($this->learner, $this->path, $this->lesson)
        ->call('completeLesson')
        ->assertRedirect(route('learn.quizzes.show', $quiz));
});

test('completing the last lesson without a visible quiz leads back to the path', function () {
    Quiz::factory()->pathFinal($this->path)->create();

    reader($this->learner, $this->path, $this->lesson)
        ->call('completeLesson')
        ->assertRedirect(route('learn.paths.show', $this->path));
});

test('the reader links to the neighbouring lessons', function () {
    $second = Lesson::factory()->for($this->path)->create(['title' => 'Zweite Lektion', 'position' => 2]);
    Lesson::factory()->for($this->path)->create(['title' => 'Dritte Lektion', 'position' => 3]);

    reader($this->learner, $this->path, $second)
        ->assertSee('Erste Lektion')
        ->assertSee('Dritte Lektion')
        ->assertSee('flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between', escape: false);
});
