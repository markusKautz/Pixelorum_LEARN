<?php

use App\Models\User;

test('users are no admins by default', function () {
    $user = User::factory()->create();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('admin factory state creates an admin', function () {
    $user = User::factory()->admin()->create();

    expect($user->is_admin)->toBeTrue();
});

test('is_admin is cast to boolean', function () {
    $user = User::factory()->admin()->create();

    expect($user->fresh()->is_admin)->toBeBool()->toBeTrue();
});

test('is_admin cannot be mass assigned', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});
