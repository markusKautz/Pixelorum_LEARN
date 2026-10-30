<?php

use App\Enums\LessonBlockType;
use App\Enums\PathStatus;
use App\Enums\QuizKind;
use App\Enums\ReviewStatus;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoContentSeeder;

it('legt die Demo-Inhalte an', function () {
    $this->seed(DemoContentSeeder::class);

    $pfad = LearningPath::where('slug', 'grundlagen-der-programmierung')->first();

    expect($pfad)->not->toBeNull()
        ->and($pfad->status)->toBe(PathStatus::Published)
        ->and($pfad->review_status)->toBe(ReviewStatus::Verified)
        ->and($pfad->isVisibleToLearners())->toBeTrue()
        ->and($pfad->verified_by)->not->toBeNull()
        ->and($pfad->lessons)->toHaveCount(3);
});

it('weist den sichtbaren Demo-Pfad den laufenden Gruppen zu', function () {
    $gruppe = Team::factory()->create();
    $teilnehmerin = User::factory()->apprentice($gruppe)->create();

    $this->seed(DemoContentSeeder::class);

    $pfad = LearningPath::where('slug', 'grundlagen-der-programmierung')->firstOrFail();

    expect($pfad->isVisibleTo($teilnehmerin))->toBeTrue();
});

it('gibt jeder Demo-Lektion Blöcke und einen Zwischen-Check', function () {
    $this->seed(DemoContentSeeder::class);

    $pfad = LearningPath::where('slug', 'grundlagen-der-programmierung')->firstOrFail();

    foreach ($pfad->lessons as $lektion) {
        expect($lektion->blocks->count())->toBeGreaterThanOrEqual(3)
            ->and($lektion->source_reference)->not->toBeNull()
            ->and(Question::where('lesson_id', $lektion->id)->count())->toBe(1);
    }
});

it('deckt in den Demo-Inhalten jeden Block-Typ ab', function () {
    $this->seed(DemoContentSeeder::class);

    $pfad = LearningPath::where('slug', 'grundlagen-der-programmierung')->firstOrFail();

    $typen = $pfad->lessons
        ->flatMap(fn (Lesson $lektion) => $lektion->blocks->pluck('type'))
        ->unique();

    expect($typen)->toHaveCount(count(LessonBlockType::cases()));
});

it('legt ein Abschluss-Quiz und ein freies Quiz mit Fragen an', function () {
    $this->seed(DemoContentSeeder::class);

    $pfad = LearningPath::where('slug', 'grundlagen-der-programmierung')->firstOrFail();
    $abschluss = $pfad->quizzes()->where('kind', QuizKind::PathFinal)->first();
    $frei = Quiz::where('kind', QuizKind::Custom)->first();

    expect($abschluss)->not->toBeNull()
        ->and($abschluss->questions)->toHaveCount(8)
        ->and($abschluss->questions->first()->pivot->position)->toBe(1)
        ->and($frei)->not->toBeNull()
        ->and($frei->questions)->toHaveCount(4)
        ->and($frei->learning_path_id)->toBeNull();
});

it('ordnet den Demo-Content einem einzigen Admin zu', function () {
    $this->seed(DemoContentSeeder::class);

    $pfad = LearningPath::where('slug', 'grundlagen-der-programmierung')->firstOrFail();

    expect(User::where('is_admin', true)->count())->toBe(1)
        ->and(Quiz::pluck('created_by')->unique()->all())->toBe([$pfad->verified_by]);
});

it('legt einen für Teilnehmer_innen unsichtbaren Entwurfs-Pfad an', function () {
    $this->seed(DemoContentSeeder::class);

    expect(LearningPath::count())->toBe(2)
        ->and(LearningPath::visibleToLearners()->count())->toBe(1);
});

it('läuft komplett durch und legt Dev-Zugänge und Demo-Inhalte an', function () {
    $this->app['env'] = 'local';

    $this->seed();

    $admin = User::where('email', 'admin@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->is_admin)->toBeTrue()
        ->and(User::where('email', 'lena@example.com')->exists())->toBeTrue()
        ->and(User::where('email', 'tom@example.com')->exists())->toBeTrue()
        ->and(LearningPath::pluck('slug')->sort()->values()->all())
        ->toBe(['entwurf-datenbanken-vertiefen', 'grundlagen-der-programmierung']);
});

it('lässt sich zweimal ausführen, ohne zu brechen oder zu verdoppeln', function () {
    $this->app['env'] = 'local';

    $this->seed();
    $this->seed();

    expect(User::where('email', 'admin@example.com')->count())->toBe(1)
        ->and(User::where('is_admin', true)->count())->toBe(1)
        ->and(Team::where('name', 'Lehrjahr 2026')->count())->toBe(1)
        ->and(LearningPath::count())->toBe(2);
});

it('legt außerhalb der lokalen Umgebung nichts an', function () {
    $this->seed();

    expect(User::count())->toBe(0)
        ->and(LearningPath::count())->toBe(0);
});
