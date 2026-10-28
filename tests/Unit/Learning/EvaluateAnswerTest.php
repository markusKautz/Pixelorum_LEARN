<?php

use App\Actions\Learning\EvaluateAnswer;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionOption;

/*
 * Die Tabellen-Tests aus Konzept i — Scoring ist der kritischste Testbereich
 * des Projekts. Bewusst ohne Datenbank: EvaluateAnswer arbeitet rein auf der
 * eager-geladenen Options-Relation, und genau das hält dieser Test fest.
 */

/**
 * Build a question whose options are correct at the listed 1-based ids.
 *
 * @param  array<int, bool>  $correctness  je Option, in Anzeigereihenfolge
 */
function questionWith(QuestionType $type, array $correctness): Question
{
    $question = new Question([
        'type' => $type,
        'prompt' => 'Beispielfrage?',
        'explanation' => 'Begründung.',
    ]);

    $question->setRelation('options', collect($correctness)->map(function (bool $isCorrect, int $index) {
        $option = new QuestionOption([
            'label' => 'Option '.($index + 1),
            'is_correct' => $isCorrect,
            'position' => $index + 1,
        ]);

        $option->id = $index + 1;

        return $option;
    }));

    return $question;
}

test('single choice is scored by the one selected option', function (array $selection, bool $expected) {
    $question = questionWith(QuestionType::Single, [false, true, false, false]);

    expect(app(EvaluateAnswer::class)->handle($question, $selection))->toBe($expected);
})->with([
    'correct option' => [[2], true],
    'wrong option' => [[1], false],
    'nothing selected' => [[], false],
    'two options selected' => [[1, 2], false],
]);

test('boolean questions are scored like single choice', function (array $selection, bool $expected) {
    $question = questionWith(QuestionType::Boolean, [true, false]);

    expect(app(EvaluateAnswer::class)->handle($question, $selection))->toBe($expected);
})->with([
    'true is correct' => [[1], true],
    'false is wrong' => [[2], false],
    'nothing selected' => [[], false],
]);

test('multiple choice only counts when the selection matches exactly', function (array $selection, bool $expected) {
    // Optionen 1, 2 und 4 sind korrekt, 3 und 5 nicht.
    $question = questionWith(QuestionType::Multiple, [true, true, false, true, false]);

    expect(app(EvaluateAnswer::class)->handle($question, $selection))->toBe($expected);
})->with([
    'all correct ones' => [[1, 2, 4], true],
    'order does not matter' => [[4, 1, 2], true],
    'duplicates do not matter' => [[1, 2, 2, 4], true],
    'proper subset' => [[1, 2], false],
    'single correct one' => [[1], false],
    'all correct plus a wrong one' => [[1, 2, 3, 4], false],
    'only wrong ones' => [[3, 5], false],
    'nothing selected' => [[], false],
]);

test('option ids arriving as strings are compared as integers', function () {
    $question = questionWith(QuestionType::Multiple, [true, false, true]);

    // Aus dem Livewire-Zustand kommen Checkbox-Werte als Strings zurück.
    expect(app(EvaluateAnswer::class)->handle($question, ['3', '1']))->toBeTrue();
});

test('a question without a correct option can never be answered correctly', function () {
    $question = questionWith(QuestionType::Single, [false, false]);

    expect(app(EvaluateAnswer::class)->handle($question, [1]))->toBeFalse()
        ->and(app(EvaluateAnswer::class)->handle($question, []))->toBeFalse();
});

/*
 * Die Auswahl wird auf dem echten Weg zweimal normalisiert: erst von
 * `Question::sanitizeSelection()` an der Livewire-Grenze, dann nochmal von
 * `EvaluateAnswer::normalize()`. Die drei Zeilen stehen absichtlich doppelt
 * (S4-N2) — dort prüfen sie Eingaben ab, hier machen sie Mengen vergleichbar,
 * und eine neue Prüfregel darf nur an einer der beiden Stellen landen.
 *
 * Was aber gelten muss: beide erzeugen dieselbe Normalform. Die folgenden
 * Tests nageln das fest, statt die Implementierungen aneinanderzubinden.
 * `sanitizeSelection()` bekommt hier nebenbei seine erste direkte Deckung —
 * bisher lief es nur über die Livewire-Seiten mit.
 */
test('the sanitiser turns a messy selection into sorted unique integers', function () {
    $question = questionWith(QuestionType::Multiple, [true, false, true]);

    // Unsortiert, doppelt, teils als String — so kommt es aus dem Client.
    expect($question->sanitizeSelection(['3', 1, '1', 3]))->toBe([1, 3]);
});

test('the sanitiser rejects a selection that does not fit the question', function (QuestionType $type, array $selection) {
    $question = questionWith($type, [true, false, true]);

    expect($question->sanitizeSelection($selection))->toBeNull();
})->with([
    'nothing selected' => [QuestionType::Multiple, []],
    'an option of a foreign question' => [QuestionType::Multiple, [1, 99]],
    'two options on a single choice' => [QuestionType::Single, [1, 2]],
]);

test('both normalisations agree, so a messy selection scores like its sanitised form', function (array $messy, bool $expected) {
    $question = questionWith(QuestionType::Multiple, [true, false, true]);

    $sanitised = $question->sanitizeSelection($messy);

    // Beide Wege müssen dasselbe Urteil ergeben — und zwar das richtige.
    // Nur „gleich" zu prüfen wäre auch erfüllt, wenn beide gleich falsch
    // lägen; genau das passiert, wenn eine der Normalisierungen das sort()
    // verliert und die Mengengleichheit an der Reihenfolge scheitert.
    expect(app(EvaluateAnswer::class)->handle($question, $messy))->toBe($expected)
        ->and(app(EvaluateAnswer::class)->handle($question, $sanitised ?? []))->toBe($expected);
})->with([
    'correct, but unsorted with duplicates and strings' => [['3', 1, '1', 3], true],
    'incomplete' => [['1'], false],
    'one correct and one wrong' => [[1, '2'], false],
]);
