<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hängt eine Einladung als Zeile an die Gruppendatei an.
 *
 * Die Datei ist ein **Protokoll, kein Datenbank-Spiegel**: bestehende Zeilen
 * werden nie umgeschrieben. Das ist Absicht, denn der tägliche Prune in
 * `routes/console.php` löscht abgelaufene Einladungen, und der Lehrling kann
 * seine eigene ablehnen. Eine angehängte Zeile überlebt beides und bleibt
 * damit der einzige Beleg, wer wann eingeladen wurde. Den aktuellen Zustand
 * beantwortet die App, nicht die Datei.
 */
class AppendInvitationToCsv
{
    public const DIRECTORY = 'invitations';

    /** Trennzeichen und BOM sind auf das deutschsprachige Excel abgestimmt. */
    public const SEPARATOR = ';';

    public const BOM = "\u{FEFF}";

    public function __construct(protected BuildInvitationCsv $csv) {}

    /**
     * Die Zeile anhängen und die Datei anlegen, falls sie noch fehlt.
     */
    public function handle(TeamInvitation $invitation): string
    {
        $invitation->loadMissing('team');

        $disk = Storage::disk('local');
        $path = $this->existingPathFor($invitation->team) ?? $this->pathFor($invitation->team);

        if (! $disk->exists($path)) {
            $disk->put($path, self::BOM);
            $this->write($disk->path($path), $this->csv->header());
        }

        $this->write($disk->path($path), $this->csv->row($invitation));

        return $path;
    }

    /**
     * Der Dateipfad einer Gruppe.
     *
     * Der Name führt mit der **ID**, weil `renameGroup()` den Slug erneuert:
     * ein Name rein aus dem Slug hätte beim Umbenennen still eine zweite Datei
     * begonnen und die erste verwaisen lassen.
     */
    public function pathFor(Team $team): string
    {
        return self::DIRECTORY."/{$team->id}-{$team->slug}.csv";
    }

    /**
     * Eine bereits vorhandene Datei einer Gruppe finden, auch unter altem Slug.
     */
    public function existingPathFor(Team $team): ?string
    {
        $disk = Storage::disk('local');

        if ($disk->exists($this->pathFor($team))) {
            return $this->pathFor($team);
        }

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (str_starts_with(basename($file), "{$team->id}-")) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Eine Zeile im Excel-Dialekt anhängen. Öffentlich, damit das
     * Neu-Erzeugen in `lapp:invitations:export` denselben Dialekt schreibt und
     * Trennzeichen, Quoting und Zeilenende nicht an zwei Stellen leben.
     *
     * `escape: ''` schaltet die PHP-eigene Backslash-Sonderbehandlung ab, die
     * mit RFC 4180 nichts zu tun hat und in Excel als Fremdkörper ankäme.
     *
     * @param  array<int, string>  $row
     */
    public function write(string $absolutePath, array $row): void
    {
        $handle = fopen($absolutePath, 'a');

        if ($handle === false) {
            throw new RuntimeException("Die Einladungsdatei {$absolutePath} lässt sich nicht schreiben.");
        }

        fputcsv($handle, $row, separator: self::SEPARATOR, escape: '', eol: "\r\n");

        fclose($handle);
    }
}
