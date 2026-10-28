<?php

namespace App\Models;

use App\Actions\Learning\FinalizeAttempt;
use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Quiz-Versuch. Gewertet (score/max_score/passed) wird er ausschließlich
 * von {@see FinalizeAttempt}; ein laufender Versuch
 * trägt 0/0/false.
 *
 * `question_order` friert die Fragenliste **dieses** Versuchs ein (Sprint 5.5):
 * gesetzt bei gemischten und bei Übungs-Quizzen, `null` bei allen übrigen —
 * dann gilt die aktuelle Pivot-Reihenfolge des Quiz.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 * @property array<int, int>|null $question_order
 * @property int $score
 * @property int $max_score
 * @property bool $passed
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $percent
 * @property-read Collection<int, QuestionResponse> $responses
 * @property-read Quiz $quiz
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'quiz_id',
    'question_order',
    'score',
    'max_score',
    'passed',
    'started_at',
    'completed_at',
])]
class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    /**
     * Get the user who made this attempt.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the quiz this attempt belongs to.
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Get the individual answers given in this attempt.
     *
     * @return HasMany<QuestionResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(QuestionResponse::class);
    }

    /**
     * Get the achieved percentage of this attempt, floored (Entscheidung 7).
     *
     * Die Frage „wie viel Prozent" gehört dem Versuch — `score` und
     * `max_score` sind seine Spalten. Gerechnet wird sie trotzdem in
     * {@see Quiz::percentOf()}: dort steht die Abrunde-Regel, und die
     * Gruppen-Auswertung braucht sie auch für Aggregatzeilen ohne
     * Versuchs-Objekt. Hier steht nur der bequeme Zugang dafür — ein laufender
     * Versuch (0/0) liefert 0, keine Division durch null.
     *
     * @return Attribute<int, never>
     */
    protected function percent(): Attribute
    {
        return Attribute::get(fn (): int => Quiz::percentOf($this->score, $this->max_score));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_order' => 'array',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
