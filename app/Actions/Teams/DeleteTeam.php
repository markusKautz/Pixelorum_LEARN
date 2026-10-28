<?php

namespace App\Actions\Teams;

use App\Actions\Users\DeleteUser;
use App\Concerns\GeneratesUniqueSlugs;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eine Gruppe endgültig löschen (S5.7.5).
 *
 * **Hart**, nicht per Soft Delete: ohne Aufbewahrungsfrist (Entscheidung 1) wäre
 * ein Zeitstempel widersprüchlich — er verspricht Löschung und behält alles. Wer
 * einen Jahrgang nur abschließen will, archiviert ihn (S5.7.1).
 *
 * `forceDelete()` ist nötig, weil `Team` weiterhin `SoftDeletes` nutzt: das
 * Trait bleibt, weil {@see GeneratesUniqueSlugs} auf
 * `withTrashed()` aufbaut und die Slug-Vergabe sonst bricht.
 *
 * Mitgliedschaften, Einladungen und Quiz-Zuweisungen nimmt die Datenbank mit —
 * alle drei Fremdschlüssel auf `teams` stehen auf `cascadeOnDelete`.
 * `users.current_team_id` fällt per `nullOnDelete` auf NULL; ein behaltener
 * Lehrling landet beim nächsten Login über `RedirectsToCurrentTeam` in seinem
 * persönlichen Team (Entscheidung 8: das bleibt so, die Inhalte sind global).
 */
class DeleteTeam
{
    public function __construct(protected DeleteUser $deleteUser)
    {
        //
    }

    /**
     * Delete the group; optionally delete its apprentices along with it.
     *
     * @param  bool  $withApprentices  Konten der Lehrlinge mitlöschen
     * @return int wie viele Konten mitgelöscht wurden
     */
    public function handle(Team $team, bool $withApprentices = false): int
    {
        return DB::transaction(function () use ($team, $withApprentices): int {
            // **Vor** dem Löschen einsammeln: die Mitgliedschaften, über die man
            // die Lehrlinge findet, kaskadieren mit der Gruppe weg.
            //
            // `apprentices()` filtert über `users.is_admin` — ein Ausbildner, der
            // dieser Gruppe angehört, ist damit auch dann nicht betroffen, wenn er
            // per Einladung mit `role = member` darin steckt (Entscheidung 4).
            /** @var Collection<int, User> $apprentices */
            $apprentices = $withApprentices
                ? $team->apprentices()->get()
                : new Collection;

            $team->forceDelete();

            // Über DeleteUser, damit es nur *eine* Stelle gibt, die weiß, was zu
            // einem Nutzer gehört — inklusive Sitzungen, Einladungen an seine
            // Adresse und seinem persönlichen Team.
            $apprentices->each(fn (User $apprentice) => $this->deleteUser->handle($apprentice));

            return $apprentices->count();
        });
    }
}
