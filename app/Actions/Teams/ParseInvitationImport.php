<?php

namespace App\Actions\Teams;

use App\Enums\InvitationImportStatus;
use App\Models\Team;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Zerlegt eine hochgeladene Adressliste in bewertete Zeilen.
 *
 * Die Action **schreibt nichts**. Sie beantwortet nur die Frage, was beim
 * Absenden passieren würde, und ist damit die Grundlage der Vorschau.
 *
 * Ein Namensfeld gibt es bewusst nicht: `team_invitations` hat keine
 * Namensspalte, den Namen setzt die Teilnehmer_in beim Aktivieren selbst. Eine
 * importierte Namensspalte würde nirgends landen und nur Erwartungen wecken,
 * deshalb wird sie stillschweigend ignoriert.
 */
class ParseInvitationImport
{
    /**
     * @return Collection<int, array{email: string, status: InvitationImportStatus}>
     */
    public function handle(Team $team, string $contents): Collection
    {
        $rule = new UniqueTeamInvitation($team);
        $seen = [];

        return collect($this->emails($contents))
            ->map(function (string $email) use ($rule, &$seen): array {
                $status = $this->status($email, $rule, $seen);

                $seen[] = $email;

                return ['email' => $email, 'status' => $status];
            })
            ->values();
    }

    /**
     * Die Rohzeilen auf normalisierte Adressen herunterbrechen.
     *
     * Gelesen wird immer die **erste Spalte**, damit auch eine aus Excel
     * exportierte Datei mit Zusatzspalten funktioniert.
     *
     * @return list<string>
     */
    protected function emails(string $contents): array
    {
        $lines = Str::of($contents)
            ->replace("\r\n", "\n")
            ->explode("\n")
            ->map(fn (string $line): string => Str::lower(trim(trim(explode(';', $line)[0]), '"')))
            ->filter(fn (string $line): bool => $line !== '')
            // values() vorher, damit „erste Zeile" die erste *befüllte* Zeile
            // meint und eine führende Leerzeile die Kopfzeile nicht versteckt.
            ->values()
            ->reject(fn (string $line, int $index): bool => $index === 0 && $this->isHeader($line))
            ->all();

        return array_values($lines);
    }

    /**
     * Eine Kopfzeile erkennen, damit sie nicht als Adresse durchgeht.
     */
    protected function isHeader(string $line): bool
    {
        return in_array($line, ['e-mail', 'email', 'e-mail-adresse', 'email address', 'mail'], strict: true);
    }

    /**
     * Den Zustand einer einzelnen Adresse bestimmen.
     *
     * Für „schon Mitglied" und „schon eingeladen" wird bewusst dieselbe
     * `UniqueTeamInvitation`-Regel benutzt wie im Einzelmodal, statt die
     * Abfragen zu duplizieren. Ändert sich die Regel, ändert sie sich für
     * beide Wege.
     *
     * @param  array<int, string>  $seen
     */
    protected function status(string $email, UniqueTeamInvitation $rule, array $seen): InvitationImportStatus
    {
        if (in_array($email, $seen, strict: true)) {
            return InvitationImportStatus::DuplicateInFile;
        }

        if (Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            return InvitationImportStatus::InvalidEmail;
        }

        $message = Validator::make(['email' => $email], ['email' => [$rule]])
            ->errors()
            ->first('email');

        return match ($message) {
            '' => InvitationImportStatus::Valid,
            __('This user is already a member of the team.') => InvitationImportStatus::AlreadyMember,
            default => InvitationImportStatus::AlreadyInvited,
        };
    }
}
