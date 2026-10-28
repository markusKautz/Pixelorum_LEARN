<?php

namespace App\Enums;

enum ProgressStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * Get the display label for the progress status.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotStarted => __('enums.progress_status.not_started'),
            self::InProgress => __('enums.progress_status.in_progress'),
            self::Completed => __('enums.progress_status.completed'),
        };
    }

    /**
     * Get the badge colour for this status — **nie rot** (Konzept h,
     * Merkmal 3): offene Arbeit ist kein Fehler, sondern der Normalfall beim
     * Lernen.
     *
     * Steht hier statt in den Ansichten, damit die didaktische Regel einen Ort
     * hat: verteilt auf mehrere Komponenten kann eine davon still abweichen
     * (Sprint-5-Review, N2).
     */
    public function color(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::InProgress => 'amber',
            self::NotStarted => 'zinc',
        };
    }
}
