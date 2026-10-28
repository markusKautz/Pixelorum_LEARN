<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $team = $this->currentTeam($request);

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->personalTeam();

        if (! $team) {
            $this->rejectTeamlessUser($request);
        }

        return $team;
    }

    /**
     * Ein angemeldeter Nutzer ohne Gruppe ist kein gültiger Zustand: Fortify hat
     * ihn zu diesem Zeitpunkt bereits authentifiziert, ein blosses abort(403)
     * ließe ihn eingeloggt vor einer Fehlerseite zurück — mit einer Welcome-Seite
     * ohne Ausweg und einem /login, das ihn im Kreis auf dieselbe 403 schickt.
     * Deshalb wird die Sitzung verworfen; der Rückweg in eine Gruppe ist der
     * signierte Einladungslink, der ohnehin keinen Login voraussetzt.
     */
    protected function rejectTeamlessUser(Request $request): never
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(redirect()->route('login')->with(
            'status',
            __('You are currently not assigned to a group. Please contact your training supervisor.')
        ));
    }
}
