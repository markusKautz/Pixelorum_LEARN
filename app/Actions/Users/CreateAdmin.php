<?php

namespace App\Actions\Users;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAdmin
{
    public function __construct(
        protected CreateUser $createUser,
        protected CreateTeam $createTeam,
    ) {}

    /**
     * Create a new, verified admin account with a personal team (#13).
     *
     * Der eine Weg zu einem neuen Admin-Konto: das Formular der Nutzerliste
     * und `lapp:make-admin` rufen beide hierher. Validieren müssen die
     * Aufrufer selbst, die Aktion legt nur an.
     */
    public function handle(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $user = $this->createUser->handle($name, $email, $password, verified: true);

            $user->forceFill(['is_admin' => true])->save();

            $this->createTeam->handle($user, __(":name's Team", ['name' => $user->name]), isPersonal: true);

            return $user;
        });
    }
}
