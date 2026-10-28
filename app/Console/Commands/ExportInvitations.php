<?php

namespace App\Console\Commands;

use App\Actions\Teams\AppendInvitationToCsv;
use App\Actions\Teams\BuildInvitationCsv;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('lapp:invitations:export
    {team? : Slug der Gruppe; ohne Angabe werden alle Gruppen exportiert}')]
#[Description('Schreibt die Aktivierungslinks einer Gruppe neu in ihre CSV-Datei')]
class ExportInvitations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BuildInvitationCsv $csv, AppendInvitationToCsv $append): int
    {
        $slug = $this->argument('team');
        $slug = is_string($slug) ? $slug : null;

        $teams = Team::query()
            ->where('is_personal', false)
            ->when($slug !== null, fn ($query) => $query->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($teams->isEmpty()) {
            $this->components->error($slug !== null
                ? "Keine Gruppe mit dem Slug „{$slug}“ gefunden."
                : 'Es gibt noch keine Gruppen.');

            return self::FAILURE;
        }

        $this->components->warn('Vorhandene Dateien werden überschrieben. Zeilen zu bereits gelöschten Einladungen gehen dabei verloren.');

        foreach ($teams as $team) {
            $this->exportTeam($team, $csv, $append);
        }

        return self::SUCCESS;
    }

    /**
     * Eine Gruppendatei vollständig neu schreiben.
     *
     * Im Unterschied zum Anhängen beim Einladen ist das eine Momentaufnahme der
     * Datenbank, deshalb trägt sie eine Statusspalte. Was der tägliche Prune
     * bereits gelöscht hat, kann sie nicht zurückholen.
     */
    protected function exportTeam(Team $team, BuildInvitationCsv $csv, AppendInvitationToCsv $append): void
    {
        $invitations = $team->invitations()->with('inviter')->oldest()->get();

        $path = $append->existingPathFor($team) ?? $append->pathFor($team);
        $disk = Storage::disk('local');

        $disk->put($path, AppendInvitationToCsv::BOM);

        $absolutePath = $disk->path($path);

        $append->write($absolutePath, $csv->header(withStatus: true));

        foreach ($invitations as $invitation) {
            $invitation->setRelation('team', $team);

            $append->write($absolutePath, $csv->row($invitation, withStatus: true));
        }

        $this->components->info("{$team->name}: {$invitations->count()} Einladungen nach {$path} geschrieben.");
    }
}
