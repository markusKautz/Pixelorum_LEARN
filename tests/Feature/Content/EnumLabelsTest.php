<?php

use App\Enums\LessonBlockType;
use App\Enums\PathStatus;
use App\Enums\ProgressStatus;
use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Enums\ReviewStatus;

/**
 * Ein fehlender Key in lang/de/enums.php fällt sonst stumm durch: __() gibt
 * bei namespaced Keys den rohen Key zurück — es gibt keinen sichtbaren
 * englischen Fallback wie bei den flachen Keys in de.json.
 */
dataset('content enum cases', function () {
    foreach ([
        QuestionType::class,
        QuizKind::class,
        PathStatus::class,
        ReviewStatus::class,
        LessonBlockType::class,
        ProgressStatus::class,
    ] as $enum) {
        foreach ($enum::cases() as $case) {
            yield class_basename($enum).'::'.$case->name => [$case];
        }
    }
});

it('übersetzt jeden Enum-Case ins Deutsche', function (QuestionType|QuizKind|PathStatus|ReviewStatus|LessonBlockType|ProgressStatus $case) {
    expect($case->label())
        ->not->toBe('')
        ->not->toStartWith('enums.');
})->with('content enum cases');
