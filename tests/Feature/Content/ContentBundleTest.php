<?php

use App\Actions\Content\BundleContentExport;
use App\Actions\Content\ContentBundle;
use App\Actions\Content\ExportLearningPath;
use App\Actions\Content\ImportLearningPath;
use App\Actions\Content\ReadContentBundle;
use App\Actions\Content\StoreBundledImages;
use App\Actions\Content\ValidateContentFile;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\LessonBlock;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ContentFileFixture;
use Tests\Support\ContentSourceFixture;

/*
 * DEV-14: Hochgeladene Bilder reisen als ZIP-Bündel mit Export und Upload.
 */

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');

    ContentSource::factory()->create(['source_id' => 'scriptum', 'title' => 'Scriptum']);
    ContentSource::factory()->slides()->create(['source_id' => 'it-osi', 'title' => 'IT-OSI-Folien']);

    $this->admin = User::factory()->admin()->create();
    File::ensureDirectoryExists(bundleDirectory());
});

afterEach(function () {
    File::deleteDirectory(bundleDirectory());
    ContentFileFixture::clean();
    ContentSourceFixture::clean();
});

function bundleDirectory(): string
{
    return storage_path('framework/testing/content-bundle-'.getmypid());
}

/**
 * Die Fixture als Schema-Version 3 mit den Quellen, wie sie in der Datenbank stehen.
 *
 * @return array<string, mixed>
 */
function contentVersionThree(): array
{
    return [
        'schema_version' => 3,
        ...ContentFileFixture::valid(),
        'sources' => ContentSource::orderBy('source_id')->get()->map->toTransportable()->all(),
    ];
}

/**
 * Ein gültiges PNG; das Etikett hinter IEND ändert den Hash, nicht den Typ.
 */
function bundlePng(string $tag): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==').$tag;
}

/**
 * Der Fixture-Pfad mit einem Media- und einem Diagramm-Bild aus dem Upload.
 */
function pathWithUploadedImages(): LearningPath
{
    $path = app(ImportLearningPath::class)->create(ContentFileFixture::valid());
    $lesson = $path->lessons()->sole();

    Storage::disk('public')->put('lesson-media/foto.png', bundlePng('foto'));
    Storage::disk('public')->put('lesson-media/skizze.png', bundlePng('skizze'));

    LessonBlock::factory()->for($lesson)->create([
        'position' => 2,
        'type' => 'media',
        'content' => ['kind' => 'image', 'url' => '/storage/lesson-media/foto.png', 'alt' => 'Foto', 'caption' => null],
    ]);
    LessonBlock::factory()->for($lesson)->create([
        'position' => 3,
        'type' => 'diagram',
        'content' => ['format' => 'image', 'source' => '/storage/lesson-media/skizze.png', 'caption' => 'Skizze'],
    ]);

    return $path->fresh();
}

/**
 * Write a ZIP from entry name => bytes and remember it for cleanup.
 *
 * @param  array<string, string>  $entries
 */
function writeBundle(array $entries): string
{
    $target = (string) tempnam(bundleDirectory(), 'bundle-');
    $zip = new ZipArchive;
    $zip->open($target, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }

    $zip->close();

    return $target;
}

/**
 * Export the path as bundle and return its file.
 */
function exportBundle(LearningPath $path): string
{
    $result = app(ExportLearningPath::class)->handle($path);
    $bundle = bundleDirectory().'/export.zip';
    File::move(app(BundleContentExport::class)->handle($result['json'], $result['media']), $bundle);

    return $bundle;
}

/**
 * A bundle with one image and a manifest that may be bent per test.
 *
 * @param  array<string, mixed>  $entryOverrides
 * @param  array<string, string>  $extraEntries
 */
function singleImageBundle(string $bytes, array $entryOverrides = [], array $extraEntries = []): string
{
    $sha256 = hash('sha256', $bytes);
    $path = ContentBundle::bundlePath(ContentBundle::fileName($sha256, 'image/png'));
    $content = contentVersionThree();
    $content['media'] = [['path' => $path, 'sha256' => $sha256, 'mime' => 'image/png', 'bytes' => strlen($bytes), ...$entryOverrides]];
    $content['lessons'][0]['blocks'][] = [
        'position' => 2,
        'type' => 'media',
        'content' => ['kind' => 'image', 'url' => $path, 'alt' => 'Bild', 'caption' => null],
    ];

    return writeBundle([
        ContentBundle::CONTENT_FILE => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        $path => $bytes,
        ...$extraEntries,
    ]);
}

