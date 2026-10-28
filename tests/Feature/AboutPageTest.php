<?php

use App\Models\Team;
use App\Models\User;

test('guests are sent to the login instead of the about page', function () {
    $this->get(route('about'))->assertRedirect(route('login'));
});

test('an apprentice sees the author and licence on the about page', function () {
    $apprentice = User::factory()->apprentice(Team::factory()->create())->create();

    $this->actingAs($apprentice)
        ->get(route('about'))
        ->assertOk()
        ->assertSee('Über '.config('app.name'))
        ->assertSee('Die Inhalte sind gegen Quellen geprüft und freigegeben')
        ->assertSee('soll beim Lernen unterstützen, nicht überwachen')
        ->assertSee('Entwickelt von Markus Kautz')
        ->assertSee('© 2026 Markus Kautz')
        ->assertSee('Freie Software unter der GNU Affero General Public License, Version 3 oder später.');
});

test('the about page links to the source code of the installation', function () {
    config(['lapp.source_url' => 'https://example.com/learn-fork']);
    $apprentice = User::factory()->apprentice(Team::factory()->create())->create();

    $this->actingAs($apprentice)
        ->get(route('about'))
        ->assertOk()
        ->assertSee('Quelltext')
        ->assertSee('href="https://example.com/learn-fork"', escape: false);
});

test('the user menu links to the about page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-test="about-menu-item"', escape: false)
        ->assertSee(route('about'), escape: false);
});
