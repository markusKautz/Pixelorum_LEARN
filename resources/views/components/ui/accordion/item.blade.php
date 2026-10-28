{{--
    Ein Eintrag der Aufklappliste. Startet zugeklappt; x-id liefert eine
    eindeutige ID, über die Überschrift (aria-controls) und Inhalt
    zusammenfinden.
--}}
<div
    x-data="{ open: false }"
    x-id="['accordion-content']"
    {{ $attributes }}
>
    {{ $slot }}
</div>
