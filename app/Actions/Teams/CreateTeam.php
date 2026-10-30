<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new team; only a personal one makes its user a member.
     *
     * **Entscheidung 2 gilt hier genauso wie in `admin.groups`:** eine
     * Gruppe bekommt keine Owner-Mitgliedschaft. Die Autorität des
     * Admins kommt aus `Gate::before`/`is_admin`, nicht aus einer
     * Team-Rolle — er muss der Gruppe also nicht angehören, um sie zu
     * verwalten. Hing er doch drin, zählte ihn jede Auswertung als Teilnehmer_in
     * mit 0 % mit; genau dieser Fall war über das allgemeine „Team erstellen"
     * erreichbar, während der Weg über `admin.groups` es schon immer richtig
     * machte. Zwei Pfade, dieselbe Sache, unterschiedliches Ergebnis — das ist
     * jetzt vereinheitlicht.
     *
     * Das persönliche Team ist der Gegenfall: es *ist* die Mitgliedschaft
     * seines Nutzers und wäre ohne sie sinnlos. Nur dort wird angehängt und
     * umgeschaltet.
     */
    public function handle(User $user, string $name, bool $isPersonal = false): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            if ($isPersonal) {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => TeamRole::Owner,
                ]);

                $user->switchTeam($team);
            }

            return $team;
        });
    }
}
