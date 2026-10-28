<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Verified = 'verified';

    /**
     * Get the display label for the review status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unreviewed => __('enums.review_status.unreviewed'),
            self::Verified => __('enums.review_status.verified'),
        };
    }
}
