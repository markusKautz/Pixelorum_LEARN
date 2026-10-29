<?php

use App\Actions\Content\LoadSourceRegistry;
use App\Enums\LessonBlockType;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Support\ContentSourceFixture;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->path = LearningPath::factory()->create();
    $this->lesson = Lesson::factory()->for($this->path)->create();
    ContentSourceFixture::bind();
});

afterEach(function () {
    ContentSourceFixture::clean();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function blockOfType(?string $state, Lesson $lesson, array $attributes = []): LessonBlock
{
    $factory = LessonBlock::factory()->for($lesson);

    if ($state !== null) {
        $factory = $factory->{$state}();
    }

    return $factory->create($attributes);
}

test('updating the lesson head persists title and page reference', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('title', 'Neuer Lektionstitel')
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.page', 12)
        ->call('saveDetails')
        ->assertHasNoErrors();

    $this->lesson->refresh();

    expect($this->lesson->title)->toBe('Neuer Lektionstitel')
        ->and($this->lesson->source_reference)->toBe([['source_id' => 'scriptum', 'page' => 12]]);
});

test('the slide reference form is persisted without a page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', 'it-osi')
        ->set('sourceReference.references.0.slide', 4)
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->lesson->refresh()->source_reference)->toBe([['source_id' => 'it-osi', 'slide' => 4]]);
});

test('page and slide together are rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.page', 3)
        ->set('sourceReference.references.0.slide', 4)
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references']);
});

test('a page without a document is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', '')
        ->set('sourceReference.references.0.page', 3)
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references']);
});

test('clearing the document clears the whole reference', function () {
    $this->lesson->update(['source_reference' => [['source_id' => 'scriptum', 'page' => 2]]]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', '')
        ->set('sourceReference.references.0.page', null)
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->lesson->refresh()->source_reference)->toBeNull();
});

test('persisting a complete two-reference list works', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('title', 'Zwei Quellen')
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.page', 12)
        ->set('sourceReference.references.1.source_id', 'it-osi')
        ->set('sourceReference.references.1.slide', 4)
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->lesson->refresh()->source_reference)->toBe([
        ['source_id' => 'scriptum', 'page' => 12],
        ['source_id' => 'it-osi', 'slide' => 4],
    ]);
});

test('add and remove reference rows', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    $component->call('addSourceReference');
    expect(count($component->get('sourceReference.references')))->toBe(2);

    $component->call('removeSourceReference', 0);
    expect(count($component->get('sourceReference.references')))->toBe(1);
});

test('malformed scalar row gives sourceReference.references.0 error', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0', 'not-an-array')
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references.0']);
});

test('adding a block creates a shape-conform empty block per type', function () {
    $page = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    foreach (LessonBlockType::cases() as $index => $type) {
        $page->call('addBlock', $type->value);

        $block = $this->lesson->blocks()->where('type', $type)->first();

        expect($block)->not->toBeNull()
            ->and($block->position)->toBe($index + 1);
    }

    expect($this->lesson->blocks()->count())->toBe(count(LessonBlockType::cases()));
});

test('freshly added blocks are marked incomplete until they are saved', function () {
    $page = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    $page->call('addBlock', LessonBlockType::Markdown->value);

    $block = $this->lesson->blocks()->sole();

    // Neue Blöcke starten leer (Abweichung 5) — der Editor zeigt das an
    expect($block->isComplete())->toBeFalse();

    $page->assertSeeHtml('data-test="block-incomplete-badge"');

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['body' => 'Jetzt mit Inhalt.'])
        ->call('save')
        ->assertHasNoErrors();

    expect($block->refresh()->isComplete())->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->assertDontSeeHtml('data-test="block-incomplete-badge"');
});

