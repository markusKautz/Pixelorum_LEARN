<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateAdmin;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('lapp:make-admin
    {email? : E-Mail-Adresse des Users}
    {--name= : Name für einen neu anzulegenden Account}
    {--password= : Passwort für einen neu anzulegenden Account}')]
#[Description('Befördert einen bestehenden User zum globalen Admin oder legt einen neuen Admin-Account an')]
class MakeAdmin extends Command
{
    use ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(CreateAdmin $createAdmin): int
    {
        $email = $this->argument('email');
        $email = is_string($email) ? $email : null;

        if ($email === null) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Im Non-Interactive-Modus muss die E-Mail-Adresse als Argument übergeben werden.');

                return self::FAILURE;
            }

            $email = text(label: 'E-Mail-Adresse', required: true);
        }

        $email = Str::lower($email);

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $this->promoteExistingUser($user);
        }

        return $this->createAdminUser($email, $createAdmin);
    }

    /**
     * Promote an existing user to global admin (idempotent).
     */
    protected function promoteExistingUser(User $user): int
    {
        if ($user->is_admin) {
            $this->components->info("{$user->email} ist bereits globaler Admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->components->info("{$user->email} wurde zum globalen Admin befördert.");

        return self::SUCCESS;
    }

    /**
     * Create a new, verified admin account with a personal team.
     */
    protected function createAdminUser(string $email, CreateAdmin $createAdmin): int
    {
        $name = $this->option('name');
        $name = is_string($name) ? $name : null;
        $password = $this->option('password');
        $password = is_string($password) ? $password : null;

        if (($name === null || $password === null) && ! $this->input->isInteractive()) {
            $this->components->error('Für einen neuen Account müssen --name und --password übergeben werden.');

            return self::FAILURE;
        }

        $name ??= text(label: 'Name', required: true);
        $password ??= password(label: 'Passwort', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                ...$this->profileRules(),
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = $createAdmin->handle($name, $email, $password);

        $this->components->info("Admin-Account {$user->email} wurde angelegt.");

        return self::SUCCESS;
    }
}
