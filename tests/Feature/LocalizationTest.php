<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;

test('login page is german and carries the wordmark in the title', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Melde dich mit deinem Account an')
        ->assertSee('Anmelden - LEARN');
});

test('landing page is german and shows the wordmark', function () {
    // Die Wortmarke ist eine Grafik, der Name steht deshalb im Alternativtext.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('images/logo-learn.svg')
        ->assertSee('alt="LEARN"', escape: false)
        ->assertSee('Schritt für Schritt zur Lehrabschlussprüfung.');
});

test('landing page tells guests that it is running in test mode', function () {
    // Der Testbetrieb ist eine Zusage an die Lehrlinge: fehlende Inhalte sind
    // gewollt und kein Fehler. Er darf ohne Anmeldung sichtbar bleiben.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Testbetrieb')
        ->assertSee('Die Plattform läuft aktuell im Testbetrieb. Lernpfade und Fragen kommen laufend dazu, einzelne Funktionen können sich noch ändern.');
});

test('callout variants have distinct german labels', function () {
    // "Warning" und "Danger" waren beide mit "Achtung" übersetzt — die gelbe und
    // die rote Option der Varianten-Auswahl hießen gleich (Browser-Test S3, F4).
    $labels = array_map(__(...), ['Info', 'Warning', 'Danger', 'Highlight', 'Exam-relevant']);

    expect($labels)->toBe(array_unique($labels))
        ->and($labels)->each->not->toBe('');
});

test('validation errors are german', function () {
    $response = $this->from(route('login'))->post(route('login.store'), []);

    $response->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))
        ->toBe('E-Mail-Adresse muss ausgefüllt werden.');
});

test('dashboard is german', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Einstellungen')
        ->assertSee('Abmelden');
});

test('profile settings are german', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Aktualisiere deinen Namen und deine E-Mail-Adresse');
});

test('invitation mail subject and action are german', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Testgruppe']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail($invitation);

    expect($mail->subject)->toBe('Du wurdest in die Gruppe „Testgruppe“ eingeladen')
        ->and($mail->actionText)->toBe('Einladung annehmen');
});

test('teams index page says Gruppe instead of Team', function () {
    $user = User::factory()->admin()->create();
    $user->currentTeam->update(['name' => 'Lehrlinge 2026']);

    $this->actingAs($user)
        ->get(route('teams.index'))
        ->assertOk()
        ->assertSeeText('Gruppe')
        ->assertDontSeeText('Team');
});

test('team edit page says Gruppe instead of Team', function () {
    $user = User::factory()->admin()->create();
    $team = $user->currentTeam;
    $team->update(['name' => 'Lehrlinge 2026']);

    $this->actingAs($user)
        ->get(route('teams.edit', $team))
        ->assertOk()
        ->assertSeeText('Gruppe')
        ->assertDontSeeText('Team');
});

test('personal teams are named Gruppe von at runtime', function () {
    $this->artisan('lapp:make-admin', [
        'email' => 'admin@example.com',
        '--name' => 'Admina Adminson',
        '--password' => 'sicheres-passwort',
    ])->assertSuccessful();

    $admin = User::where('email', 'admin@example.com')->firstOrFail();

    expect($admin->personalTeam()->name)->toBe('Gruppe von Admina Adminson');
});
