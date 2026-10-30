<?php

namespace App\Actions\Teams;

use App\Enums\InvitationImportStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Legt aus den geprüften Zeilen einer Importdatei die Einladungen an.
 *
 * Fehlerhafte Zeilen blockieren die übrigen nicht: bei einer ganzen Gruppe ist
 * meistens genau eine Adresse vertippt, und ein Komplettabbruch zwänge zum
 * erneuten Hochladen. Der Durchgang läuft trotzdem in einer Transaktion, damit
 * ein Datenbankfehler mittendrin keine halbe Gruppe hinterlässt.
 */
class ImportInvitations
{
    public function __construct(
        protected AppendInvitationToCsv $appendToCsv,
        protected SendInvitationNotification $sendNotification,
    ) {}

    /**
     * @param  Collection<int, array{email: string, status: InvitationImportStatus}>  $rows
     * @return array{invited: int, skipped: array<int, array{email: string, reason: string}>}
     */
    public function handle(Team $team, Collection $rows, User $inviter): array
    {
        $invitable = $rows->filter(fn (array $row): bool => $row['status']->isInvitable());

        $invitations = DB::transaction(function () use ($team, $invitable, $inviter): Collection {
            return $invitable->map(function (array $row) use ($team, $inviter): TeamInvitation {
                $invitation = $team->invitations()->create([
                    'email' => $row['email'],
                    'role' => TeamRole::Member,
                    'invited_by' => $inviter->id,
                    'expires_at' => now()->addDays(TeamInvitation::EXPIRY_DAYS),
                ]);

                $invitation->setRelation('team', $team);

                $this->appendToCsv->handle($invitation);

                return $invitation;
            });
        });

        /*
         * Der Mailversuch steht außerhalb der Transaktion: er darf weder etwas
         * zurückrollen noch die Sperren länger halten als nötig. Ohne
         * Mailserver ist die CSV ohnehin der Zustellweg.
         */
        $invitations->each(fn (TeamInvitation $invitation) => $this->sendNotification->handle($invitation));

        return [
            'invited' => $invitations->count(),
            'skipped' => $rows
                ->reject(fn (array $row): bool => $row['status']->isInvitable())
                ->map(fn (array $row): array => [
                    'email' => $row['email'],
                    'reason' => $row['status']->label(),
                ])
                ->values()
                ->all(),
        ];
    }
}
