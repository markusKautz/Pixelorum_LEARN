<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Nur lokal: Dev-Zugänge (ein Admin, eine Gruppe, zwei Lehrlinge), ohne die
     * kein Login möglich wäre, und die Beispielinhalte des DemoContentSeeders.
     * Echte Lernpfade kommen über den Admin-Upload. Außerhalb von `local`
     * legt der Seeder nichts an.
     *
     * Bewusst **ohne** WithoutModelEvents: der path_final-Guard auf Quiz und
     * die Slug-Hooks auf LearningPath/Team hängen an Model-Events. Das Trait
     * würde sie in allen aufgerufenen Seedern stumm schalten und stille
     * Invarianten-Brüche zulassen.
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->seedDevAccounts();
            $this->call(DemoContentSeeder::class);
        }
    }

    /**
     * Create the accounts used to log in during local development.
     *
     * Wiederholbar: Ohne die Existenzprüfungen bricht ein zweiter `db:seed`
     * mit `UNIQUE constraint failed: users.email` ab. Das Idiom
     * `->first() ?? factory()` entspricht dem im DemoContentSeeder und lässt
     * die Model-Events der Factories intakt.
     */
    protected function seedDevAccounts(): void
    {
        $gruppe = Team::where('name', 'Lehrjahr 2026')->first()
            ?? Team::factory()->create(['name' => 'Lehrjahr 2026']);

        User::where('email', 'admin@example.com')->first()
            ?? User::factory()->admin()->create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
            ]);

        foreach ([['Lena Lehrling', 'lena@example.com'], ['Tom Lehrling', 'tom@example.com']] as [$name, $email]) {
            User::where('email', $email)->first()
                ?? User::factory()->apprentice($gruppe)->create([
                    'name' => $name,
                    'email' => $email,
                ]);
        }
    }
}
