<?php

namespace App\Exceptions;

use Exception;

/**
 * Die Löschung träfe den letzten Admin (#10).
 *
 * Neue Admins entstehen nur über `lapp:make-admin` auf der Kommandozeile; ohne
 * einen verbliebenen Admin wäre die Verwaltung also ausgesperrt.
 */
class LastAdminException extends Exception
{
    public function __construct()
    {
        parent::__construct('The last admin cannot be deleted.');
    }
}
