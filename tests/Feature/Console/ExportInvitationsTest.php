<?php

use App\Actions\Teams\AppendInvitationToCsv;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('without an argument every group is exported', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    Team::factory()->personal()->create();

    TeamInvitation::factory()->create(['team_id' => $first->id]);
    TeamInvitation::factory()->create(['team_id' => $second->id]);

    $this->artisan('lapp:invitations:export')->assertSuccessful();

    expect(Storage::disk('local')->files('invitations'))->toHaveCount(2);
});

test('a slug limits the export to that one group', function () {
    $team = Team::factory()->create();
    Team::factory()->create();

    TeamInvitation::factory()->create(['team_id' => $team->id]);

    $this->artisan('lapp:invitations:export', ['team' => $team->slug])->assertSuccessful();

    expect(Storage::disk('local')->files('invitations'))->toHaveCount(1);
});

test('an unknown slug fails instead of writing nothing quietly', function () {
    $this->artisan('lapp:invitations:export', ['team' => 'gibt-es-nicht'])->assertFailed();
});

test('the status column tells the three states apart', function () {
    $team = Team::factory()->create();

    TeamInvitation::factory()->create(['team_id' => $team->id, 'expires_at' => now()->addDay()]);
    TeamInvitation::factory()->accepted()->create(['team_id' => $team->id]);
    TeamInvitation::factory()->expired()->create(['team_id' => $team->id]);

    $this->artisan('lapp:invitations:export')->assertSuccessful();

    $rows = readCsvRows(app(AppendInvitationToCsv::class)->pathFor($team));

    expect($rows[0])->toHaveCount(8)
        ->and(collect($rows)->skip(1)->pluck(7)->sort()->values()->all())
        ->toBe(collect([__('Open'), __('Accepted'), __('Expired')])->sort()->values()->all());
});

test('a group without invitations still gets a file with just the header', function () {
    $team = Team::factory()->create();

    $this->artisan('lapp:invitations:export')->assertSuccessful();

    $rows = readCsvRows(app(AppendInvitationToCsv::class)->pathFor($team));

    expect($rows)->toHaveCount(1);
});

test('the export overwrites instead of appending', function () {
    $team = Team::factory()->create();
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id]);

    app(AppendInvitationToCsv::class)->handle($invitation);

    $this->artisan('lapp:invitations:export')->assertSuccessful();

    expect(readCsvRows(app(AppendInvitationToCsv::class)->pathFor($team)))->toHaveCount(2);
});

/**
 * @return array<int, array<int, string>>
 */
function readCsvRows(string $path): array
{
    $handle = fopen(Storage::disk('local')->path($path), 'r');
    $rows = [];

    while (($row = fgetcsv($handle, escape: '', separator: ';')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    $rows[0][0] = str_replace("\u{FEFF}", '', $rows[0][0]);

    return $rows;
}
