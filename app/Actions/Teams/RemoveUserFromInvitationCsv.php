<?php

namespace App\Actions\Teams;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Entfernt ein gelöschtes Konto aus den Einladungsdateien (#23).
 *
 * Durchsucht **alle** Gruppendateien, weil eine Adresse auch in fremden Gruppen
 * eingeladen sein kann. Zeilen an die Adresse des Kontos verschwinden. Steht das
 * Konto nur als einladende Person in einer Zeile, gehört diese einer anderen
 * Person und bleibt; geleert wird allein die Zelle. Die Datei kennt dort nur den
 * Namen, ein Namensvetter verlöre schlimmstenfalls diese eine Angabe.
 */
class RemoveUserFromInvitationCsv
{
    public function __construct(protected AppendInvitationToCsv $csv) {}

    public function handle(User $user): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(AppendInvitationToCsv::DIRECTORY) as $file) {
            $this->clean($disk->path($file), $user);
        }
    }

    /**
     * Eine Datei unter Sperre bereinigen; ohne Treffer bleibt sie unangetastet.
     */
    protected function clean(string $absolutePath, User $user): void
    {
        $handle = fopen($absolutePath, 'r+');

        if ($handle === false) {
            throw new RuntimeException("Die Einladungsdatei {$absolutePath} lässt sich nicht bereinigen.");
        }

        try {
            flock($handle, LOCK_EX);

            $hasBom = fread($handle, strlen(AppendInvitationToCsv::BOM)) === AppendInvitationToCsv::BOM;

            if (! $hasBom) {
                rewind($handle);
            }

            $rows = $this->readRows($handle);
            $cleaned = $this->withoutUser($rows, $user);

            if ($cleaned === $rows) {
                return;
            }

            rewind($handle);
            ftruncate($handle, 0);

            if ($hasBom) {
                fwrite($handle, AppendInvitationToCsv::BOM);
            }

            foreach ($cleaned as $row) {
                $this->csv->writeRow($handle, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<array<int, string|null>>
     */
    protected function readRows($handle): array
    {
        $rows = [];

        while (($row = fgetcsv($handle, null, AppendInvitationToCsv::SEPARATOR, '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Die Kopfzeile bleibt immer stehen.
     *
     * @param  list<array<int, string|null>>  $rows
     * @return list<array<int, string|null>>
     */
    protected function withoutUser(array $rows, User $user): array
    {
        $header = array_slice($rows, 0, 1);
        $cleaned = [];

        foreach (array_slice($rows, 1) as $row) {
            if (($row[BuildInvitationCsv::EMAIL_COLUMN] ?? null) === $user->email) {
                continue;
            }

            if (($row[BuildInvitationCsv::INVITED_BY_COLUMN] ?? null) === $user->name) {
                $row[BuildInvitationCsv::INVITED_BY_COLUMN] = '';
            }

            $cleaned[] = $row;
        }

        return [...$header, ...$cleaned];
    }
}
