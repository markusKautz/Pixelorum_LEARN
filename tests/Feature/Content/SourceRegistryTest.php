<?php

use App\Actions\Content\LoadSourceRegistry;
use Illuminate\Support\Facades\File;

function testDir(): string
{
    return storage_path('framework/testing/content-sources');
}

function cleanupTestDir(): void
{
    $dir = testDir();
    if (File::isDirectory($dir)) {
        File::deleteDirectory($dir);
    }
    File::makeDirectory($dir, 0755, true);
}

function writeJson(string $path, array $data): void
{
    File::put($path, json_encode($data, JSON_THROW_ON_ERROR));
}

function validLocal(): array
{
    return [
        'source_id' => 'test-local',
        'kind' => 'pdf',
        'title' => 'Test Local Source',
        'publisher' => 'Test Publisher',
        'path' => 'doc/ressource/slides/test.pdf',
        'extract_path' => 'doc/ressource/extracts/test.txt',
    ];
}

function validWeb(string $snapshotPath, string $hash): array
{
    return [
        'source_id' => 'test-web',
        'kind' => 'web',
        'title' => 'Test Web Source',
        'publisher' => 'Test Publisher',
        'url' => 'https://example.com/article',
        'accessed_at' => '2024-01-15',
        'approved_at' => '2024-01-20',
        'snapshot_path' => $snapshotPath,
        'content_sha256' => $hash,
    ];
}

beforeEach(function () {
    cleanupTestDir();
});

afterEach(function () {
    cleanupTestDir();
});

it('loads valid local sources', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validLocal()]);

    $result = $action->handle("{$testDir}/registry.json");

    expect($result)->toHaveKey('test-local')
        ->and($result['test-web'] ?? null)->toBeNull()
        ->and($result['test-local']['kind'])->toBe('pdf')
        ->and($result['test-local']['path'])->toBe('doc/ressource/slides/test.pdf')
        ->and($result['test-local']['extract_path'])->toBe('doc/ressource/extracts/test.txt');
});

it('loads valid web sources', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validWeb($snapshotPath, $hash)]);

    $result = $action->handle("{$testDir}/registry.json");

    expect($result)->toHaveKey('test-web')
        ->and($result['test-web']['kind'])->toBe('web')
        ->and($result['test-web']['snapshot_path'])->toBe($snapshotPath)
        ->and($result['test-web']['content_sha256'])->toBe($hash);
});

it('rejects duplicate source ids', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validLocal(), validLocal()]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('wraps malformed json in runtime exception with previous exception and path', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    file_put_contents("{$testDir}/registry.json", 'not valid json {{');

    try {
        $action->handle("{$testDir}/registry.json");
    } catch (RuntimeException $e) {
        expect($e->getPrevious())->toBeInstanceOf(JsonException::class)
            ->and($e->getMessage())->toContain("{$testDir}/registry.json");
        throw $e;
    }
})->throws(RuntimeException::class);

it('rejects web hash mismatch', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');

    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validWeb($snapshotPath, '0000000000000000000000000000000000000000000000000000000000000000')]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects missing web snapshot file', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validWeb("{$testDir}/missing.html", '0000000000000000000000000000000000000000000000000000000000000000')]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects invalid hash shape', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');

    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validWeb($snapshotPath, 'NOT64HEX')]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects snapshot file that is unhashable', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot_dir";
    File::makeDirectory($snapshotPath);

    $action = new LoadSourceRegistry;
    writeJson("{$testDir}/registry.json", [validWeb($snapshotPath, '0000000000000000000000000000000000000000000000000000000000000000')]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'Snapshot file unhashable');

it('rejects local source with web-only fields filled', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['url'] = 'https://example.com';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects web source with local-only fields filled', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    $data = validWeb($snapshotPath, $hash);
    $data['path'] = 'doc/ressource/test.pdf';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects missing local path', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    unset($data['path']);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects missing local extract_path', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    unset($data['extract_path']);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects missing kind and reports missing required field', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    unset($data['kind']);
    writeJson("{$testDir}/registry.json", [$data]);

    try {
        $action->handle("{$testDir}/registry.json");
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain("Missing required field 'kind'");
        throw $e;
    }
})->throws(RuntimeException::class);

