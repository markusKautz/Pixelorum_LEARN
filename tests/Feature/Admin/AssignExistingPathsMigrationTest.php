<?php

use App\Enums\PathStatus;
use App\Models\LearningPath;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Die Datenmigration aus E13 erneut ausführen: `RefreshDatabase` hat sie schon
 * auf der leeren Datenbank laufen lassen, der Test braucht sie mit Daten.
 */
function runAssignExistingPathsMigration(): void
{
    $files = glob(database_path('migrations/*_assign_existing_learning_paths_to_all_groups.php'));

    expect($files)->toHaveCount(1);

    $migration = require $files[0];
    $migration->up();
}

test('paths created before the cutoff are assigned to every existing group', function () {
    $running = Team::factory()->create();
    $archived = Team::factory()->archived()->create();
    $personalOwner = User::factory()->create();

    $old = LearningPath::factory()->create(['created_at' => Carbon::parse('2026-10-29 23:59:59')]);
    $trashed = LearningPath::factory()->create(['created_at' => Carbon::parse('2026-09-01')]);
    $trashed->delete();
    $new = LearningPath::factory()->create(['created_at' => Carbon::parse('2026-10-30 00:00:00')]);
    $old->teams()->attach($running);

    runAssignExistingPathsMigration();

    $groupIds = [$running->id, $archived->id];

    expect($old->teams()->pluck('teams.id')->sort()->values()->all())->toBe($groupIds)
        ->and(LearningPath::withTrashed()->find($trashed->id)->teams()->pluck('teams.id')->sort()->values()->all())->toBe($groupIds)
        ->and($new->teams()->exists())->toBeFalse()
        ->and($old->teams()->whereKey($personalOwner->personalTeam()->id)->exists())->toBeFalse();
});

test('the visibility labels read Sichtbar and Nicht sichtbar', function () {
    expect(PathStatus::Published->label())->toBe('Sichtbar')
        ->and(PathStatus::Draft->label())->toBe('Nicht sichtbar')
        ->and(__('Publish'))->toBe('Sichtbar schalten')
        ->and(__('Unpublish'))->toBe('Unsichtbar schalten');
});
