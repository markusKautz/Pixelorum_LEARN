<?php

namespace App\Enums;

/**
 * Typ einer Lernquelle; bestimmt den passenden Locator einer Quellenangabe.
 */
enum SourceKind: string
{
    case Pdf = 'pdf';
    case Slides = 'slides';
    case Web = 'web';

    /**
     * Get the locator a source reference to this kind of source must use.
     */
    public function locator(): string
    {
        return match ($this) {
            self::Pdf => 'page',
            self::Slides => 'slide',
            self::Web => 'section',
        };
    }
}
