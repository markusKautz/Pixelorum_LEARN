<?php

namespace App\Rules;

use App\Enums\QuestionType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Erzwingt die Typ-Invarianten des Fragen-Pools (Konzept f, konsistent mit
 * den Factory-States aus S2.3): Single = genau eine korrekte Option,
 * Multiple = mindestens eine, Boolean = genau zwei Optionen mit genau einer
 * korrekten.
 */
class QuestionOptionsMatchType implements ValidationRule
{
    public function __construct(private readonly QuestionType $type) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('The options must be a list.'));

            return;
        }

        $total = count($value);
        $correct = collect($value)
            ->filter(fn (mixed $option) => is_array($option) && (bool) ($option['is_correct'] ?? false))
            ->count();

        [$passes, $message] = match ($this->type) {
            QuestionType::Single => [
                $total >= 2 && $correct === 1,
                __('A single-choice question needs at least two options, exactly one of them correct.'),
            ],
            QuestionType::Multiple => [
                $total >= 2 && $correct >= 1,
                __('A multiple-choice question needs at least two options, at least one of them correct.'),
            ],
            QuestionType::Boolean => [
                $total === 2 && $correct === 1,
                __('A true/false question needs exactly two options, exactly one of them correct.'),
            ],
        };

        if (! $passes) {
            $fail($message);
        }
    }
}
