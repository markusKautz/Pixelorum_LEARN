<?php

namespace App\Models;

use Database\Factories\QuestionResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine einzelne Antwort innerhalb eines Quiz-Versuchs.
 *
 * @property int $id
 * @property int $quiz_attempt_id
 * @property int $question_id
 * @property array<int, int> $selected_option_ids
 * @property bool $is_correct
 * @property-read Question $question
 * @property-read QuizAttempt $quizAttempt
 */
#[Fillable(['quiz_attempt_id', 'question_id', 'selected_option_ids', 'is_correct'])]
class QuestionResponse extends Model
{
    /** @use HasFactory<QuestionResponseFactory> */
    use HasFactory;

    /**
     * Das Timing liefert der zugehörige Versuch (Konzept d).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attempt this answer belongs to.
     *
     * @return BelongsTo<QuizAttempt, $this>
     */
    public function quizAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class);
    }

    /**
     * Get the answered question.
     *
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct' => 'boolean',
        ];
    }
}
