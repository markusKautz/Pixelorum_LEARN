<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Root
    |--------------------------------------------------------------------------
    |
    | Verzeichnis der lokalen Content-Pipeline, relativ zum Projektordner. Darin
    | liegen `content/` (Lernpfad-JSONs) und `ressource/` (Quellregister
    | `sources.json`, Quellmaterial, Extrakte und Web-Schnappschüsse). Es gehört
    | nicht zum Repository. Fehlt es, brechen `lapp:content:validate` ohne
    | `--file` und `lapp:content:extract` mit einer Meldung ab; Upload, Export
    | und `lapp:content:validate --file` brauchen es nicht.
    |
    */

    'content_root' => env('LAPP_CONTENT_ROOT', 'doc'),

    /*
    |--------------------------------------------------------------------------
    | Source URL
    |--------------------------------------------------------------------------
    |
    | Wo der Quelltext dieser Installation liegt. Die AGPL verlangt, ihn allen
    | anzubieten, die die Anwendung über das Netz nutzen; die Seite „Über“
    | verlinkt ihn. Wer eine geänderte Fassung betreibt, trägt hier deren
    | Quelltext ein.
    |
    */

    'source_url' => env('LAPP_SOURCE_URL', 'https://github.com/markusKautz/Pixelorum_LEARN'),

];
