<?php

namespace App\Actions\Users;

use App\Exceptions\LastAdminException;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Ein Konto samt aller personenbezogenen Daten endgültig löschen (S5.7.4).
 *
 * Die **einzige** Stelle, die weiß, was zu einem Nutzer gehört — die
 * Gruppenlöschung (S5.7.5) ruft sie für die Mitglieder auf, statt die Liste
 * nachzubauen.
 *
 * Den größten Teil erledigen die Fremdschlüssel: `team_members`,
 * `lesson_completions`, `quiz_attempts` (und über `quiz_attempt_id` die
 * `question_responses`), `path_progress` und `passkeys` stehen auf
 * `cascadeOnDelete`. `quizzes.created_by` und `learning_paths.verified_by`
 * stehen auf `nullOnDelete` — erstellte Inhalte bleiben also erhalten und
 * verlieren nur ihren Urheber-Verweis.
 *
 * Vier Dinge kaskadieren **nicht**, weil kein Fremdschlüssel auf sie zeigt.
 * Genau sie wären die Reste, die ein Löschbegehren unerfüllt lassen:
 */
class DeleteUser
{
    /**
     * Delete the given account and everything personal attached to it.
     *
     * Alles in einer Transaktion: bricht ein Schritt ab, bleibt kein
     * halb gelöschter Nutzer zurück, dessen Fortschritt weg und dessen Konto
     * noch da ist.
     *
     * @throws LastAdminException
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            // 0. Nie den letzten Admin (#10). Die Admin-Zeilen bleiben bis zum
            //    Ende der Transaktion gesperrt: löschen sich zwei Admins
            //    gleichzeitig gegenseitig, sieht die zweite Löschung das
            //    Ergebnis der ersten, statt beide auf veraltetem Stand zu prüfen.
            if ($user->is_admin) {
                $adminIds = User::query()->where('is_admin', true)->lockForUpdate()->pluck('id');

                if ($adminIds->reject(fn (int $id) => $id === $user->id)->isEmpty()) {
                    throw new LastAdminException;
                }
            }

            // 1. Das persönliche Team. Es heißt „<Name>'s Team", trägt also den
            //    Klarnamen, und `teams` hat keinen Fremdschlüssel auf den
            //    Nutzer. **Vor** dem Löschen des Kontos einsammeln: die
            //    Mitgliedschaft, über die man es findet, kaskadiert mit weg.
            //    `forceDelete()`, weil `Team` Soft Deletes nutzt und ein
            //    Zeitstempel hier keine Löschung wäre.
            $personalTeams = $user->teams()->where('teams.is_personal', true)->get();

            // 2. Offene Einladungen an seine Adresse — auch in fremden Gruppen.
            //    `team_invitations.email` ist ein Textfeld, keine Beziehung.
            TeamInvitation::query()->where('email', $user->email)->delete();

            // 3. Sitzungen. `sessions.user_id` ist nur ein Index ohne Constraint,
            //    daneben stehen IP-Adresse und User-Agent.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // 4. Offene Passwort-Reset-Token. `password_reset_tokens` ist über
            //    die E-Mail verschlüsselt, nicht über `user_id`, und hat keinen
            //    Fremdschlüssel — die Zeile überlebt das Konto sonst mitsamt
            //    Adresse und Token-Hash. Schlimmer noch: `DatabaseTokenRepository`
            //    findet sie allein über die E-Mail, ein alter Link könnte also
            //    das Passwort eines später unter derselben Adresse neu
            //    angelegten Kontos setzen. Über den Broker statt per DB-Zugriff:
            //    er kennt die konfigurierte Tabelle und Verbindung.
            Password::deleteToken($user);

            $user->delete();

            $personalTeams->each(function (Team $team): void {
                $team->memberships()->delete();
                $team->forceDelete();
            });
        });
    }
}