it('rejects missing web required fields', function (string $field) {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    $data = validWeb($snapshotPath, $hash);
    unset($data[$field]);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class)->with([
    'url',
    'accessed_at',
    'approved_at',
    'snapshot_path',
    'content_sha256',
]);

it('rejects unknown fields', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['unknown_field'] = 'value';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects source_id over 100 characters', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['source_id'] = str_repeat('a', 101);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects title over 255 characters', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['title'] = str_repeat('a', 256);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects publisher over 255 characters', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['publisher'] = str_repeat('a', 256);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects multibyte title at 255 character boundary', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    // 255 'ä' characters = 510 bytes; mb_strlen should see 255 chars
    $data['title'] = str_repeat('ä', 256);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'title exceeds 255 characters');

it('rejects multibyte publisher at 255 character boundary', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['publisher'] = str_repeat('ü', 256);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'publisher exceeds 255 characters');

it('rejects version over 100 characters', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['version'] = str_repeat('a', 101);
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'version exceeds 100 characters');

it('rejects invalid version type', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['version'] = 123;
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'Invalid type for field \'version\'');

it('accepts valid version', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['version'] = 'v1.2.3';
    writeJson("{$testDir}/registry.json", [$data]);

    $result = $action->handle("{$testDir}/registry.json");

    expect($result['test-local']['version'])->toBe('v1.2.3');
});

it('rejects bad url for web sources', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    $data = validWeb($snapshotPath, $hash);
    $data['url'] = 'ftp://example.com/article';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects bad date format', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['published_at'] = '15-01-2024';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects invalid source_id format', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $data = validLocal();
    $data['source_id'] = 'Test_PDF';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('wraps missing file in runtime exception', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $action->handle("{$testDir}/does-not-exist.json");
})->throws(RuntimeException::class);

it('wraps unreadable file in runtime exception', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $path = "{$testDir}/unreadable.json";
    file_put_contents($path, '[]');
    chmod($path, 0000);

    try {
        if (is_readable($path)) {
            $this->markTestSkipped('Runtime can still read chmod(0000) files (likely running as root)');
        }

        $action->handle($path);
    } finally {
        chmod($path, 0644);
    }
})->throws(RuntimeException::class);

it('loads the registry under the configured content root when no path is given', function () {
    $root = 'storage/framework/testing/content-sources/root';
    config(['lapp.content_root' => $root]);
    File::makeDirectory(base_path("{$root}/ressource"), 0755, true);

    $data = validLocal();
    $data['path'] = "{$root}/ressource/slides/test.pdf";
    $data['extract_path'] = "{$root}/ressource/extract/test.txt";
    writeJson(base_path("{$root}/ressource/sources.json"), [$data]);

    expect(LoadSourceRegistry::defaultPath())->toBe(base_path("{$root}/ressource/sources.json"))
        ->and(array_keys((new LoadSourceRegistry)->handle()))->toBe(['test-local']);
});

it('enforces containment under the configured content root for the default registry', function () {
    $root = 'storage/framework/testing/content-sources/root';
    config(['lapp.content_root' => $root]);
    File::makeDirectory(base_path("{$root}/ressource"), 0755, true);

    writeJson(base_path("{$root}/ressource/sources.json"), [validLocal()]);

    (new LoadSourceRegistry)->handle();
})->throws(RuntimeException::class, 'path');

it('reports a missing default registry', function () {
    config(['lapp.content_root' => 'storage/framework/testing/content-sources/fehlt']);

    (new LoadSourceRegistry)->handle();
})->throws(RuntimeException::class, 'Source registry not found');

