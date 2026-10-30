<?php

use App\Enums\TeamRole;
use App\Models\LearningPath;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->lehrling = User::factory()->apprentice()->create();
});

it('lässt Admins alles mit Lernpfaden tun', function (string $ability) {
    $path = LearningPath::factory()->create();

    expect($this->admin->can($ability, $path))->toBeTrue();
})->with(['view', 'update', 'delete', 'publish', 'verify']);

it('lässt Admins Lernpfade anlegen und auflisten', function () {
    expect($this->admin->can('viewAny', LearningPath::class))->toBeTrue()
        ->and($this->admin->can('create', LearningPath::class))->toBeTrue();
});

it('zeigt Teilnehmer_innen nur sichtbare und geprüfte Pfade ihrer Gruppe', function (bool $published, bool $verified, bool $sichtbar) {
    $factory = LearningPath::factory()->assignedToAllGroups();
    $factory = $published ? $factory->published() : $factory;
    $factory = $verified ? $factory->verified() : $factory;

    expect($this->lehrling->can('view', $factory->create()))->toBe($sichtbar);
})->with([
    'entwurf, ungeprüft' => [false, false, false],
    'veröffentlicht, ungeprüft' => [true, false, false],
    'entwurf, geprüft' => [false, true, false],
    'veröffentlicht und geprüft' => [true, true, true],
]);

it('lässt Teilnehmer_innen die Pfad-Liste sehen, aber nichts verwalten', function () {
    $path = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();

    expect($this->lehrling->can('viewAny', LearningPath::class))->toBeTrue()
        ->and($this->lehrling->can('create', LearningPath::class))->toBeFalse()
        ->and($this->lehrling->can('update', $path))->toBeFalse()
        ->and($this->lehrling->can('delete', $path))->toBeFalse()
        ->and($this->lehrling->can('publish', $path))->toBeFalse()
        ->and($this->lehrling->can('verify', $path))->toBeFalse();
});

it('filtert die Pfad-Liste per Scope auf sichtbare Pfade', function () {
    $sichtbar = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    LearningPath::factory()->published()->create();
    LearningPath::factory()->verified()->create();
    LearningPath::factory()->create();

    expect(LearningPath::visibleToLearners()->pluck('id')->all())->toBe([$sichtbar->id]);
});

/*
 * Ab Sprint 5.6 ist die Ausgabe an eine Gruppe das Sichtbarkeits-Gate freier
 * Quizze. Vorher war jedes veröffentlichte freie Quiz per Direkt-URL für jeden
 * Teilnehmer_in offen — verlinkt war es nirgends, gefunden hat es niemand.
 */
it('verbirgt ein veröffentlichtes freies Quiz, das keiner Gruppe ausgegeben ist', function () {
    $quiz = Quiz::factory()->published()->create();

    expect($this->lehrling->can('view', $quiz))->toBeFalse();
});

it('zeigt Teilnehmer_innen ein freies Quiz, das ihrer Gruppe ausgegeben ist', function () {
    $quiz = Quiz::factory()->published()->create();

    $quiz->teams()->attach($this->lehrling->currentTeam);

    expect($this->lehrling->can('view', $quiz))->toBeTrue();
});

it('verbirgt ein freies Quiz, das nur einer fremden Gruppe ausgegeben ist', function () {
    $quiz = Quiz::factory()->published()->create();
    $fremdeGruppe = Team::factory()->create();

    $quiz->teams()->attach($fremdeGruppe);

    expect($this->lehrling->can('view', $quiz))->toBeFalse();
});

it('macht ein ausgegebenes freies Quiz unsichtbar, solange es Entwurf ist', function () {
    $quiz = Quiz::factory()->create();

    $quiz->teams()->attach($this->lehrling->currentTeam);

    // Beide Stufen müssen erfüllt sein: ausgegeben *und* veröffentlicht.
    expect($this->lehrling->can('view', $quiz))->toBeFalse();
});

it('taugt ein persönliches Team nicht als Zugang zu einem freien Quiz', function () {
    $quiz = Quiz::factory()->published()->create();
    $persoenlich = Team::factory()->personal()->create();

    $persoenlich->members()->attach($this->lehrling, ['role' => TeamRole::Owner->value]);
    $quiz->teams()->attach($persoenlich);

    expect($this->lehrling->can('view', $quiz))->toBeFalse();
});

it('verbirgt ein unveröffentlichtes Quiz vor Teilnehmer_innen', function () {
    $quiz = Quiz::factory()->create();

    expect($this->lehrling->can('view', $quiz))->toBeFalse();
});

it('verbirgt das Abschluss-Quiz eines ungeprüften Pfades vor Teilnehmer_innen', function () {
    $entwurfsPfad = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($entwurfsPfad)->published()->create();

    expect($this->lehrling->can('view', $quiz))->toBeFalse();
});

it('verbirgt das Abschluss-Quiz weiterhin, wenn sein Pfad gelöscht wird', function () {
    // Regression: die Relation liefert bei soft-gelöschtem Pfad null — das
    // Quiz darf dadurch nicht als «freies Quiz ohne Gate» durchgehen.
    $pfad = LearningPath::factory()->create();
    $quiz = Quiz::factory()->pathFinal($pfad)->published()->create();

    $pfad->delete();

    expect($this->lehrling->can('view', $quiz->fresh()))->toBeFalse();
});

it('verbirgt auch das Quiz eines gelöschten freigegebenen Pfades', function () {
    $pfad = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($pfad)->published()->create();

    $pfad->delete();

    expect($this->lehrling->can('view', $quiz->fresh()))->toBeFalse();
});

it('zeigt Teilnehmer_innen das Abschluss-Quiz eines freigegebenen Pfades', function () {
    $pfad = LearningPath::factory()->published()->verified()->assignedToAllGroups()->create();
    $quiz = Quiz::factory()->pathFinal($pfad)->published()->create();

    expect($this->lehrling->can('view', $quiz))->toBeTrue();
});

it('lässt Admins Quizze verwalten, Teilnehmer_innen nicht', function () {
    $quiz = Quiz::factory()->create();

    expect($this->admin->can('view', $quiz))->toBeTrue()
        ->and($this->admin->can('create', Quiz::class))->toBeTrue()
        ->and($this->admin->can('update', $quiz))->toBeTrue()
        ->and($this->admin->can('delete', $quiz))->toBeTrue()
        ->and($this->lehrling->can('create', Quiz::class))->toBeFalse()
        ->and($this->lehrling->can('update', $quiz))->toBeFalse()
        ->and($this->lehrling->can('delete', $quiz))->toBeFalse();
});
