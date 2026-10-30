<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMembership
{
    /**
     * Die Seiten, die einen *eigenen* Lernstand zeigen.
     *
     * Sie sind es, die Entscheidung 8 dem Admin verwehrt — nicht der
     * Teilnehmer_innen-Bereich als ganzer: `learn.paths.show`, `learn.lessons.show`
     * und `learn.quizzes.show` zeigen globale Inhalte und dienen ihm
     * ausdrücklich als Entwurfs-Vorschau (siehe `mount()` in
     * `pages/learn/paths/⚡show.blade.php`).
     *
     * @var list<string>
     */
    protected const APPRENTICE_ONLY_ROUTES = [
        'dashboard',
        'learn.progress',
        'learn.practice.create',
    ];

    /**
     * Handle an incoming request.
     *
     * Der Admin agiert global und hat auf den Teilnehmer_innen-Seiten oben nichts
     * verloren (Entscheidung 8). Die Weiche steht **vor** der
     * Mitgliedsprüfung, weil er den Gruppen der Teilnehmer_innen gerade nicht
     * angehört — sonst bräche der Wächter mit 403 ab, bevor eine Seite den
     * Sprung anbieten könnte. Genau das war F3.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $team] = [$request->user(), $this->team($request)];

        if ($this->isApprenticeOnlyRoute($request) && $user?->can('admin')) {
            return redirect()->route('admin.dashboard');
        }

        abort_if(! $user || ! $team || ! $user->belongsToTeam($team), 403);

        $this->ensureTeamMemberHasRequiredRole($user, $team, $minimumRole);

        if ($request->route('current_team') && ! $user->isCurrentTeam($team)) {
            $user->switchTeam($team);
        }

        return $next($request);
    }

    /**
     * Determine whether the request targets a page reserved for apprentices.
     *
     * Am Routennamen und nicht am Präfix `{current_team}`: die Middleware
     * hängt auch an `settings/teams/{team}` — der Gruppen-Detailseite, die dem
     * Admin gehört —, und innerhalb des Teilnehmer_innen-Bereichs sind die
     * Inhaltsseiten seine Entwurfs-Vorschau. Beides darf die Weiche nicht
     * greifen.
     */
    protected function isApprenticeOnlyRoute(Request $request): bool
    {
        return in_array($request->route()?->getName(), static::APPRENTICE_ONLY_ROUTES, strict: true);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureTeamMemberHasRequiredRole(User $user, Team $team, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->teamRole($team);

        $requiredRole = TeamRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the team associated with the request.
     */
    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        return $team;
    }
}
