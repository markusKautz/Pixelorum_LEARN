<?php

namespace App\Enums;

enum PathStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('enums.path_status.draft'),
            self::Published => __('enums.path_status.published'),
        };
    }
}
