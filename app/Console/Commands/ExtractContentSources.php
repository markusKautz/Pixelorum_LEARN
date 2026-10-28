<?php

namespace App\Console\Commands;

use App\Actions\Content\ExtractContentSources as ExtractContentSourcesAction;
use App\Actions\Content\LoadSourceRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

#[Signature('lapp:content:extract {--registry= : Alternativer Pfad zum Quellenregister}')]
#[Description('Extrahiert Text aus lokalen PDF- und Folien-Quellen')]
class ExtractContentSources extends Command
{
    public function handle(LoadSourceRegistry $loadRegistry, ExtractContentSourcesAction $extractSources): int
    {
        foreach (['pdfinfo', 'pdftotext'] as $binary) {
            $result = Process::timeout(10)->run(['which', $binary]);

            if (! $result->successful()) {
                $this->error("Das Tool '{$binary}' wurde nicht gefunden. Bitte Poppler installieren.");

                return self::FAILURE;
            }
        }

        $registryOption = $this->option('registry');
        $registryPath = is_string($registryOption) && $registryOption !== '' ? $registryOption : null;

        try {
            $registry = $loadRegistry->handle($registryPath);
            $results = $extractSources->handle($registry);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $count = count($results);
        $this->info("{$count} lokale Quellen wurden extrahiert.");

        return self::SUCCESS;
    }
}
