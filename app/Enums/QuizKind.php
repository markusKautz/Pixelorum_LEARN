<?php

namespace App\Enums;

enum QuizKind: string
{
    case PathFinal = 'path_final';
    case Custom = 'custom';

    /**
     * Der persönliche Übungsbehälter eines Lehrlings (Sprint 5.5): kein
     * Redaktionsinhalt, sondern die materialisierte Zufallsauswahl seiner
     * letzten Übungssitzung. Genau einer je Lehrling, `created_by` ist er
     * selbst — und nur er sieht ihn.
     */
    case Practice = 'practice';

    /**
     * Get the display label for the quiz kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::PathFinal => __('enums.quiz_kind.path_final'),
            self::Custom => __('enums.quiz_kind.custom'),
            self::Practice => __('enums.quiz_kind.practice'),
        };
    }

    /**
     * Determine whether this kind is content an admin curates.
     *
     * Übungsbehälter gehören nicht in die Redaktionslisten — sie sind
     * persönliche Nebenprodukte des Lernens, kein gepflegter Inhalt.
     */
    public function isEditorial(): bool
    {
        return $this !== self::Practice;
    }

    /**
     * Get the kinds an admin may pick in the quiz forms.
     *
     * @return array<int, self>
     */
    public static function editorialCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->isEditorial(),
        ));
    }
}
