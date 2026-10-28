<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\BundleContentExport;
use App\Actions\Content\ExportLearningPath;
use App\Http\Controllers\Controller;
use App\Models\LearningPath;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportLearningPathController extends Controller
{
    /**
     * Einen Lernpfad als Content-JSON ausliefern (DEV-1).
     *
     * Die Datei kommt auch dann, wenn sie so nicht wieder hochladbar wäre
     * (E3). Die Warnungen zeigt die Detailansicht vor dem Download an. Mit
     * hochgeladenen Bildern kommt ein ZIP-Bündel statt der JSON-Datei (DEV-14).
     */
    public function __invoke(LearningPath $learningPath, ExportLearningPath $export, BundleContentExport $bundle): StreamedResponse|BinaryFileResponse
    {
        Gate::authorize('view', $learningPath);

        $result = $export->handle($learningPath);

        if ($result['media'] !== []) {
            return response()
                ->download($bundle->handle($result['json'], $result['media']), $result['filename'], ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend();
        }

        return response()->streamDownload(
            function () use ($result): void {
                echo $result['json'];
            },
            $result['filename'],
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
