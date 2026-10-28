<?php

namespace App\Enums;

enum QuestionType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
    case Boolean = 'boolean';

    /**
     * Get the display label for the question type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Single => __('enums.question_type.single'),
            self::Multiple => __('enums.question_type.multiple'),
            self::Boolean => __('enums.question_type.boolean'),
        };
    }
}
