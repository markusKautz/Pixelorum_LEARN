<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('admins pass team policy checks on foreign teams without membership', function () {
    $admin = User::factory()->admin()->create();
    $foreignTeam = Team::factory()->create();

    expect($admin->can('view', $foreignTeam))->toBeTrue()
        ->and($admin->can('update', $foreignTeam))->toBeTrue()
        ->and($admin->can('addMember', $foreignTeam))->toBeTrue()
        ->and($admin->can('inviteMember', $foreignTeam))->toBeTrue()
        ->and($admin->can('delete', $foreignTeam))->toBeTrue();
});

test('non-admins fail team policy checks on foreign teams', function () {
    $user = User::factory()->create();
    $foreignTeam = Team::factory()->create();

    expect($user->can('view', $foreignTeam))->toBeFalse()
        ->and($user->can('update', $foreignTeam))->toBeFalse()
        ->and($user->can('addMember', $foreignTeam))->toBeFalse()
        ->and($user->can('inviteMember', $foreignTeam))->toBeFalse()
        ->and($user->can('delete', $foreignTeam))->toBeFalse();
});

test('admin gate allows only admins', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    expect($admin->can('admin'))->toBeTrue()
        ->and($user->can('admin'))->toBeFalse();
});

test('owner abilities remain unchanged', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect($owner->can('view', $team))->toBeTrue()
        ->and($owner->can('update', $team))->toBeTrue()
        ->and($owner->can('delete', $team))->toBeTrue();
});

test('member abilities remain unchanged', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($member->can('view', $team))->toBeTrue()
        ->and($member->can('update', $team))->toBeFalse()
        ->and($member->can('delete', $team))->toBeFalse();
});