test('addBlockAt inserts at the given index and keeps positions compact', function () {
    // Drei Blöcke anlegen (Positionen 1..3).
    LessonBlock::factory()->for($this->lesson)->create(['position' => 1]);
    LessonBlock::factory()->for($this->lesson)->callout()->create(['position' => 2]);
    LessonBlock::factory()->for($this->lesson)->glossary()->create(['position' => 3]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        // Zwischen Block 1 und 2 einfügen → Index 1.
        ->call('addBlockAt', 'diagram', 1);

    $ordered = $this->lesson->blocks()->orderBy('position')->get();

    // Neuer Diagramm-Block steht an Position 2; die anderen kompakt verschoben.
    expect($ordered->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($ordered->pluck('type')->map->value->all())
        ->toBe(['markdown', 'diagram', 'callout', 'glossary']);
});

test('addBlockAt with index 0 inserts at the top', function () {
    LessonBlock::factory()->for($this->lesson)->create(['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->call('addBlockAt', 'callout', 0);

    $ordered = $this->lesson->blocks()->orderBy('position')->get();

    expect($ordered->pluck('type')->map->value->all())->toBe(['callout', 'markdown'])
        ->and($ordered->pluck('position')->all())->toBe([1, 2]);
});

test('addBlock still appends at the end', function () {
    LessonBlock::factory()->for($this->lesson)->create(['position' => 1]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->call('addBlock', 'callout');

    $ordered = $this->lesson->blocks()->orderBy('position')->get();

    expect($ordered->pluck('type')->map->value->all())->toBe(['markdown', 'callout']);
});

test('a freshly added section break is immediately complete', function () {
    $page = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    $page->call('addBlock', LessonBlockType::SectionBreak->value);

    $block = $this->lesson->blocks()->sole();

    // Ein Abschnitt trägt keinen Pflichtinhalt — sofort komplett, kein
    // „Unvollständig"-Badge, wird vom Reader nicht als Leerblock übersprungen.
    expect($block->isComplete())->toBeTrue();

    $page->assertDontSeeHtml('data-test="block-incomplete-badge"');
});

test('a section break shows its title in the preview and normalizes an empty title to null', function () {
    $block = blockOfType('sectionBreak', $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->assertSet('content.title', 'Vertiefung')
        ->assertSee('Vertiefung')
        ->assertSeeHtml('data-test="lesson-block-section-break"')
        ->set('content.title', '   ')
        ->call('save')
        ->assertHasNoErrors();

    expect($block->refresh()->content)->toBe(['title' => null]);
});

test('an unknown block type is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->call('addBlock', 'unbekannt')
        ->assertStatus(422);
});

test('saving persists valid content per type', function (?string $state, array $content) {
    $block = blockOfType($state, $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', $content)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('block-saved');

    expect($block->refresh()->content)->toBe($content);
})->with([
    'markdown' => [null, ['body' => 'Ein **fetter** Text.']],
    'accordion' => ['accordion', ['items' => [['heading' => 'Frage', 'body' => 'Antwort']]]],
    'callout' => ['callout', ['variant' => 'exam', 'body' => 'Prüfungsrelevant!']],
    'code_snippet' => ['codeSnippet', ['language' => 'php', 'code' => "echo 'Hallo';", 'filename' => 'hallo.php']],
    'code_snippet csharp' => ['codeSnippet', ['language' => 'csharp', 'code' => 'public abstract class Fahrzeug { }', 'filename' => 'Fahrzeug.cs']],
    'code_snippet python' => ['codeSnippet', ['language' => 'python', 'code' => "def begruessung(name):\n    return f'Hallo {name}'", 'filename' => 'begruessung.py']],
    'diagram' => ['diagram', ['format' => 'mermaid', 'source' => 'graph TD; A-->B;', 'caption' => null]],
    'media' => ['media', ['kind' => 'image', 'url' => 'https://example.test/bild.png', 'caption' => null, 'alt' => 'Ein Bild']],
    'glossary' => ['glossary', ['terms' => [['term' => 'Compiler', 'definition' => 'Übersetzt Quellcode.']]]],
    'section_break' => ['sectionBreak', ['title' => 'Vertiefung']],
]);

test('saving rejects broken payloads per type', function (?string $state, array $content, string $errorKey) {
    $block = blockOfType($state, $this->lesson);
    $original = $block->content;

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', $content)
        ->call('save')
        ->assertHasErrors([$errorKey]);

    expect($block->refresh()->content)->toBe($original);
})->with([
    'markdown ohne body' => [null, ['body' => ''], 'content.body'],
    'accordion ohne items' => ['accordion', ['items' => []], 'content.items'],
    'accordion-item ohne heading' => ['accordion', ['items' => [['heading' => '', 'body' => 'Text']]], 'content.items.0.heading'],
    'callout mit fremder variante' => ['callout', ['variant' => 'rot', 'body' => 'Text'], 'content.variant'],
    'code_snippet mit fremder sprache' => ['codeSnippet', ['language' => 'cobol', 'code' => 'x'], 'content.language'],
    'code_snippet ohne code' => ['codeSnippet', ['language' => 'php', 'code' => ''], 'content.code'],
    'diagram mit fremdem format' => ['diagram', ['format' => 'png', 'source' => 'x'], 'content.format'],
    'diagram-bild mit javascript-url' => ['diagram', ['format' => 'image', 'source' => 'javascript:alert(1)'], 'content'],
    'media ohne url' => ['media', ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => null], 'content.url'],
    'media-video mit javascript-url' => ['media', ['kind' => 'video', 'url' => 'javascript:alert(1)'], 'content'],
    'media-video mit relativer url' => ['media', ['kind' => 'video', 'url' => '/storage/kurs.mp4'], 'content'],
    'glossary ohne definition' => ['glossary', ['terms' => [['term' => 'X', 'definition' => '']]], 'content.terms.0.definition'],
    'section_break mit fremdem feld' => ['sectionBreak', ['title' => 'X', 'schmuggelware' => 'y'], 'content'],
]);

test('unexpected content fields are rejected by the shape rule', function () {
    $block = blockOfType(null, $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['body' => 'Text', 'schmuggelware' => 'x'])
        ->call('save')
        ->assertHasErrors(['content']);
});

test('sorting blocks writes compact one-based positions', function () {
    $first = LessonBlock::factory()->for($this->lesson)->create(['position' => 1]);
    $second = LessonBlock::factory()->for($this->lesson)->create(['position' => 2]);
    $third = LessonBlock::factory()->for($this->lesson)->create(['position' => 3]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->call('sortBlocks', $third->id, 0);

    expect($third->refresh()->position)->toBe(1)
        ->and($first->refresh()->position)->toBe(2)
        ->and($second->refresh()->position)->toBe(3);
});

test('deleting a block removes it', function () {
    $block = LessonBlock::factory()->for($this->lesson)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->call('deleteBlock', $block->id)
        ->assertDispatched('modal-close', name: "delete-block-{$block->id}");

    expect($block->fresh())->toBeNull();
});

test('an uploaded image lands in lesson-media and its url in the media shape', function () {
    Storage::fake('public');

    $block = blockOfType('media', $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => 'Ein Bild'])
        ->set('imageUpload', UploadedFile::fake()->image('foto.png'))
        ->call('save')
        ->assertHasNoErrors()
        // Der Upload-Modus bleibt aktiv — das Bild ist kein externer Link.
        ->assertSet('imageSource', 'upload');

    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk('public');
    $files = $disk->allFiles('lesson-media');

    expect($files)->toHaveCount(1);

    // Site-relativer /storage-Pfad, ohne Schema und Host.
    expect($block->refresh()->content['url'])
        ->toBe(parse_url($disk->url($files[0]), PHP_URL_PATH))
        ->toStartWith('/storage/lesson-media/');
});

test('an uploaded image fills the diagram source in image format', function () {
    Storage::fake('public');

    $block = blockOfType('diagram', $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['format' => 'image', 'source' => '', 'caption' => 'Schaubild'])
        ->set('imageUpload', UploadedFile::fake()->image('diagramm.png'))
        ->call('save')
        ->assertHasNoErrors();

    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk('public');
    $files = $disk->allFiles('lesson-media');

    expect($files)->toHaveCount(1);

    expect($block->refresh()->content['source'])
        ->toBe(parse_url($disk->url($files[0]), PHP_URL_PATH))
        ->toStartWith('/storage/lesson-media/');
});

test('editing a block with an uploaded image opens in upload mode and shows only the filename', function () {
    $block = blockOfType('media', $this->lesson, ['content' => [
        'kind' => 'image',
        'url' => '/storage/lesson-media/abc123.png',
        'caption' => null,
        'alt' => 'Ein Bild',
    ]]);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->assertSet('imageSource', 'upload')
        // Der Dateiname erscheint als Text (die Vorschau unten trägt den Pfad
        // legitim im img-src); das URL-Eingabefeld wird gar nicht gerendert.
        ->assertSee('Aktuelles Bild: abc123.png')
        ->assertDontSee('image-url-input');
});

test('editing a block with an external image url stays in url mode', function () {
    $block = blockOfType('media', $this->lesson, ['content' => [
        'kind' => 'image',
        'url' => 'https://example.test/extern.png',
        'caption' => null,
        'alt' => 'Extern',
    ]]);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->assertSet('imageSource', 'url');
});

test('a non-image upload is rejected', function () {
    Storage::fake('public');

    $block = blockOfType('media', $this->lesson);
    $original = $block->content;

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => null])
        ->set('imageUpload', UploadedFile::fake()->create('unterlagen.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['imageUpload' => 'image']);

    expect(Storage::disk('public')->allFiles('lesson-media'))->toBeEmpty()
        ->and($block->refresh()->content)->toBe($original);
});

test('the upload branch renders the flux dropzone and lists the chosen file', function () {
    Storage::fake('public');

    $block = blockOfType('media', $this->lesson);

    $component = Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => null])
        ->set('imageSource', 'upload')
        ->assertSee('Bild hierher ziehen oder klicken zum Auswählen')
        ->set('imageUpload', UploadedFile::fake()->image('schaubild.png'))
        ->assertSee('schaubild.png');

    $component->call('removeImageUpload')
        ->assertDontSee('schaubild.png');

    expect($component->get('imageUpload'))->toBeNull();
});

test('a chosen non-image renders without blowing up on temporaryUrl', function () {
    // isPreviewable()-Guard: temporaryUrl() wirft für nicht darstellbare
    // Dateien — die .pdf darf erst beim Speichern an der image-Regel scheitern.
    Storage::fake('public');

    $block = blockOfType('media', $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['kind' => 'image', 'url' => '', 'caption' => null, 'alt' => null])
        ->set('imageSource', 'upload')
        ->set('imageUpload', UploadedFile::fake()->create('unterlagen.pdf', 100, 'application/pdf'))
        ->assertOk()
        ->assertSee('unterlagen.pdf');
});

test('a video url is stored without any upload handling', function () {
    Storage::fake('public');

    $block = blockOfType('media', $this->lesson);

    Livewire::actingAs($this->admin)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['kind' => 'video', 'url' => 'https://example.test/kurs.mp4', 'caption' => null, 'alt' => null])
        ->set('imageUpload', UploadedFile::fake()->image('ignoriert.png'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Storage::disk('public')->allFiles('lesson-media'))->toBeEmpty()
        ->and($block->refresh()->content['url'])->toBe('https://example.test/kurs.mp4');
});

test('apprentices are forbidden on every block editor action', function () {
    $apprentice = User::factory()->apprentice()->create();
    $block = LessonBlock::factory()->for($this->lesson)->create();

    $page = fn () => Livewire::actingAs($apprentice)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    $page()->call('saveDetails')->assertForbidden();
    $page()->call('addBlock', 'markdown')->assertForbidden();
    $page()->call('sortBlocks', $block->id, 0)->assertForbidden();
    $page()->call('deleteBlock', $block->id)->assertForbidden();
    $page()->call('addSourceReference')->assertForbidden();
    $page()->call('removeSourceReference', 0)->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('admin.lesson-block-form', ['block' => $block])
        ->set('content', ['body' => 'Manipuliert'])
        ->call('save')
        ->assertForbidden();

    expect($block->fresh())->not->toBeNull();
});

test('stable rowKeys survive editing source_id', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    $initialKeys = $component->get('sourceReference.rowKeys');
    $initialKey = $initialKeys[0];

    $component->set('sourceReference.references.0.source_id', 'scriptum');

    $updatedKeys = $component->get('sourceReference.rowKeys');

    expect($updatedKeys[0])->toBe($initialKey);
});

test('blank rows validate without calling the source registry loader', function () {
    /** @var MockInterface $spy */
    $spy = Mockery::mock(LoadSourceRegistry::class);
    $spy->shouldReceive('handle')->never();
    app()->instance(LoadSourceRegistry::class, $spy);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('title', 'Nur Titel')
        ->set('sourceReference.references.0.source_id', '')
        ->call('saveDetails')
        ->assertHasNoErrors();
});

test('unknown source id is rejected by the source references rule', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('title', 'Titel')
        ->set('sourceReference.references.0.source_id', 'unknown-source')
        ->set('sourceReference.references.0.page', 1)
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references']);
});

test('unexpected ui row key is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', 'scriptum')
        ->set('sourceReference.references.0.unexpected', 'x')
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references']);
});

