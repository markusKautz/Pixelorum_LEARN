<?php

namespace Tests\Support;

use App\Actions\Content\CollectContentSources;
use App\Actions\Content\LoadSourceRegistry;
use App\Models\ContentSource;
use Illuminate\Support\Facades\File;
use Mockery;

/**
 * Dynamische Test-Fixture für Quellen-Registry-Einträge.
 *
 * Erzeugt Extrakte und Karten in einem prozessisolierten Testverzeichnis,
 * liefert eine Registry zurück, die direkt an die SourceReferences-Rule übergeben
 * werden kann, und bietet einen Mockery-Wrapper für UI-Tests.
 */
class ContentSourceFixture
{
    /**
     * Erzeugt die Fixture-Dateien und liefert die Registry zurück.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        $base = self::directory();

        if (! File::isDirectory($base)) {
            File::makeDirectory($base, 0755, true);
        }

        // scriptum extract: PAGE 1, PAGE 12, PAGE 174
        $scriptumExtract = "=== PAGE 1 ===\nContent page 1\n=== PAGE 12 ===\nContent page 12\n=== PAGE 174 ===\nContent page 174";
        File::put("{$base}/scriptum-extract.txt", $scriptumExtract);

        // it-osi extract: PAGE 1, PAGE 4, PAGE 15
        $itOsiExtract = "=== PAGE 1 ===\nSlide 1\n=== PAGE 4 ===\nSlide 4\n=== PAGE 15 ===\nSlide 15";
        File::put("{$base}/it-osi-extract.txt", $itOsiExtract);

        // web card: # Official source and ## Approved section
        $webCard = "# Official source\n\n## Approved section\n\nSome content here.";
        File::put("{$base}/official-source-card.md", $webCard);

        $scriptumHash = hash_file('sha256', "{$base}/scriptum-extract.txt");
        $itOsiHash = hash_file('sha256', "{$base}/it-osi-extract.txt");
        $webHash = hash_file('sha256', "{$base}/official-source-card.md");

        return [
            'scriptum' => [
                'source_id' => 'scriptum',
                'kind' => 'pdf',
                'title' => 'Scriptum PDF',
                'publisher' => 'Test Publisher',
                'path' => "{$base}/scriptum.pdf",
                'extract_path' => "{$base}/scriptum-extract.txt",
                'version' => '1.0',
                'published_at' => '2024-01-01',
                'content_sha256' => $scriptumHash,
            ],
            'it-osi' => [
                'source_id' => 'it-osi',
                'kind' => 'slides',
                'title' => 'IT-OSI Slides',
                'publisher' => 'Test Publisher',
                'path' => "{$base}/it-osi.pdf",
                'extract_path' => "{$base}/it-osi-extract.txt",
                'version' => '1.0',
                'published_at' => '2024-01-01',
                'content_sha256' => $itOsiHash,
            ],
            'official-source' => [
                'source_id' => 'official-source',
                'kind' => 'web',
                'title' => 'Official Web Source',
                'publisher' => 'Test Publisher',
                'url' => 'https://example.com/official',
                'accessed_at' => '2024-01-15',
                'approved_at' => '2024-01-20',
                'snapshot_path' => "{$base}/official-source-card.md",
                'content_sha256' => $webHash,
            ],
        ];
    }

    /**
     * Bindet eine Mockery-Instanz von LoadSourceRegistry in den Container,
     * die bei handle() die Fixture-Registry zurückgibt, und legt dieselben
     * Quellen in `content_sources` an, gegen die Admin-Formulare prüfen.
     *
     * @return array<string, array<string, mixed>> Die gebundene Registry
     */
    public static function bind(): array
    {
        $registry = self::registry();
        $loader = Mockery::mock(LoadSourceRegistry::class);
        $loader->shouldReceive('handle')->andReturn($registry);
        app()->instance(LoadSourceRegistry::class, $loader);

        self::store($registry);

        return $registry;
    }

    /**
     * Legt die Fixture-Quellen in `content_sources` an.
     *
     * @param  array<string, array<string, mixed>>|null  $registry
     */
    public static function store(?array $registry = null): void
    {
        $collect = new CollectContentSources;

        foreach ($registry ?? self::registry() as $source) {
            ContentSource::firstOrCreate(
                ['source_id' => $source['source_id']],
                $collect->transportable($source),
            );
        }
    }

    /**
     * Entfernt das Fixture-Verzeichnis.
     */
    public static function clean(): void
    {
        if (function_exists('app')) {
            app()->forgetInstance(LoadSourceRegistry::class);
        }

        File::deleteDirectory(self::directory());
    }

    private static function directory(): string
    {
        return storage_path('framework/testing/content-source-fixture-'.getmypid());
    }
}