it('rejects wrong typed fields with runtime exception not type error', function (string $field, mixed $badValue, string $baseKind) {
    $testDir = testDir();
    $action = new LoadSourceRegistry;

    if ($baseKind === 'local') {
        $data = validLocal();
    } else {
        $snapshotPath = "{$testDir}/snapshot.html";
        file_put_contents($snapshotPath, '<html>test content</html>');
        $hash = hash_file('sha256', $snapshotPath);
        $data = validWeb($snapshotPath, $hash);
    }

    $data[$field] = $badValue;
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class)->with([
    'source_id int on local' => ['source_id', 0, 'local'],
    'title array on local' => ['title', [], 'local'],
    'publisher bool on local' => ['publisher', false, 'local'],
    'kind array on local' => ['kind', [], 'local'],
    'path int on local' => ['path', 123, 'local'],
    'extract_path bool on local' => ['extract_path', false, 'local'],
    'published_at bool on local' => ['published_at', true, 'local'],
    'url int on web' => ['url', 123, 'web'],
    'accessed_at bool on web' => ['accessed_at', false, 'web'],
    'approved_at array on web' => ['approved_at', [], 'web'],
    'snapshot_path array on web' => ['snapshot_path', [], 'web'],
    'content_sha256 int on web' => ['content_sha256', 123, 'web'],
]);

it('rejects url without host', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    $data = validWeb($snapshotPath, $hash);
    $data['url'] = 'https://';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects url with spaces in host', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    $data = validWeb($snapshotPath, $hash);
    $data['url'] = 'https://not a valid host';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class);

it('rejects local path traversal when containment is enforced', function () {
    $testDir = testDir();
    $action = (new LoadSourceRegistry)->enforceContainment();
    $data = validLocal();
    $data['path'] = 'doc/ressource/../../../etc/passwd';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'path');

it('rejects absolute local path outside the resource directory when containment is enforced', function () {
    $testDir = testDir();
    $action = (new LoadSourceRegistry)->enforceContainment();
    $data = validLocal();
    $data['path'] = '/etc/passwd';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'path');

it('rejects extract_path outside the extract directory when containment is enforced', function () {
    $testDir = testDir();
    $action = (new LoadSourceRegistry)->enforceContainment();
    $data = validLocal();
    $data['extract_path'] = 'doc/ressource/test.txt';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'extract_path');

it('rejects extract_path not ending in .txt when containment is enforced', function () {
    $testDir = testDir();
    $action = (new LoadSourceRegistry)->enforceContainment();
    $data = validLocal();
    $data['extract_path'] = 'doc/ressource/extract/test.json';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'extract_path');

it('rejects web snapshot_path outside the web snapshot directory when containment is enforced', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = (new LoadSourceRegistry)->enforceContainment();
    $data = validWeb($snapshotPath, $hash);
    $data['snapshot_path'] = 'doc/ressource/test.html';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'snapshot_path');

it('rejects web snapshot_path not ending in .md when containment is enforced', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = (new LoadSourceRegistry)->enforceContainment();
    $data = validWeb($snapshotPath, $hash);
    $data['snapshot_path'] = 'doc/ressource/web/test.html';
    writeJson("{$testDir}/registry.json", [$data]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'snapshot_path');

it('rejects duplicate extract_path values', function () {
    $testDir = testDir();
    $action = new LoadSourceRegistry;
    $first = validLocal();
    $first['source_id'] = 'first-source';
    $second = validLocal();
    $second['source_id'] = 'second-source';
    writeJson("{$testDir}/registry.json", [$first, $second]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'extract_path');

it('rejects duplicate snapshot_path values', function () {
    $testDir = testDir();
    $snapshotPath = "{$testDir}/snapshot.html";
    file_put_contents($snapshotPath, '<html>test content</html>');
    $hash = hash_file('sha256', $snapshotPath);

    $action = new LoadSourceRegistry;
    $first = validWeb($snapshotPath, $hash);
    $first['source_id'] = 'first-web';
    $second = validWeb($snapshotPath, $hash);
    $second['source_id'] = 'second-web';
    writeJson("{$testDir}/registry.json", [$first, $second]);

    $action->handle("{$testDir}/registry.json");
})->throws(RuntimeException::class, 'snapshot_path');