test('a path with uploaded images exports as bundle with media block and bundle paths', function () {
    $result = app(ExportLearningPath::class)->handle(pathWithUploadedImages());
    $content = json_decode($result['json'], true);
    $fotoHash = hash('sha256', bundlePng('foto'));

    expect($result['warnings'])->toBe([])
        ->and($result['filename'])->toBe('test-content-path.zip')
        ->and($content['schema_version'])->toBe(3)
        ->and(array_keys($content))->toContain('media')
        ->and(array_search('media', array_keys($content), true))->toBe(array_search('sources', array_keys($content), true) + 1)
        ->and($content['media'])->toHaveCount(2)
        ->and($content['lessons'][0]['blocks'][1]['content']['url'])->toBe("media/{$fotoHash}.png")
        ->and($result['media'])->toBe([
            "media/{$fotoHash}.png" => 'lesson-media/foto.png',
            'media/'.hash('sha256', bundlePng('skizze')).'.png' => 'lesson-media/skizze.png',
        ]);
});

test('a path without uploaded images still exports as plain json', function () {
    $path = app(ImportLearningPath::class)->create(ContentFileFixture::valid());

    $result = app(ExportLearningPath::class)->handle($path);

    expect($result['filename'])->toBe('test-content-path.json')
        ->and($result['media'])->toBe([])
        ->and(json_decode($result['json'], true))->not->toHaveKey('media');
});

