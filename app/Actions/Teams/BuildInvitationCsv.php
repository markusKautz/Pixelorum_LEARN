<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\URL;

/**
 * Das Zeilen-Shape des Aktivierungslink-Exports an einer Stelle.
 *
 * Der Aktivierungslink ist keine Eigenheit der E-Mail: er entsteht auch dort
 * aus `URL::signedRoute('invitations.accept', …)`. Weil die CSV denselben Link
 * transportiert, wird er hier einmal gebaut und von Anhängen, Neu-Erzeugen und
 * Sammel-Import gemeinsam benutzt. Die Klasse formatiert nur, sie schreibt
 * nichts.
 */
class BuildInvitationCsv
{
    /** Das Datumsformat, das der Ausbildner auf Papier und in Excel liest. */
    public const DATE_FORMAT = 'd.m.Y H:i';

    /**
     * Die Kopfzeile der Datei.
     *
     * Die Statusspalte gibt es nur beim Neu-Erzeugen aus der Datenbank. Eine
     * angehängte Zeile ist per Definition offen und wird nie wieder angefasst.
     *
     * @return array<int, string>
     */
    public function header(bool $withStatus = false): array
    {
        $header = [
            __('Group'),
            __('Email'),
            __('Role'),
            __('Activation link'),
            __('Invited at'),
            __('Valid until'),
            __('Invited by'),
        ];

        if ($withStatus) {
            $header[] = __('Status');
        }

        return $header;
    }

    /**
     * Eine Einladung als Zeile.
     *
     * @return array<int, string>
     */
    public function row(TeamInvitation $invitation, bool $withStatus = false): array
    {
        $invitation->loadMissing(['team', 'inviter']);

        $row = [
            $invitation->team->name,
            $invitation->email,
            $invitation->role->label(),
            $this->link($invitation),
            $invitation->created_at?->format(self::DATE_FORMAT) ?? '',
            $invitation->expires_at?->format(self::DATE_FORMAT) ?? '',
            $invitation->inviter->name,
        ];

        if ($withStatus) {
            $row[] = $this->status($invitation);
        }

        return $row;
    }

    /**
     * Der signierte Aktivierungslink, exakt so wie ihn die E-Mail versendet.
     *
     * Bewusst ohne Ablaufparameter: die Signatur selbst verfällt nie, die
     * Gültigkeit kommt allein aus `expires_at`. Sonst hätte ein Link zwei
     * Ablaufzeiten, die auseinanderlaufen können.
     */
    public function link(TeamInvitation $invitation): string
    {
        return URL::signedRoute('invitations.accept', ['invitation' => $invitation->code]);
    }

    /**
     * Der Zustand einer Einladung für die Statusspalte.
     */
    protected function status(TeamInvitation $invitation): string
    {
        return match (true) {
            $invitation->isAccepted() => __('Accepted'),
            $invitation->isExpired() => __('Expired'),
            default => __('Open'),
        };
    }
}
