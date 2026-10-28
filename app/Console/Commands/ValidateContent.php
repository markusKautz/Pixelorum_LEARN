<?php

namespace App\Console\Commands;

use App\Actions\Content\LoadSourceRegistry;
use App\Actions\Content\ValidateContentFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('lapp:content:validate
    {slug? : Optionaler Lernpfad-Slug}
    {--file=* : Content-JSON an beliebigem Ort, geprüft wie beim Admin-Upload}')]
#[Description('Validiert versionierte Lernpfad-JSONs und ihre Quellenbelege')]
class ValidateContent extends Command
{
    public function handle(LoadSourceRegistry $loadRegistry, ValidateContentFile $validateContentFile): int
    {
        $argument = $this->input->getArgument('slug');
        $paths = array_values(array_filter((array) $this->option('file'), is_string(...)));

        if ($paths !== []) {
            if ($argument !== null) {
                $this->components->error('--file und ein Lernpfad-Slug schließen sich aus.');

                return self::FAILURE;
            }

            return $this->validateStandaloneFiles($validateContentFile, $paths);
        }

        if (! is_dir($this->directories()[0])) {
            $this->components->error('Kein Content-Verzeichnis gefunden: '.$this->relativeDirectories()[0]);
            $this->line('Die lokale Pipeline braucht das Verzeichnis aus `lapp.content_root`. Einzelne Dateien prüft `--file`.');

            return self::FAILURE;
        }

        if ($argument !== null && (! is_string($argument)
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $argument) !== 1)) {
            $this->components->error('Der Lernpfad-Slug ist ungültig.');

            return self::FAILURE;
        }

        if (is_string($argument)) {
            $files = $this->filesForSlug($argument);

            if ($files === []) {
                // Die Verzeichnisse als eigene Zeilen: Eine einzeilige Meldung
                // umbricht im Terminal mitten im Pfad und ist dann schlechter
                // zu lesen als die Liste.
                $this->components->error("Content-Datei fehlt: {$argument}.json");
                $this->components->bulletList($this->relativeDirectories());

                return self::FAILURE;
            }
        } else {
            $files = $this->allFiles();
        }

        if ($files === []) {
            $this->components->info('0 Content-Dateien wurden validiert.');

            return self::SUCCESS;
        }

        try {
            $registry = $loadRegistry->handle();
            $slugs = [];

            foreach ($files as $file) {
                $content = $validateContentFile->handle($file, $registry);
                $slug = (string) $content['slug'];

                if (in_array($slug, $slugs, true)) {
                    throw new RuntimeException("Lernpfad-Slug ist mehrfach vorhanden: {$slug}");
                }

                $slugs[] = $slug;
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(count($files).' Content-Dateien wurden validiert.');

        return self::SUCCESS;
    }

    /**
     * Check content files outside the project the way the admin upload does.
     *
     * Für Autoren außerhalb der Projekt-Pipeline, etwa über den Skill
     * `learn-content`: ohne Quellenarchiv und ohne Datenbank, dafür mit allen
     * Befunden auf einmal statt nur dem ersten. Verlangt Schema-Version 2 oder
     * höher, weil erst dann die Quellen in der Datei selbst stehen.
     *
     * @param  list<string>  $paths
     */
    protected function validateStandaloneFiles(ValidateContentFile $validateContentFile, array $paths): int
    {
        $problems = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                $problems[] = "{$path}: Datei nicht gefunden.";

                continue;
            }

            $contents = (string) file_get_contents($path);
            $decoded = json_decode($contents, true);

            if (is_array($decoded) && $validateContentFile->schemaVersionOf($decoded) < 2) {
                $problems[] = "{$path}: Schema-Version 1 trägt keine Quellen. `schema_version` auf ".ValidateContentFile::SCHEMA_VERSION_CURRENT.' setzen und einen `sources`-Block ergänzen.';

                continue;
            }

            $problems = [...$problems, ...$validateContentFile->problemsIn($contents, $path)];
        }

        if ($problems !== []) {
            $this->components->error(count($problems).' Problem(e) gefunden:');

            // Eine Zeile je Befund und ohne Umbruch: Die Ausgabe liest oft ein
            // Agent, der Feldpfad und Meldung zusammen braucht.
            foreach ($problems as $problem) {
                $this->line("- {$problem}");
            }

            return self::FAILURE;
        }

        $this->components->info(count($paths).' Content-Dateien wurden validiert.');

        return self::SUCCESS;
    }

    /**
     * Die Verzeichnisse, in denen validierbare Pfad-JSONs liegen.
     *
     * Nur `content/` unter dem Content-Root der lokalen Pipeline
     * (`config/lapp.php`). Er gehört nicht zum Repository; die Liste bleibt,
     * damit Slug-Prüfung und Meldungen nicht an einem einzelnen Ort hängen.
     *
     * @return list<string>
     */
    protected function directories(): array
    {
        return [base_path(LoadSourceRegistry::contentRoot().'/content')];
    }

    /**
     * Every content file, grouped by directory and sorted within each.
     *
     * @return list<string>
     */
    protected function allFiles(): array
    {
        $files = [];

        foreach ($this->directories() as $directory) {
            $found = glob("{$directory}/*.json") ?: [];
            sort($found);
            $files = [...$files, ...$found];
        }

        return $files;
    }

    /**
     * The files a slug resolves to — more than one means it exists twice.
     *
     * @return list<string>
     */
    protected function filesForSlug(string $slug): array
    {
        $files = [];

        foreach ($this->directories() as $directory) {
            $file = "{$directory}/{$slug}.json";

            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The directories as repository-relative paths, for error messages.
     *
     * @return list<string>
     */
    protected function relativeDirectories(): array
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return array_map(
            static fn (string $directory): string => str_replace($base, '', $directory),
            $this->directories(),
        );
    }
}
