<?php

use App\Enums\PathStatus;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\User;

it('sortiert Lektionen nach position', function () {
    $path = LearningPath::factory()->create();

    Lesson::factory()->for($path)->create(['title' => 'Zweitens', 'position' => 2]);
    Lesson::factory()->for($path)->create(['title' => 'Erstens', 'position' => 1]);

    expect($path->lessons()->pluck('title')->all())->toBe(['Erstens', 'Zweitens']);
});

it('castet Status, Review-Status und Lernziele', function () {
    $path = LearningPath::factory()->create([
        'objectives' => ['Ziel A', 'Ziel B'],
    ]);

    $fresh = $path->fresh();

    expect($fresh->status)->toBe(PathStatus::Draft)
        ->and($fresh->review_status)->toBe(ReviewStatus::Unreviewed)
        ->and($fresh->objectives)->toBe(['Ziel A', 'Ziel B'])
        ->and($fresh->verified_at)->toBeNull();
});

it('erzeugt aus dem Titel einen Slug', function () {
    $path = LearningPath::factory()->create(['title' => 'Grundlagen der Programmierung']);

    expect($path->slug)->toBe('grundlagen-der-programmierung')
        ->and($path->getRouteKeyName())->toBe('slug');
});

it('erzeugt den Slug auch bei überschriebenem Titel aus diesem Titel', function () {
    // F4: Die Factory setzte den Slug aus dem *erwürfelten* Titel; ein
    // `create(['title' => …])` überschreibt nur den Titel, nicht den daraus
    // abgeleiteten Slug. Die fünf `'slug' => null` in dieser Datei waren die
    // Notlösung dafür.
    expect(LearningPath::factory()->create(['title' => 'Netzwerkgrundlagen'])->slug)
        ->toBe('netzwerkgrundlagen');
});

it('hängt bei gleichem Titel ein Suffix an den Slug', function () {
    LearningPath::factory()->create(['title' => 'Netzwerktechnik']);
    $zweiter = LearningPath::factory()->create(['title' => 'Netzwerktechnik']);

    expect($zweiter->slug)->toBe('netzwerktechnik-1');
});

it('behält den Slug, wenn sich der Titel ändert', function () {
    $path = LearningPath::factory()->create(['title' => 'Alter Titel']);

    $path->update(['title' => 'Neuer Titel']);

    // Der Slug ist Route-Key und Import-Match-Schlüssel: er darf nicht
    // stillschweigend mitwandern (anders als bei Team).
    expect($path->fresh()->slug)->toBe('alter-titel');
});

it('erlaubt, den Slug ausdrücklich zu ändern', function () {
    $path = LearningPath::factory()->create(['title' => 'Alter Titel']);

    $path->update(['slug' => 'wunsch-slug']);

    expect($path->fresh()->slug)->toBe('wunsch-slug');
});

it('markiert einen verifizierten Pfad als sichtbar für Teilnehmer_innen', function () {
    expect(LearningPath::factory()->published()->verified()->create()->isVisibleToLearners())->toBeTrue()
        ->and(LearningPath::factory()->published()->create()->isVisibleToLearners())->toBeFalse()
        ->and(LearningPath::factory()->verified()->create()->isVisibleToLearners())->toBeFalse()
        ->and(LearningPath::factory()->create()->isVisibleToLearners())->toBeFalse();
});

it('behält Lektionen beim Soft-Delete des Pfades', function () {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->create();

    $path->delete();

    expect($path->trashed())->toBeTrue()
        ->and(Lesson::where('learning_path_id', $path->id)->count())->toBe(1);
});

it('löscht Lektionen beim Hard-Delete des Pfades mit', function () {
    $path = LearningPath::factory()->create();
    Lesson::factory()->for($path)->create();

    $path->forceDelete();

    expect(Lesson::where('learning_path_id', $path->id)->count())->toBe(0);
});

it('schützt die Freigabe-Felder vor Mass Assignment', function () {
    $admin = User::factory()->admin()->create();

    $path = LearningPath::factory()->create();
    $path->fill(['verified_by' => $admin->id, 'verified_at' => now()]);

    expect($path->verified_by)->toBeNull()
        ->and($path->verified_at)->toBeNull();
});

it('füllt Pflicht- und Default-Felder ohne explizite Angabe', function () {
    // objectives ist NOT NULL ohne DB-Default — der Model-Default []
    // verhindert einen Crash beim create() ohne objectives.
    $path = LearningPath::create(['title' => 'Minimaler Pfad', 'slug' => 'minimaler-pfad']);

    $fresh = $path->fresh();

    expect($fresh->objectives)->toBe([])
        ->and($fresh->status)->toBe(PathStatus::Draft)
        ->and($fresh->review_status)->toBe(ReviewStatus::Unreviewed)
        ->and($fresh->pass_threshold)->toBe(80)
        ->and($fresh->position)->toBe(0);
});

it('liefert Default-Enum-Werte auf ungespeicherten Instanzen', function () {
    $path = new LearningPath(['title' => 'Ungespeichert']);

    expect($path->status)->toBe(PathStatus::Draft)
        ->and($path->review_status)->toBe(ReviewStatus::Unreviewed)
        ->and($path->objectives)->toBe([]);
});
