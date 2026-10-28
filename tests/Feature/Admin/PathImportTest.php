<?php

use App\Actions\Content\ImportLearningPath;
use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Enums\ReviewStatus;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ContentFileFixture;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();

    // Die Fixture ist Schema-Version 1 ohne Quellenblock: Ihre Quellen müssen
    // der Installation schon bekannt sein (DEV-3, E9).
    ContentSource::factory()->create(['source_id' => 'scriptum']);
    ContentSource::factory()->slides()->create(['source_id' => 'it-osi']);
});

/**
 * Eine hochladbare Content-JSON aus der Validator-Fixture.
 *
 * @param  array<string, mixed>  $overrides
 */
function inhaltsDatei(array $overrides = [], string $name = 'pfad.json'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        json_encode([...ContentFileFixture::valid(), ...$overrides], JSON_THROW_ON_ERROR),
    );
}

/**
 * Die Datei prüfen und importieren, wie es der Admin im Modal tut.
 */
function ladeHoch(UploadedFile $file): Testable
{
    return Livewire::actingAs(test()->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', $file)
        ->call('previewFile')
        ->call('import');
}

test('a valid file creates the path with lessons, blocks, questions, options and quiz', function () {
    ladeHoch(inhaltsDatei())
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.learning-paths.edit', ['learningPath' => 'test-content-path']));

    $path = LearningPath::firstWhere('slug', 'test-content-path');

    expect($path)->not->toBeNull()
        ->and($path->title)->toBe('Test Content Path')
        ->and($path->objectives)->toBe(['Ein prüfbares Lernziel erreichen'])
        ->and($path->lessons)->toHaveCount(1)
        ->and(LessonBlock::where('lesson_id', $path->lessons->first()->id)->count())->toBe(1)
        // Eine Pool-Frage weniger als insgesamt: der Zwischen-Check der Lektion.
        ->and(Question::where('learning_path_id', $path->id)->count())->toBe(3)
        ->and(Question::where('learning_path_id', $path->id)->whereNull('lesson_id')->count())->toBe(2)
        ->and(QuestionOption::whereIn('question_id', Question::where('learning_path_id', $path->id)->select('id'))->count())->toBe(6)
        ->and($path->finalQuiz)->not->toBeNull()
        ->and($path->finalQuiz->title)->toBe('Abschluss-Quiz')
        ->and($path->finalQuiz->kind)->toBe(QuizKind::PathFinal)
        ->and($path->finalQuiz->questions->pluck('position')->all())->toBe([1, 2]);
});

test('an imported path is always a draft and unverified', function () {
    ladeHoch(inhaltsDatei())->assertHasNoErrors();

    $path = LearningPath::firstWhere('slug', 'test-content-path');

    expect($path->status)->toBe(PathStatus::Draft)
        ->and($path->review_status)->toBe(ReviewStatus::Unreviewed)
        ->and($path->verified_at)->toBeNull()
        ->and($path->finalQuiz->status)->toBe(PathStatus::Draft)
        ->and($path->isVisibleToLearners())->toBeFalse();
});

test('broken files are reported and create nothing', function (UploadedFile $file, string $fragment) {
    ladeHoch($file)
        ->assertHasErrors('file')
        ->assertSee($fragment);

    expect(LearningPath::withTrashed()->count())->toBe(0)
        ->and(Lesson::count())->toBe(0)
        ->and(Question::count())->toBe(0);
})->with([
    'kein JSON' => [
        fn () => UploadedFile::fake()->createWithContent('pfad.json', 'kein json'),
        'Ungültiges JSON',
    ],
    'unbekannter Blocktyp' => [
        fn () => inhaltsDatei(['lessons' => [[
            ...ContentFileFixture::valid()['lessons'][0],
            'blocks' => [['position' => 1, 'type' => 'kein-blocktyp', 'content' => ['body' => 'Text']]],
        ]]]),
        'lessons.0.blocks.0.type',
    ],
    'Pflichtfeld fehlt' => [
        fn () => UploadedFile::fake()->createWithContent(
            'pfad.json',
            json_encode(collect(ContentFileFixture::valid())->except('quiz')->all(), JSON_THROW_ON_ERROR),
        ),
        'quiz',
    ],
    'Quiz zeigt auf keine Pool-Frage' => [
        fn () => inhaltsDatei(['quiz' => [
            ...ContentFileFixture::valid()['quiz'],
            'question_positions' => [1, 99],
        ]]),
        'quiz.question_positions.1',
    ],
]);

test('the error message names the uploaded file, not a temporary path', function () {
    ladeHoch(inhaltsDatei(['lessons' => [[
        ...ContentFileFixture::valid()['lessons'][0],
        'blocks' => [['position' => 1, 'type' => 'kein-blocktyp', 'content' => ['body' => 'Text']]],
    ]]], 'osi-3.json'))
        ->assertHasErrors('file')
        ->assertSee('osi-3.json: lessons.0.blocks.0.type');
});

test('an existing slug makes the import a copy and leaves the existing path untouched', function () {
    $existing = LearningPath::factory()->create([
        'slug' => 'test-content-path',
        'title' => 'Schon da',
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', inhaltsDatei())
        ->call('previewFile')
        ->assertSet('preview.copy', true)
        ->assertSee('Der Import legt eine Kopie an')
        ->call('import')
        ->assertHasNoErrors();

    $copy = LearningPath::whereKeyNot($existing->id)->sole();

    expect($copy->slug)->toMatch('/^test-content-path-[a-z0-9]{4}$/')
        ->and($copy->title)->toEndWith(' (Kopie)')
        ->and($copy->lessons()->count())->toBe(1)
        ->and($existing->fresh()->title)->toBe('Schon da')
        ->and($existing->lessons()->count())->toBe(0);
});

test('a soft-deleted slug makes the import a copy and leaves the trashed path in the trash', function () {
    $trashed = LearningPath::factory()->create(['slug' => 'test-content-path']);
    $trashed->delete();

    ladeHoch(inhaltsDatei())->assertHasNoErrors();

    expect($trashed->fresh()->trashed())->toBeTrue()
        ->and(LearningPath::sole()->slug)->toMatch('/^test-content-path-[a-z0-9]{4}$/');
});

test('a free slug shows no copy hint', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', inhaltsDatei())
        ->call('previewFile')
        ->assertSet('preview.copy', false)
        ->assertDontSee('Der Import legt eine Kopie an');
});

test('a version 1 file with a source unknown to the installation is rejected', function () {
    // E2 gilt weiter: Die Erdung gegen das Quellenarchiv läuft lokal. Seit
    // DEV-3 (E9) muss eine Datei ohne Quellenblock aber Quellen nennen, die
    // die Installation kennt, sonst wäre der Beleg in der Freigabe nicht
    // prüfbar.
    $lesson = ContentFileFixture::valid()['lessons'][0];
    $lesson['source_reference'] = [['source_id' => 'nirgends-registriert', 'page' => 4]];

    ladeHoch(inhaltsDatei(['lessons' => [$lesson]]))
        ->assertHasErrors('file')
        ->assertSee('lapp:content:upgrade');

    expect(LearningPath::count())->toBe(0);
});

test('a file whose source reference has no locator is rejected', function () {
    $lesson = ContentFileFixture::valid()['lessons'][0];
    $lesson['source_reference'] = [['source_id' => 'scriptum']];

    ladeHoch(inhaltsDatei(['lessons' => [$lesson]]))->assertHasErrors('file');

    expect(LearningPath::count())->toBe(0);
});

test('a taken position makes the import land at the end', function () {
    LearningPath::factory()->create(['position' => 1]);
    LearningPath::factory()->create(['position' => 2]);

    ladeHoch(inhaltsDatei())->assertHasNoErrors();

    expect(LearningPath::firstWhere('slug', 'test-content-path')->position)->toBe(3);
});

test('a free position from the file is kept', function () {
    LearningPath::factory()->create(['position' => 2]);

    ladeHoch(inhaltsDatei())->assertHasNoErrors();

    expect(LearningPath::firstWhere('slug', 'test-content-path')->position)->toBe(1);
});

test('the preview alone creates nothing', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', inhaltsDatei())
        ->call('previewFile')
        ->assertHasNoErrors()
        ->assertSet('preview.slug', 'test-content-path')
        ->assertSet('preview.lessons', 1)
        ->assertSet('preview.checks', 1)
        ->assertSet('preview.questions', 2)
        ->assertSet('preview.position', 1);

    expect(LearningPath::count())->toBe(0);
});

test('importing without a checked file does nothing', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->call('import')
        ->assertHasErrors('file');

    expect(LearningPath::count())->toBe(0);
});