test('fillFromReferences provides all default keys including empty section', function () {
    $lesson = Lesson::factory()->for($this->path)->create([
        'source_reference' => [['source_id' => 'scriptum', 'page' => 12]],
    ]);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $lesson]);

    $refs = $component->get('sourceReference.references');

    expect($refs[0])->toHaveKeys(['source_id', 'page', 'slide', 'section'])
        ->and($refs[0]['section'])->toBe('')
        ->and($refs[0]['source_id'])->toBe('scriptum')
        ->and($refs[0]['page'])->toBe(12);

    $component->assertSeeHtml('wire:key="source-reference-');
});

test('initial row key is generated with laravel portable uuid', function () {
    $fakeUuid = 'a1b2c3d4-e5f6-7a89-b0c1-d2e3f4a5b6c7';
    Str::createUuidsUsing(fn () => $fakeUuid);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson]);

    $key = $component->get('sourceReference.rowKeys.0');

    Str::createUuidsNormally();

    expect($key)->toBe($fakeUuid);
});

test('non string source_id is preserved for nested string validation', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', 123)
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references.0.source_id']);
});

test('non string section is preserved for nested string validation', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.lessons.edit', ['learningPath' => $this->path, 'lesson' => $this->lesson])
        ->set('sourceReference.references.0.source_id', 'official-source')
        ->set('sourceReference.references.0.section', 123)
        ->call('saveDetails')
        ->assertHasErrors(['sourceReference.references.0.section']);
});
