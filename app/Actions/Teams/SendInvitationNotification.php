<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Verschickt die Einladungsmail, ohne den Vorgang daran scheitern zu lassen.
 *
 * Die erste Instanz läuft lokal ohne Mailserver. Die Einladung ist zu diesem
 * Zeitpunkt bereits gespeichert und die CSV-Zeile geschrieben, der Link also
 * vollständig nutzbar. Ein fehlgeschlagener Transport ist damit eine Randnotiz
 * und kein Grund, dem Ausbildner einen Fehler zu zeigen und ihn den Vorgang
 * wiederholen zu lassen.
 */
class SendInvitationNotification
{
    /**
     * @return bool ob die Mail übergeben werden konnte
     */
    public function handle(TeamInvitation $invitation): bool
    {
        try {
            Notification::route('mail', $invitation->email)
                ->notify(new TeamInvitationNotification($invitation));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Einladungsmail konnte nicht zugestellt werden.', [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
