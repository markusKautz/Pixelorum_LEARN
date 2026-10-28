<?php

namespace App\Data;

/**
 * Der Einstiegspunkt „Weiterlernen, wo aufgehört" (Konzept h, Merkmal 2).
 *
 * Bewusst nur Anzeige- und Routendaten statt eines Models: das Dashboard
 * muss nicht wissen, ob dahinter eine Lektion, ein Quiz oder ein Pfad steckt.
 */
readonly class ContinuationPoint
{
    /**
     * @param  string  $label  Beschriftung des Knopfes, z.B. „Quiz fortsetzen"
     * @param  string  $title  Worum es geht — Lektions- bzw. Quiz-Titel
     * @param  string|null  $context  Übergeordneter Pfad, falls vorhanden
     * @param  string  $routeName  Ziel-Route
     * @param  array<string, mixed>  $routeParameters
     */
    public function __construct(
        public string $label,
        public string $title,
        public ?string $context,
        public string $routeName,
        public array $routeParameters,
    ) {
        //
    }
}
