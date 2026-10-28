<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function testBase(): string
{
    return storage_path('framework/testing/content-extract');
}

beforeEach(function () {
    File::deleteDirectory(testBase());
    if (! File::exists(testBase())) {
        File::makeDirectory(testBase(), recursive: true);
    }
    Process::preventStrayProcesses();
});

afterEach(function () {
    File::deleteDirectory(testBase());
    Process::preventStrayProcesses(false);
});

function createFakeRegistry(string $base, array $sources): string
{
    $path = $base.'/sources.json';
    File::put($path, json_encode($sources, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $path;
}

function fakePdfFile(string $path): void
{
    $dir = dirname($path);
    if (! File::exists($dir)) {
        File::makeDirectory($dir, recursive: true);
    }
    File::put($path, 'fake-pdf-content');
}

test('two-page local source writes PAGE markers, removes trailing whitespace, and second run is byte-identical', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'local-pdf',
            'kind' => 'pdf',
            'title' => 'Test PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    $pdfinfoOutput = "Title: Test\nPages: 2\nPage size: 612 x 792 pts\n";
    $pdftotextOutput = "Page one line.   \t \nAnother line.\fPage two line.\n";

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: $pdfinfoOutput),
        "'pdftotext' '-layout' '*" => Process::result(output: $pdftotextOutput),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->expectsOutputToContain('1 lokale Quellen wurden extrahiert.')
        ->assertSuccessful();

    $expected = <<<'TXT'
=== PAGE 1 ===
Page one line.
Another line.

=== PAGE 2 ===
Page two line.

TXT;

    expect(File::exists($extractPath))->toBeTrue()
        ->and(File::get($extractPath))->toBe($expected);

    $firstRunBytes = File::get($extractPath);

    // Second run must be byte-identical
    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->assertSuccessful();

    expect(File::get($extractPath))->toBe($firstRunBytes);
});

test('web sources are skipped and count reflects only local', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'local-pdf',
            'kind' => 'pdf',
            'title' => 'Test PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
        [
            'source_id' => 'web-source',
            'kind' => 'web',
            'title' => 'Web Article',
            'publisher' => 'Web Publisher',
            'url' => 'https://example.com/article',
            'accessed_at' => '2024-01-15',
            'approved_at' => '2024-01-20',
            'snapshot_path' => $base.'/snapshot.html',
            'content_sha256' => 'bc7c8fc96a25bf6ff242fc73cce18291deac4505886b017220a151d0d8192d36',
        ],
    ]);

    $snapshotContent = '<html>test</html>';
    File::put($base.'/snapshot.html', $snapshotContent);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 1\n"),
        "'pdftotext' '-layout' '*" => Process::result(output: "Local content.\n"),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->expectsOutputToContain('1 lokale Quellen wurden extrahiert.')
        ->assertSuccessful();

    expect(File::exists($extractPath))->toBeTrue();
});

test('missing tools fail before registry load with clear German message', function () {
    $base = testBase();
    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'local-pdf',
            'kind' => 'pdf',
            'title' => 'Test PDF',
            'publisher' => 'Test Publisher',
            'path' => $base.'/input.pdf',
            'extract_path' => $base.'/output.txt',
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(errorOutput: '', exitCode: 1),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
    ]);

    $exitCode = Artisan::call('lapp:content:extract', ['--registry' => $registryPath]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('pdfinfo')
        ->and($output)->toContain('Poppler');

    // Should fail before loading registry (no pdfinfo call, no pdftotext call for extraction)
    Process::assertNotRan(fn ($process) => is_array($process->command) && isset($process->command[0]) && $process->command[0] === 'pdfinfo');
});

test('image-only PDF output fails and creates no extract', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'image-pdf',
            'kind' => 'pdf',
            'title' => 'Image PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 1\n"),
        "'pdftotext' '-layout' '*" => Process::result(output: "\f"),
    ]);

    $exitCode = Artisan::call('lapp:content:extract', ['--registry' => $registryPath]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('keinen verwendbaren Text');

    expect(File::exists($extractPath))->toBeFalse();
});

