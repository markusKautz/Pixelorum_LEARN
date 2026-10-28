<?php

namespace App\Enums;

/**
 * Was mit einer Zeile der Importdatei passieren wird.
 *
 * Der Status entsteht in der Vorschau, **bevor** irgendetwas angelegt wird:
 * eine Liste mit verrutschtem Spaltentrenner soll nicht erst an ihren
 * Fehleinträgen erkennbar werden.
 */
enum InvitationImportStatus: string
{
    case Valid = 'valid';
    case AlreadyMember = 'already_member';
    case AlreadyInvited = 'already_invited';
    case InvalidEmail = 'invalid_email';
    case DuplicateInFile = 'duplicate_in_file';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Valid => __('Will be invited'),
            self::AlreadyMember => __('Already a member'),
            self::AlreadyInvited => __('Already invited'),
            self::InvalidEmail => __('Not a valid email address'),
            self::DuplicateInFile => __('Listed twice in the file'),
        };
    }

    /**
     * Die Farbe des Badges in der Vorschau.
     */
    public function color(): string
    {
        return match ($this) {
            self::Valid => 'green',
            self::InvalidEmail => 'red',
            default => 'zinc',
        };
    }

    /**
     * Ob aus dieser Zeile eine Einladung entsteht.
     */
    public function isInvitable(): bool
    {
        return $this === self::Valid;
    }
}
