@props([
    'sidebar' => false,
])

{{--
    Bewusst ein eigener Anker statt flux:brand: dessen Anker ist fest auf h-10
    gesetzt und deckelt damit die Wortmarke. Die Flux-Komponente bringt sonst
    nur noch ein Datenattribut mit, an dem kein Stylesheet hängt, und das
    Icon-Rounding, das den Schriftzug an den Ecken beschneiden würde.

    Den Namen liest der Screenreader aus dem Alternativtext in x-app-wordmark.
--}}
<a {{ $attributes->class(['flex min-w-0 shrink-0 items-center', 'px-2' => $sidebar]) }}>
    <x-app-wordmark class="h-10" />
</a>