test('an exported bundle is uploaded again with its images', function () {
    $path = pathWithUploadedImages();
    $bundle = exportBundle($path);
    $path->forceDelete();
    Storage::disk('public')->delete(['lesson-media/foto.png', 'lesson-media/skizze.png']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', UploadedFile::fake()->createWithContent('pfad.zip', File::get($bundle)))
        ->call('previewFile')
        ->assertHasNoErrors()
        ->assertSet('preview.images', 2)
        ->call('import')
        ->assertHasNoErrors();

    $blocks = LearningPath::firstWhere('slug', 'test-content-path')->lessons()->sole()->blocks()->orderBy('position')->get();
    $fotoFile = 'lesson-media/'.hash('sha256', bundlePng('foto')).'.png';
    $skizzeFile = 'lesson-media/'.hash('sha256', bundlePng('skizze')).'.png';

    expect($blocks[1]->content['url'])->toBe("/storage/{$fotoFile}")
        ->and($blocks[2]->content['source'])->toBe("/storage/{$skizzeFile}")
        ->and(Storage::disk('public')->get($fotoFile))->toBe(bundlePng('foto'))
        ->and(Storage::disk('public')->get($skizzeFile))->toBe(bundlePng('skizze'));
});

test('the admin download delivers a zip for a path with uploaded images', function () {
    $path = pathWithUploadedImages();

    $response = $this->actingAs($this->admin)->get(route('admin.learning-paths.export', $path));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertDownload('test-content-path.zip');
});

test('the export command writes a zip for a path with uploaded images', function () {
    pathWithUploadedImages();
    $directory = storage_path('framework/testing/content-file-fixture/bundle-export');

    $this->artisan('lapp:content:export', ['slug' => 'test-content-path', '--dir' => $directory])->assertSuccessful();

    $zip = new ZipArchive;

    expect($zip->open("{$directory}/test-content-path.zip"))->toBeTrue()
        ->and($zip->numFiles)->toBe(3)
        ->and($zip->locateName(ContentBundle::CONTENT_FILE))->not->toBeFalse();

    $zip->close();
});

test('a missing image file is reported and its block keeps the old url', function () {
    $path = pathWithUploadedImages();
    Storage::disk('public')->delete('lesson-media/foto.png');

    $result = app(ExportLearningPath::class)->handle($path);

    expect(implode("\n", $result['warnings']))->toContain('Die hochgeladene Bilddatei fehlt')
        ->and(json_decode($result['json'], true)['lessons'][0]['blocks'][1]['content']['url'])->toBe('/storage/lesson-media/foto.png')
        ->and($result['media'])->toHaveCount(1);
});

test('a malicious or broken bundle is rejected', function (Closure $makeBundle, string $message) {
    expect(fn () => app(ReadContentBundle::class)->handle($makeBundle(), 'pfad.zip'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'Zip-Slip' => [fn () => singleImageBundle(bundlePng('a'), extraEntries: ['../boese.png' => 'x']), 'Unerwarteter Eintrag'],
    'Eintrag ohne Manifest' => [fn () => singleImageBundle(bundlePng('a'), extraEntries: ['media/'.str_repeat('b', 64).'.png' => bundlePng('b')]), 'steht nicht im media-Block'],
    'Manifest nennt eine andere Datei' => [fn () => singleImageBundle(bundlePng('a'), ['sha256' => str_repeat('c', 64), 'path' => 'media/'.str_repeat('c', 64).'.png']), 'steht nicht im media-Block'],
    'SVG als PNG ausgegeben' => [fn () => singleImageBundle('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'kein Bild vom Typ image/png'],
    'zu großes Bild' => [fn () => singleImageBundle(bundlePng(str_repeat('x', ContentBundle::MAX_IMAGE_BYTES))), 'zu groß'],
    'zu viele Einträge' => [fn () => writeBundle(collect(range(1, ContentBundle::MAX_IMAGES + 2))->mapWithKeys(fn (int $i) => ["media/{$i}.png" => 'x'])->all() + [ContentBundle::CONTENT_FILE => '{}']), 'zu viele Einträge'],
    'kein ZIP' => [fn () => tap((string) tempnam(bundleDirectory(), 'bundle-'), fn (string $file) => File::put($file, 'kein zip')), 'kein lesbares ZIP-Archiv'],
    'ohne content.json' => [fn () => writeBundle(['media/' => '']), 'fehlt content.json'],
]);

test('a hash mismatch between manifest and file is rejected', function () {
    $bytes = bundlePng('a');
    $sha256 = hash('sha256', $bytes);
    $path = ContentBundle::bundlePath(ContentBundle::fileName($sha256, 'image/png'));

    // Unter dem richtigen Namen liegt ein anderes Bild gleicher Länge.
    $bundle = singleImageBundle($bytes, extraEntries: [$path => bundlePng('b')]);

    expect(fn () => app(ReadContentBundle::class)->handle($bundle, 'pfad.zip'))
        ->toThrow(RuntimeException::class, 'Prüfsumme weicht vom media-Block ab');
});

test('a valid bundle points the image at its future place on the disk', function () {
    $bytes = bundlePng('a');
    $fileName = ContentBundle::fileName(hash('sha256', $bytes), 'image/png');

    $result = app(ReadContentBundle::class)->handle(singleImageBundle($bytes), 'pfad.zip');
    $content = app(ValidateContentFile::class)->handleContents($result['json'], 'pfad.zip', null);

    expect($result['images'])->toBe(["lesson-media/{$fileName}" => $bytes])
        ->and($content['lessons'][0]['blocks'][1]['content']['url'])->toBe("/storage/lesson-media/{$fileName}")
        ->and($content['lessons'][0]['blocks'][0]['content'])->toBeArray();
});

test('a media path that does not match hash and type is rejected by the validator', function () {
    $content = contentVersionThree();
    $content['media'] = [['path' => 'media/'.str_repeat('a', 64).'.png', 'sha256' => str_repeat('b', 64), 'mime' => 'image/png', 'bytes' => 10]];

    expect(fn () => app(ValidateContentFile::class)->handleContents(json_encode($content), 'pfad.json', null))
        ->toThrow(RuntimeException::class, 'media.0.path');
});

test('storing the same image twice writes it once and discards only new files', function () {
    $store = app(StoreBundledImages::class);
    Storage::disk('public')->put('lesson-media/alt.png', 'alt');

    expect($store->handle(['lesson-media/neu.png' => 'neu']))->toBe(['lesson-media/neu.png'])
        ->and($store->handle(['lesson-media/neu.png' => 'neu', 'lesson-media/alt.png' => 'alt']))->toBe([]);

    $store->discard(['lesson-media/neu.png']);

    expect(Storage::disk('public')->exists('lesson-media/neu.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('lesson-media/alt.png'))->toBeTrue();
});

test('a failing import removes the images it wrote', function () {
    $this->mock(ImportLearningPath::class, function ($mock): void {
        $mock->shouldReceive('resolvePosition')->andReturn(1);
        $mock->shouldReceive('isSlugTaken')->andReturn(false);
        $mock->shouldReceive('create')->andThrow(new RuntimeException('Import gescheitert'));
    });
    $bytes = bundlePng('a');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', UploadedFile::fake()->createWithContent('pfad.zip', File::get(singleImageBundle($bytes))))
        ->call('previewFile')
        ->call('import')
        ->assertHasErrors(['file']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('a json file larger than 2 MB is rejected even below the archive limit', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.import-path-modal')
        ->set('file', UploadedFile::fake()->createWithContent('pfad.json', str_repeat(' ', ContentBundle::MAX_CONTENT_BYTES + 1).'{}'))
        ->call('previewFile')
        ->assertHasErrors(['file']);
});
