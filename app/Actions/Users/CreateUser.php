<?php

namespace App\Actions\Users;

use App\Models\User;

class CreateUser
{
    /**
     * Create a new user, optionally marking the email as already verified.
     *
     * Callers decide about teams and memberships; this action only covers
     * the shared core of every account creation path.
     */
    public function handle(string $name, string $email, string $password, bool $verified = false): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