test('mismatch between pdfinfo page count and form-feed pages fails', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'mismatch-pdf',
            'kind' => 'pdf',
            'title' => 'Mismatch PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 3\n"),
        "'pdftotext' '-layout' '*" => Process::result(output: "Page one.\fPage two.\n"),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->expectsOutputToContain('Seitenzahl')
        ->assertFailed();

    expect(File::exists($extractPath))->toBeFalse();
});

test('failed pdfinfo prints clear error', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'bad-pdf',
            'kind' => 'pdf',
            'title' => 'Bad PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(errorOutput: 'Error opening file', exitCode: 1),
    ]);

    $exitCode = Artisan::call('lapp:content:extract', ['--registry' => $registryPath]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('pdfinfo')
        ->and($output)->toContain('Error opening file');
});

test('failed pdftotext prints clear error including error output', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'bad-pdf',
            'kind' => 'pdf',
            'title' => 'Bad PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 1\n"),
        "'pdftotext' '-layout' '*" => Process::result(errorOutput: 'Permission denied', exitCode: 1),
    ]);

    $exitCode = Artisan::call('lapp:content:extract', ['--registry' => $registryPath]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('pdftotext')
        ->and($output)->toContain('Permission denied');
});

test('missing original file prints clear error', function () {
    $base = testBase();
    $extractPath = $base.'/output.txt';

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'missing-pdf',
            'kind' => 'pdf',
            'title' => 'Missing PDF',
            'publisher' => 'Test Publisher',
            'path' => $base.'/nonexistent.pdf',
            'extract_path' => $extractPath,
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->expectsOutputToContain('nicht gefunden')
        ->assertFailed();
});

test('blank final physical page is preserved as PAGE marker', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'blank-final',
            'kind' => 'pdf',
            'title' => 'Blank Final PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    // Page 1 has text, page 2 is genuinely blank; terminal empty segment
    // from pdftotext must be removed without swallowing the blank page.
    $pdftotextOutput = "Text on page one.\n\f\f";

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 2\n"),
        "'pdftotext' '-layout' '*" => Process::result(output: $pdftotextOutput),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->expectsOutputToContain('1 lokale Quellen wurden extrahiert.')
        ->assertSuccessful();

    $content = File::get($extractPath);

    expect($content)->toContain('=== PAGE 1 ===')
        ->and($content)->toContain('=== PAGE 2 ===');
});

test('pdftotext output ending in form-feed plus trailing whitespace succeeds', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $registryPath = createFakeRegistry($base, [
        [
            'source_id' => 'trailing-whitespace',
            'kind' => 'pdf',
            'title' => 'Trailing Whitespace PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    // Single page with trailing \f\n — the terminal whitespace-only segment
    // must be removed so the page count matches pdfinfo.
    $pdftotextOutput = "Hello.\n\f\n";

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 1\n"),
        "'pdftotext' '-layout' '*" => Process::result(output: $pdftotextOutput),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $registryPath])
        ->expectsOutputToContain('1 lokale Quellen wurden extrahiert.')
        ->assertSuccessful();

    $expected = <<<'TXT'
=== PAGE 1 ===
Hello.

TXT;

    expect(File::get($extractPath))->toBe($expected);
});

test('custom registry path is passed through', function () {
    $base = testBase();
    $pdfPath = $base.'/input.pdf';
    $extractPath = $base.'/output.txt';

    fakePdfFile($pdfPath);

    $customRegistry = createFakeRegistry($base, [
        [
            'source_id' => 'custom-registry-pdf',
            'kind' => 'pdf',
            'title' => 'Custom Registry PDF',
            'publisher' => 'Test Publisher',
            'path' => $pdfPath,
            'extract_path' => $extractPath,
        ],
    ]);

    Process::fake([
        "'which' 'pdfinfo'" => Process::result(output: '/usr/bin/pdfinfo'),
        "'which' 'pdftotext'" => Process::result(output: '/usr/bin/pdftotext'),
        "'pdfinfo' '*" => Process::result(output: "Pages: 1\n"),
        "'pdftotext' '-layout' '*" => Process::result(output: "Custom content.\n"),
    ]);

    $this->artisan('lapp:content:extract', ['--registry' => $customRegistry])
        ->expectsOutputToContain('1 lokale Quellen wurden extrahiert.')
        ->assertSuccessful();

    expect(File::exists($extractPath))->toBeTrue();
});
