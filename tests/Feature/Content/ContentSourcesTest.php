<?php

use App\Actions\Content\ImportLearningPath;
use App\Actions\Content\LoadSourceRegistry;
use App\Actions\Content\SyncContentSources;
use App\Enums\SourceKind;
use App\Models\ContentSource;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\ContentFileFixture;
use Tests\Support\ContentSourceFixture;

afterEach(function () {
    ContentSourceFixture::clean();
});

/**
 * @return array<string, mixed>
 */
function transportableSource(array $overrides = []): array
{
    return [
        'source_id' => 'scriptum',
        'kind' => 'pdf',
        'title' => 'Scriptum',
        'publisher' => 'Markus Kautz',
        'url' => null,
        'version' => null,
        'published_at' => null,
        'accessed_at' => null,
        'content_sha256' => null,
        ...$overrides,
    ];
}

test('missing sources are created with their transportable fields', function () {
    app(SyncContentSources::class)->handle([
        transportableSource(),
        transportableSource([
            'source_id' => 'infschule-dualsystem',
            'kind' => 'web',
            'url' => 'https://inf-schule.de/dualsystem',
            'published_at' => '2024-08-01',
            'accessed_at' => '2026-08-07',
            'content_sha256' => str_repeat('a', 64),
        ]),
    ]);

    $web = ContentSource::firstWhere('source_id', 'infschule-dualsystem');

    expect(ContentSource::count())->toBe(2)
        ->and($web->kind)->toBe(SourceKind::Web)
        ->and($web->toTransportable())->toBe(transportableSource([
            'source_id' => 'infschule-dualsystem',
            'kind' => 'web',
            'url' => 'https://inf-schule.de/dualsystem',
            'published_at' => '2024-08-01',
            'accessed_at' => '2026-08-07',
            'content_sha256' => str_repeat('a', 64),
        ]));
});

test('an identical source is reused and a differing publisher is tolerated', function () {
    app(SyncContentSources::class)->handle([transportableSource()]);
    app(SyncContentSources::class)->handle([transportableSource(['publisher' => 'Anderer Verlag'])]);

    expect(ContentSource::count())->toBe(1)
        ->and(ContentSource::sole()->publisher)->toBe('Markus Kautz');
});

test('a source that differs in an identity field rejects the import', function (string $field, mixed $value) {
    app(SyncContentSources::class)->handle([transportableSource()]);

    expect(fn () => app(SyncContentSources::class)->handle([transportableSource([$field => $value])]))
        ->toThrow(RuntimeException::class, "Die Quelle scriptum existiert bereits mit abweichendem Feld {$field}.");
})->with([
    'kind' => ['kind', 'slides'],
    'title' => ['title', 'Anderer Titel'],
    'url' => ['url', 'https://example.com'],
    'content_sha256' => ['content_sha256', str_repeat('b', 64)],
]);

test('a conflicting source rolls back the whole path import', function () {
    ContentSource::factory()->create(['source_id' => 'scriptum', 'title' => 'Altes Scriptum']);
    $content = [
        'schema_version' => 2,
        ...ContentFileFixture::valid(),
        'sources' => [
            transportableSource(['source_id' => 'it-osi', 'kind' => 'slides', 'title' => 'IT-OSI']),
            transportableSource(),
        ],
    ];

    expect(fn () => app(ImportLearningPath::class)->create($content))->toThrow(RuntimeException::class);

    expect(LearningPath::count())->toBe(0)
        ->and(ContentSource::pluck('source_id')->all())->toBe(['scriptum']);
});

test('the source form works from the database without the source archive', function () {
    // Ohne Quellenarchiv in Produktion (E11): Die Registry aus Dateien darf
    // gar nicht erst angefasst werden.
    $loader = Mockery::mock(LoadSourceRegistry::class);
    $loader->shouldNotReceive('handle');
    app()->instance(LoadSourceRegistry::class, $loader);

    ContentSource::factory()->slides()->create(['source_id' => 'it-osi']);
    $lesson = Lesson::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.lessons.edit', ['learningPath' => $lesson->learningPath, 'lesson' => $lesson])
        ->set('sourceReference.references.0.source_id', 'it-osi')
        ->set('sourceReference.references.0.slide', 999)
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($lesson->refresh()->source_reference)->toBe([['source_id' => 'it-osi', 'slide' => 999]]);
});

test('the source form rejects ids the database does not know and wrong locators', function (array $reference) {
    ContentSource::factory()->slides()->create(['source_id' => 'it-osi']);
    $lesson = Lesson::factory()->create();

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.lessons.edit', ['learningPath' => $lesson->learningPath, 'lesson' => $lesson]);

    foreach ($reference as $field => $value) {
        $component->set("sourceReference.references.0.{$field}", $value);
    }

    $component->call('saveDetails')->assertHasErrors('sourceReference.references');
})->with([
    'unbekannte Quelle' => [['source_id' => 'scriptum', 'page' => 3]],
    'falscher Locator' => [['source_id' => 'it-osi', 'page' => 3]],
]);
