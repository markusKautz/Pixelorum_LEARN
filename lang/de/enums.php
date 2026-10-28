<?php

/*
|--------------------------------------------------------------------------
| Enum-Labels
|--------------------------------------------------------------------------
|
| Namespaced Keys je Enum (statt flacher Keys in de.json): Case-Namen wie
| "Draft", "Custom" oder "Markdown" sind zu generisch für den globalen,
| namensraumlosen Key-Space von de.json und könnten anderswo im UI eine
| andere Übersetzung brauchen.
|
*/

return [

    'question_type' => [
        'single' => 'Einfachauswahl',
        'multiple' => 'Mehrfachauswahl',
        'boolean' => 'Wahr/Falsch',
    ],

    'quiz_kind' => [
        'path_final' => 'Abschluss-Quiz',
        'custom' => 'Freies Quiz',
        'practice' => 'Übungsquiz',
    ],

    'path_status' => [
        'draft' => 'Entwurf',
        'published' => 'Veröffentlicht',
    ],

    'review_status' => [
        'unreviewed' => 'Ungeprüft',
        'verified' => 'Geprüft',
    ],

    'lesson_block_type' => [
        'markdown' => 'Fließtext',
        'accordion' => 'Aufklappbereich',
        'callout' => 'Hinweisbox',
        'code_snippet' => 'Code-Beispiel',
        'diagram' => 'Diagramm',
        'media' => 'Medien',
        'glossary' => 'Glossar',
        'section_break' => 'Abschnitt',
    ],

    'progress_status' => [
        'not_started' => 'Nicht begonnen',
        'in_progress' => 'In Arbeit',
        'completed' => 'Abgeschlossen',
    ],

];
