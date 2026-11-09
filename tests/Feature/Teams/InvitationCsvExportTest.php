<?php

use App\Actions\Teams\AppendInvitationToCsv;
use App\Actions\Teams\BuildInvitationCsv;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

test('the header row names all eight columns', function () {
    $header = app(BuildInvitationCsv::class)->header();

    expect($header)->toHaveCount(8)
        ->and($header[1])->toBe(__('Email'))
        ->and($header[2])->toBe(__('Name'))
        ->and($header[4])->toBe(__('Activation link'));
});

test('a row carries the preset name and stays empty without one', function () {
    $build = app(BuildInvitationCsv::class);

    $named = TeamInvitation::factory()->create(['name' => 'Anna Muster']);
    $unnamed = TeamInvitation::factory()->create();

    expect($build->row($named)[2])->toBe('Anna Muster')
        ->and($build->row($unnamed)[2])->toBe('');
});

test('the header row gains a status column when regenerated from the database', function () {
    $header = app(BuildInvitationCsv::class)->header(withStatus: true);

    expect($header)->toHaveCount(9)
        ->and($header[8])->toBe(__('Status'));
});

test('a row carries a link that passes signature validation', function () {
    $team = Team::factory()->create(['name' => 'Teilnehmer_innen 2026']);
    $inviter = User::factory()->create(['name' => 'Markus']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $inviter->id,
        'email' => 'anna@example.at',
        'expires_at' => now()->addDays(30),
    ]);

    $row = app(BuildInvitationCsv::class)->row($invitation);

    expect($row[0])->toBe('Teilnehmer_innen 2026')
        ->and($row[1])->toBe('anna@example.at')
        ->and($row[7])->toBe('Markus')
        ->and($row[4])->toStartWith(config('app.url'))
        ->and($row[4])->toContain($invitation->code);

    $request = Request::create($row[4]);

    expect(URL::hasValidSignature($request))->toBeTrue();
});

test('dates use the format the group leader reads on paper', function () {
    $invitation = TeamInvitation::factory()->create([
        'expires_at' => now()->setDate(2026, 9, 17)->setTime(9, 14),
    ]);

    $invitation->forceFill(['created_at' => now()->setDate(2026, 8, 18)->setTime(9, 14)])->save();

    $row = app(BuildInvitationCsv::class)->row($invitation->fresh());

    expect($row[5])->toBe('18.08.2026 09:14')
        ->and($row[6])->toBe('17.09.2026 09:14');
});

test('an invitation without an expiry leaves the column empty instead of failing', function () {
    $invitation = TeamInvitation::factory()->create(['expires_at' => null]);

    expect(app(BuildInvitationCsv::class)->row($invitation)[6])->toBe('');
});

test('the status column distinguishes open, accepted and expired invitations', function () {
    $build = app(BuildInvitationCsv::class);

    $open = TeamInvitation::factory()->create(['expires_at' => now()->addDay()]);
    $accepted = TeamInvitation::factory()->accepted()->create();
    $expired = TeamInvitation::factory()->expired()->create();

    expect($build->row($open, withStatus: true)[8])->toBe(__('Open'))
        ->and($build->row($accepted, withStatus: true)[8])->toBe(__('Accepted'))
        ->and($build->row($expired, withStatus: true)[8])->toBe(__('Expired'));
});

test('the first invitation creates the file with a BOM and a header row', function () {
    Storage::fake('local');

    $team = Team::factory()->create(['name' => 'Teilnehmer_innen 2026']);
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id]);

    $append = app(AppendInvitationToCsv::class);
    $append->handle($invitation);

    $contents = Storage::disk('local')->get($append->pathFor($team));

    expect($contents)->toStartWith("\u{FEFF}")
        ->and($contents)->toContain(__('Activation link'))
        ->and($contents)->toContain($invitation->email);
});

test('a second invitation appends exactly one line and no second header', function () {
    Storage::fake('local');

    $team = Team::factory()->create();
    $append = app(AppendInvitationToCsv::class);

    $append->handle(TeamInvitation::factory()->create(['team_id' => $team->id]));
    $append->handle(TeamInvitation::factory()->create(['team_id' => $team->id]));

    $lines = array_filter(explode("\n", Storage::disk('local')->get($append->pathFor($team))));

    expect($lines)->toHaveCount(3)
        ->and(substr_count(implode("\n", $lines), __('Activation link')))->toBe(1);
});

test('a separator inside the group name does not tear the columns apart', function () {
    Storage::fake('local');

    $team = Team::factory()->create(['name' => 'HTL; Abend "2026"']);
    $append = app(AppendInvitationToCsv::class);

    $append->handle(TeamInvitation::factory()->create(['team_id' => $team->id]));

    $handle = fopen(Storage::disk('local')->path($append->pathFor($team)), 'r');
    fgetcsv($handle, escape: '', separator: ';');
    $row = fgetcsv($handle, escape: '', separator: ';');
    fclose($handle);

    expect($row[0])->toBe('HTL; Abend "2026"')
        ->and($row)->toHaveCount(8);
});

test('renaming the group keeps writing into the same file', function () {
    Storage::fake('local');

    $team = Team::factory()->create(['name' => 'Teilnehmer_innen 2026']);
    $append = app(AppendInvitationToCsv::class);

    $append->handle(TeamInvitation::factory()->create(['team_id' => $team->id]));

    $team->update(['name' => 'Teilnehmer_innen 2027']);
    $append->handle(TeamInvitation::factory()->create(['team_id' => $team->fresh()->id]));

    expect(Storage::disk('local')->files('invitations'))->toHaveCount(1);
});