/*
 * Die Create-only-Grenze ist eine Eigenschaft der Action, nicht der
 * Oberfläche. Ohne diese Tests hinge sie am Component, und der nächste
 * Aufrufer — Konsole, Job, Test — käme daran vorbei.
 */

test('the action creates a copy for an existing slug instead of synchronising it', function () {
    $existing = LearningPath::factory()->create([
        'slug' => 'test-content-path',
        'title' => 'Schon da',
    ]);

    $copy = app(ImportLearningPath::class)->create(ContentFileFixture::valid());

    expect($copy->id)->not->toBe($existing->id)
        ->and($copy->slug)->toMatch('/^test-content-path-[a-z0-9]{4}$/')
        ->and($copy->title)->toBe(ContentFileFixture::valid()['title'].' (Kopie)')
        ->and($existing->fresh()->title)->toBe('Schon da')
        ->and($existing->lessons()->count())->toBe(0);
});

test('the action creates a copy for a soft-deleted slug instead of reviving it', function () {
    $trashed = LearningPath::factory()->create(['slug' => 'test-content-path']);
    $trashed->delete();

    $copy = app(ImportLearningPath::class)->create(ContentFileFixture::valid());

    expect($trashed->fresh()->trashed())->toBeTrue()
        ->and($copy->slug)->not->toBe('test-content-path');
});

