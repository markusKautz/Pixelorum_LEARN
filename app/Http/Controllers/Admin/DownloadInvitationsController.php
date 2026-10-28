<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Teams\AppendInvitationToCsv;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadInvitationsController extends Controller
{
    /**
     * Die Aktivierungslinks einer Gruppe als CSV ausliefern.
     *
     * Die Datei liegt außerhalb des Web-Roots, weil sie E-Mail-Adressen
     * zusammen mit gültigen Zugangstokens enthält und damit wie eine
     * Passwortliste zu behandeln ist. Der einzige Weg dorthin führt über diese
     * Route und ihre Autorisierung.
     */
    public function __invoke(Team $team, AppendInvitationToCsv $append): StreamedResponse
    {
        abort_if($team->is_personal, 404);

        Gate::authorize('inviteMember', $team);

        $path = $append->existingPathFor($team);

        /*
         * Fehlt die Datei noch, etwa weil die Gruppe vor diesem Feature
         * entstanden ist, wird sie aus der Datenbank erzeugt statt einen 404 zu
         * zeigen. Ein leerer Download wäre für den Ausbildner nicht von einem
         * kaputten zu unterscheiden.
         */
        if ($path === null) {
            Artisan::call('lapp:invitations:export', ['team' => $team->slug]);

            $path = $append->existingPathFor($team);
        }

        return Storage::disk('local')->download(
            $path,
            "{$team->slug}-aktivierungslinks.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
