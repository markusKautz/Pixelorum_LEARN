<?php

use App\Enums\PathStatus;
use App\Models\LearningPath;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('creating a path redirects to the edit page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->set('title', 'Grundlagen der Programmierung')
        ->call('createPath')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.learning-paths.edit', ['learningPath' => 'grundlagen-der-programmierung']));

    $path = LearningPath::firstWhere('slug', 'grundlagen-der-programmierung');

    expect($path)->not->toBeNull()
        ->and($path->title)->toBe('Grundlagen der Programmierung')
        ->and($path->status)->toBe(PathStatus::Draft);
});

test('new paths are appended at the end of the order', function () {
    LearningPath::factory()->create(['position' => 7]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->set('title', 'Neuer Pfad')
        ->call('createPath');

    expect(LearningPath::firstWhere('slug', 'neuer-pfad')->position)->toBe(8);
});

test('editing persists all fields including the objectives roundtrip', function () {
    $path = LearningPath::factory()->create(['difficulty' => 'einsteiger']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->set('title', 'Schleifen und Bedingungen')
        ->set('slug', 'schleifen-und-bedingungen')
        ->set('summary', 'Alles über Kontrollstrukturen.')
        ->set('objectives', ['for-Schleifen erklären', '', '  while-Schleifen anwenden  '])
        ->set('difficulty', 'fortgeschritten')
        ->set('estimated_minutes', 45)
        ->set('pass_threshold', 70)
        ->call('save')
        ->assertHasNoErrors();

    $path->refresh();

    expect($path->title)->toBe('Schleifen und Bedingungen')
        ->and($path->slug)->toBe('schleifen-und-bedingungen')
        ->and($path->summary)->toBe('Alles über Kontrollstrukturen.')
        ->and($path->objectives)->toBe(['for-Schleifen erklären', 'while-Schleifen anwenden'])
        ->and($path->difficulty)->toBe('fortgeschritten')
        ->and($path->estimated_minutes)->toBe(45)
        ->and($path->pass_threshold)->toBe(70);
});

test('the save button in the header submits the path form', function () {
    $path = LearningPath::factory()->create();

    $html = Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->html();

    // Lucide-Diskette aus resources/views/flux/icon/save.blade.php
    expect($html)->toContain('<form id="path-form" wire:submit="save"')
        ->and($html)->toContain('M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7')
        ->and($html)->toMatch('/<button[^>]*type="submit"[^>]*form="path-form"[^>]*data-test="path-save-button"|<button[^>]*form="path-form"[^>]*type="submit"[^>]*data-test="path-save-button"/');
});

test('the slug must be unique across paths including trashed ones', function () {
    $trashed = LearningPath::factory()->create(['slug' => 'belegter-slug']);
    $trashed->delete();

    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->set('slug', 'belegter-slug')
        ->call('save')
        ->assertHasErrors(['slug' => 'unique']);
});

test('creating a path with an empty title shows the german server error', function () {
    // F6: ohne native required-Blase ist die deutsche Server-Meldung sichtbar.
    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->set('title', '')
        ->call('createPath')
        ->assertHasErrors(['title' => 'required'])
        ->assertSee('muss ausgefüllt werden');
});

test('objectives can be reordered by drag and drop', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->set('objectives', ['Ziel A', 'Ziel B', 'Ziel C'])
        // wire:sort meldet (from, to) — das dritte Ziel an die Spitze ziehen.
        ->call('sortObjectives', 2, 0)
        ->assertHasNoErrors()
        ->call('save');

    expect($path->refresh()->objectives)->toBe(['Ziel C', 'Ziel A', 'Ziel B']);
});

test('publishing sets the status', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('publish')
        // Flux lauscht auf modal-close; ein dispatch('close-modal') liefe ins
        // Leere und ließe das Modal offen stehen (Browser-Test S3, F2).
        ->assertDispatched('modal-close', name: 'publish-path');

    expect($path->refresh()->status)->toBe(PathStatus::Published);
});

test('unpublishing resets the status to draft', function () {
    $path = LearningPath::factory()->published()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('unpublish');

    expect($path->refresh()->status)->toBe(PathStatus::Draft);
});

test('publishing straight from the list sets the status', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('publishPath', $path->id)
        // Der Dialog gehört zur Zeile, sein Name trägt deshalb die id.
        ->assertDispatched('modal-close', name: "publish-path-{$path->id}");

    expect($path->refresh()->status)->toBe(PathStatus::Published);
});

test('unpublishing straight from the list resets the status to draft', function () {
    $path = LearningPath::factory()->published()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('unpublishPath', $path->id)
        ->assertDispatched('modal-close', name: "unpublish-path-{$path->id}");

    expect($path->refresh()->status)->toBe(PathStatus::Draft);
});

test('publishing from the list does not count as a content change', function () {
    // Dieselbe Falle wie auf der Bearbeiten-Seite (siehe PathReviewTest): ein
    // bewegtes updated_at ließe die Freigabe als Inhaltsänderung durchgehen.
    $path = LearningPath::factory()->create();
    $before = $path->updated_at;

    $this->travel(5)->minutes();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('publishPath', $path->id);

    expect($path->refresh()->updated_at->eq($before))->toBeTrue();
});

test('the list offers the publish toggle that matches the status', function () {
    LearningPath::factory()->create(['title' => 'Entwurf']);
    LearningPath::factory()->published()->create(['title' => 'Veröffentlicht']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->assertSeeHtml('data-test="path-publish-button"')
        ->assertSeeHtml('data-test="path-unpublish-button"');
});

test('the edit page links back to the list', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->assertSeeHtml('data-test="back-to-paths-button"')
        ->assertSeeHtml(route('admin.learning-paths.index'));
});

test('sorting writes compact one-based positions', function () {
    $first = LearningPath::factory()->create(['position' => 3]);
    $second = LearningPath::factory()->create(['position' => 7]);
    $third = LearningPath::factory()->create(['position' => 12]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('sortPaths', $third->id, 0);

    expect($third->refresh()->position)->toBe(1)
        ->and($first->refresh()->position)->toBe(2)
        ->and($second->refresh()->position)->toBe(3);
});

test('deleting a path soft-deletes it', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.index')
        ->call('deletePath', $path->id);

    $this->assertSoftDeleted($path);
});

test('deleting from the edit page redirects to the list', function () {
    $path = LearningPath::factory()->create();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path])
        ->call('deletePath')
        ->assertRedirect(route('admin.learning-paths.index'));

    $this->assertSoftDeleted($path);
});

test('apprentices are forbidden on the pages', function () {
    $apprentice = User::factory()->apprentice()->create();
    $path = LearningPath::factory()->create();

    $this->actingAs($apprentice)
        ->get(route('admin.learning-paths.edit', $path))
        ->assertForbidden();
});

test('apprentices are forbidden on every livewire action', function () {
    $apprentice = User::factory()->apprentice()->create();
    $path = LearningPath::factory()->create();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->set('title', 'Versuch')
        ->call('createPath')
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->call('sortPaths', $path->id, 0)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->call('publishPath', $path->id)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->call('unpublishPath', $path->id)
        ->assertForbidden();

    Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.index')
        ->call('deletePath', $path->id)
        ->assertForbidden();

    $edit = fn () => Livewire::actingAs($apprentice)
        ->test('pages::admin.learning-paths.edit', ['learningPath' => $path]);

    $edit()->call('save')->assertForbidden();
    $edit()->call('publish')->assertForbidden();
    $edit()->call('unpublish')->assertForbidden();
    $edit()->call('deletePath')->assertForbidden();

    expect($path->refresh()->deleted_at)->toBeNull();
});
