<?php

use App\Actions\Teams\AppendInvitationToCsv;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
});

test('the admin downloads the activation links as a csv', function () {
    $invitation = TeamInvitation::factory()->create(['team_id' => $this->group->id]);

    app(AppendInvitationToCsv::class)->handle($invitation);

    $response = $this->actingAs($this->admin)
        ->get(route('admin.groups.invitations', ['team' => $this->group->slug]));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload("{$this->group->slug}-aktivierungslinks.csv");

    expect($response->streamedContent())->toContain($invitation->email);
});

test('a missing file is rebuilt from the database instead of 404', function () {
    TeamInvitation::factory()->create(['team_id' => $this->group->id, 'email' => 'anna@example.at']);

    $response = $this->actingAs($this->admin)
        ->get(route('admin.groups.invitations', ['team' => $this->group->slug]));

    $response->assertOk();

    expect($response->streamedContent())->toContain('anna@example.at');
});

test('an apprentice cannot download the links', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    $this->actingAs($apprentice)
        ->get(route('admin.groups.invitations', ['team' => $this->group->slug]))
        ->assertForbidden();
});

test('a guest is sent to the login', function () {
    $this->get(route('admin.groups.invitations', ['team' => $this->group->slug]))
        ->assertRedirect(route('login'));
});

test('a personal team has no link list', function () {
    $personal = Team::factory()->personal()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.groups.invitations', ['team' => $personal->slug]))
        ->assertNotFound();
});
