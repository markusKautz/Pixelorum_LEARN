<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mehrere Teilnehmer_innen auf einmal endgültig löschen (#6).
 *
 * Nur Teilnehmer_innen (E5): Admins werden einzeln gelöscht, damit der Wächter für
 * den letzten Admin aus der Mehrfachlöschung herausbleibt. Admins und der
 * Handelnde in der Auswahl werden verworfen, nicht gelöscht.
 */
class DeleteUsers
{
    public function __construct(protected DeleteUser $deleteUser) {}

    /**
     * Die Konten, die eine Mehrfachlöschung tatsächlich träfe.
     *
     * Dieselbe Menge für Dialog und Löschung: was der Dialog zählt, wird
     * gelöscht, nicht mehr und nicht weniger.
     *
     * @param  array<int, int|string>  $userIds
     * @return Collection<int, User>
     */
    public function deletable(array $userIds, User $actor): Collection
    {
        return User::query()
            ->whereKey($userIds)
            ->whereKeyNot($actor->id)
            ->where('is_admin', false)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Delete the given apprentices and everything personal attached to them.
     *
     * Eine gemeinsame Transaktion: scheitert ein Konto, bleiben alle bestehen.
     *
     * @param  array<int, int|string>  $userIds
     */
    public function handle(array $userIds, User $actor): int
    {
        return DB::transaction(function () use ($userIds, $actor): int {
            $users = $this->deletable($userIds, $actor);

            $users->each(fn (User $user) => $this->deleteUser->handle($user));

            return $users->count();
        });
    }
}
