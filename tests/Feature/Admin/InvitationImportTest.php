<?php

use App\Actions\Teams\AppendInvitationToCsv;
use App\Actions\Teams\ParseInvitationImport;
use App\Enums\InvitationImportStatus;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
});

function parseImport(Team $team, string $contents): Collection
{
    return app(ParseInvitationImport::class)->handle($team, $contents);
}

test('one address per line is recognised', function () {
    $rows = parseImport($this->group, "anna@example.at\nben@example.at\n");

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('email')->all())->toBe(['anna@example.at', 'ben@example.at'])
        ->and($rows->pluck('status')->unique()->all())->toBe([InvitationImportStatus::Valid]);
});

test('a header line is skipped', function () {
    expect(parseImport($this->group, "E-Mail\nanna@example.at"))->toHaveCount(1);
    expect(parseImport($this->group, "email;name\nanna@example.at;Anna"))->toHaveCount(1);
});

test('blank lines, stray spaces and capitals are normalised away', function () {
    $rows = parseImport($this->group, " Anna@Example.AT \n\n\n");

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['email'])->toBe('anna@example.at');
});

test('the second column is the name and further columns are ignored', function () {
    $rows = parseImport($this->group, 'ben@example.at; Ben Mayr ;Lehrjahr 1');

    expect($rows->first()['email'])->toBe('ben@example.at')
        ->and($rows->first()['name'])->toBe('Ben Mayr');
});

test('an empty or missing second column means no name', function () {
    $rows = parseImport($this->group, "anna@example.at;\nben@example.at");

    expect($rows->pluck('name')->all())->toBe([null, null])
        ->and($rows->pluck('status')->unique()->all())->toBe([InvitationImportStatus::Valid]);
});

test('an overlong name is marked as invalid', function () {
    $rows = parseImport($this->group, 'anna@example.at;'.str_repeat('a', 256));

    expect($rows->first()['status'])->toBe(InvitationImportStatus::InvalidName);
});

test('a malformed address keeps its finding even with an overlong name', function () {
    $rows = parseImport($this->group, 'david.punkt;'.str_repeat('a', 256));

    expect($rows->first()['status'])->toBe(InvitationImportStatus::InvalidEmail);
});

test('a byte order mark does not hide the header or break the first address', function () {
    expect(parseImport($this->group, "\u{FEFF}E-Mail;Name\nanna@example.at;Anna"))->toHaveCount(1);

    expect(parseImport($this->group, "\u{FEFF}anna@example.at;Anna")->first()['status'])
        ->toBe(InvitationImportStatus::Valid);
});

test('an umlaut from a windows-1252 export arrives as utf-8', function () {
    $rows = parseImport($this->group, "anna@example.at;Anna M\xFCller");

    expect($rows->first()['name'])->toBe('Anna Müller');
});

test('the preview shows the name and the import stores it', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', "anna@example.at;Anna Muster\nben@example.at"))
        ->call('previewFile')
        ->assertSee('Anna Muster');

    $component->call('import')->assertHasNoErrors();

    $this->assertDatabaseHas('team_invitations', ['email' => 'anna@example.at', 'name' => 'Anna Muster']);
    $this->assertDatabaseHas('team_invitations', ['email' => 'ben@example.at', 'name' => null]);
});

test('a line with an invalid name is skipped without blocking the valid ones', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', "anna@example.at;Anna\nben@example.at;".str_repeat('a', 256)))
        ->call('previewFile')
        ->call('import')
        ->assertHasNoErrors();

    expect(TeamInvitation::pluck('email')->all())->toBe(['anna@example.at']);
});

test('a duplicate inside the file shows up once and is marked', function () {
    $rows = parseImport($this->group, "anna@example.at\nanna@example.at");

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['status'])->toBe(InvitationImportStatus::Valid)
        ->and($rows[1]['status'])->toBe(InvitationImportStatus::DuplicateInFile);
});

test('a malformed address is marked instead of dropped', function () {
    $rows = parseImport($this->group, 'david.punkt');

    expect($rows->first()['status'])->toBe(InvitationImportStatus::InvalidEmail);
});

test('existing members and pending invitations are recognised', function () {
    $member = User::factory()->apprentice($this->group)->create(['email' => 'clara@example.at']);
    TeamInvitation::factory()->create(['team_id' => $this->group->id, 'email' => 'ben@example.at', 'expires_at' => now()->addDay()]);

    $rows = parseImport($this->group, "clara@example.at\nben@example.at\nneu@example.at");

    expect($rows[0]['status'])->toBe(InvitationImportStatus::AlreadyMember)
        ->and($rows[1]['status'])->toBe(InvitationImportStatus::AlreadyInvited)
        ->and($rows[2]['status'])->toBe(InvitationImportStatus::Valid)
        ->and($member->email)->toBe('clara@example.at');
});

test('the upload alone creates no invitation', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', "anna@example.at\nben@example.at"))
        ->call('previewFile')
        ->assertHasNoErrors()
        ->assertCount('preview', 2);

    expect(TeamInvitation::count())->toBe(0);
});

test('importing invites every valid line and writes it to the csv', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', "E-Mail\nanna@example.at\nben@example.at\nclara@example.at"))
        ->call('previewFile')
        ->call('import')
        ->assertHasNoErrors();

    expect(TeamInvitation::where('team_id', $this->group->id)->count())->toBe(3);

    $file = Storage::disk('local')->get(app(AppendInvitationToCsv::class)->pathFor($this->group));

    expect(substr_count($file, '@example.at'))->toBe(3);
});

test('broken lines are skipped without blocking the valid ones', function () {
    TeamInvitation::factory()->create(['team_id' => $this->group->id, 'email' => 'ben@example.at', 'expires_at' => now()->addDay()]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', "anna@example.at\nben@example.at\ndavid.punkt\nanna@example.at"))
        ->call('previewFile')
        ->call('import')
        ->assertHasNoErrors();

    expect(TeamInvitation::where('team_id', $this->group->id)->pluck('email')->sort()->values()->all())
        ->toBe(['anna@example.at', 'ben@example.at']);
});

test('a file that is not a list is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->create('bild.png', 10, 'image/png'))
        ->call('previewFile')
        ->assertHasErrors('file');
});

test('an oversized file is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->create('liste.csv', 600, 'text/csv'))
        ->call('previewFile')
        ->assertHasErrors('file');
});

test('an apprentice cannot import a list', function () {
    $apprentice = User::factory()->apprentice($this->group)->create();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.groups.import-invitations-modal', ['team' => $this->group])
        ->set('file', UploadedFile::fake()->createWithContent('liste.csv', 'anna@example.at'))
        ->call('previewFile')
        ->assertForbidden();
});
