<?php

use App\Models\Quiz;
use App\Models\QuizAttempt;

/*
 * Der Accessor `QuizAttempt::$percent` rechnet nicht selbst, sondern
 * delegiert an Quiz::percentOf() — dort steht die Abrunde-Regel aus
 * Entscheidung 7. Diese Tests halten beides fest: dass abgerundet wird
 * (und nicht gerundet), und dass der Accessor dieselbe Zahl liefert wie
 * die static-Methode, an die er delegiert.
 *
 * Ohne Datenbank: die Rechnung liest nur zwei eigene Spalten.
 */

/**
 * Build an unsaved attempt with the given score.
 */
function attemptScoring(int $score, int $maxScore): QuizAttempt
{
    return new QuizAttempt(['score' => $score, 'max_score' => $maxScore]);
}

test('the percentage is floored rather than rounded', function (int $score, int $maxScore, int $expected) {
    expect(attemptScoring($score, $maxScore)->percent)->toBe($expected);
})->with([
    // 79,6 % darf nicht als 80 erscheinen — sonst stünde „80 %" neben
    // „nicht bestanden".
    'just below the threshold' => [199, 250, 79],
    'exactly on it' => [4, 5, 80],
    'everything right' => [8, 8, 100],
    'nothing right' => [0, 8, 0],
    'a third' => [1, 3, 33],
]);

test('a running attempt reports zero instead of dividing by zero', function () {
    // Ein laufender Versuch trägt 0/0, bis FinalizeAttempt ihn wertet.
    expect(attemptScoring(0, 0)->percent)->toBe(0);
});

test('the accessor returns what it delegates to', function () {
    $attempt = attemptScoring(5, 8);

    expect($attempt->percent)->toBe(Quiz::percentOf($attempt->score, $attempt->max_score));
});
