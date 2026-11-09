<?php

namespace App\Actions\Teams;

use App\Concerns\ProfileValidationRules;
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
 * Die zweite Spalte ist der Name (#23): `E-Mail;Name`. Leer heißt, die
 * Teilnehmer_in gibt ihn beim Aktivieren selbst ein.
 */
class ParseInvitationImport
{
    use ProfileValidationRules;

    /**
     * @return Collection<int, array{email: string, name: string|null, status: InvitationImportStatus}>
     */
    public function handle(Team $team, string $contents): Collection
    {
        $rule = new UniqueTeamInvitation($team);
        $seen = [];

        return collect($this->entries($this->normalizeEncoding($contents)))
            ->map(function (array $entry) use ($rule, &$seen): array {
                $status = $this->status($entry['email'], $entry['name'], $rule, $seen);

                $seen[] = $entry['email'];

                return [...$entry, 'status' => $status];
            })
            ->values();
    }

    /**
     * BOM entfernen und die Datei nach UTF-8 bringen.
     *
     * Excel speichert „CSV UTF-8" mit BOM, die gewöhnliche „CSV
     * (Trennzeichen-getrennt)" im deutschsprachigen Windows dagegen in
     * Windows-1252. Ein Umlaut daraus ist kein gültiges UTF-8 und ließe sich
     * nicht in die Vorschau übertragen.
     */
    protected function normalizeEncoding(string $contents): string
    {
        $contents = Str::chopStart($contents, "\u{FEFF}");

        return mb_check_encoding($contents, 'UTF-8')
            ? $contents
            : mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    /**
     * Die Rohzeilen auf normalisierte Adressen und Namen herunterbrechen.
     *
     * Gelesen werden die **ersten beiden Spalten**, damit auch eine aus Excel
     * exportierte Datei mit Zusatzspalten funktioniert.
     *
     * @return list<array{email: string, name: string|null}>
     */
    protected function entries(string $contents): array
    {
        $entries = Str::of($contents)
            ->replace("\r\n", "\n")
            ->explode("\n")
            ->map(fn (string $line): array => str_getcsv($line, ';', '"', ''))
            ->map(fn (array $columns): array => [
                'email' => Str::lower(trim(trim((string) ($columns[0] ?? '')), '"')),
                'name' => $this->cleanName($columns[1] ?? null),
            ])
            ->filter(fn (array $entry): bool => $entry['email'] !== '')
            // values() vorher, damit „erste Zeile" die erste *befüllte* Zeile
            // meint und eine führende Leerzeile die Kopfzeile nicht versteckt.
            ->values()
            ->reject(fn (array $entry, int $index): bool => $index === 0 && $this->isHeader($entry['email']))
            ->all();

        return array_values($entries);
    }

    /**
     * Eine leere Namensspalte heißt: kein Name.
     */
    protected function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
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
     * beide Wege. Der Name wird zuletzt geprüft: Zeilen, die ohnehin nicht
     * eingeladen würden, behalten ihre bisherige Meldung.
     *
     * @param  array<int, string>  $seen
     */
    protected function status(string $email, ?string $name, UniqueTeamInvitation $rule, array $seen): InvitationImportStatus
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
            '' => $this->isValidName($name) ? InvitationImportStatus::Valid : InvitationImportStatus::InvalidName,
            __('This user is already a member of the team.') => InvitationImportStatus::AlreadyMember,
            default => InvitationImportStatus::AlreadyInvited,
        };
    }

    protected function isValidName(?string $name): bool
    {
        return Validator::make(['name' => $name], ['name' => $this->optionalNameRules()])->passes();
    }
}