test('a copy title stays within the column length', function () {
    LearningPath::factory()->create(['slug' => 'test-content-path']);

    $copy = app(ImportLearningPath::class)->create([
        ...ContentFileFixture::valid(),
        'title' => str_repeat('a', 255),
    ]);

    expect(mb_strlen($copy->title))->toBe(255)
        ->and($copy->title)->toEndWith(' (Kopie)');
});

test('a failure midway through leaves no half path behind', function () {
    $content = ContentFileFixture::valid();
    $content['quiz']['question_positions'] = [1, 99];

    expect(fn () => app(ImportLearningPath::class)->create($content))
        ->toThrow(RuntimeException::class);

    expect(LearningPath::withTrashed()->count())->toBe(0)
        ->and(Lesson::count())->toBe(0)
        ->and(Question::count())->toBe(0)
        ->and(Quiz::count())->toBe(0);
});

test('the action keeps inline checks and pool questions apart despite equal positions', function () {
    $path = app(ImportLearningPath::class)->create(ContentFileFixture::valid());
    $lesson = $path->lessons()->firstOrFail();

    expect(Question::where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and(Question::where('learning_path_id', $path->id)->whereNull('lesson_id')->count())->toBe(2)
        ->and(Question::count())->toBe(3);
});

test('the action orders the final quiz as listed in the file', function () {
    $content = ContentFileFixture::valid();
    $content['quiz']['question_positions'] = [2, 1];

    $path = app(ImportLearningPath::class)->create($content);
    $quiz = $path->quizzes()->where('kind', QuizKind::PathFinal)->firstOrFail();

    expect($quiz->questions->pluck('prompt')->all())->toBe([
        'Welche Antworten sind korrekt?',
        'Welche Antwort ist korrekt?',
    ]);
});

test('apprentices reach neither the modal nor the import', function () {
    $apprentice = User::factory()->apprentice()->create();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', inhaltsDatei())
        ->call('previewFile')
        ->assertForbidden();

    expect(LearningPath::count())->toBe(0);
});
