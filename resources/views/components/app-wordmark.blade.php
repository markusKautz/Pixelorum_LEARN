{{--
    Wortmarke „LEARN" (Bildmarke + Schriftzug). Der Viewport ist auf die
    Inhaltsgrenzen beschnitten (351,07 × 72,47), damit die Marke ohne Luft an
    den Rändern sitzt; die Höhe gibt die aufrufende Stelle per Klasse vor, die
    Breite folgt.

    Zwei Dateien statt einer: der Schriftzug ist im Export fest dunkelgrau
    (#545454) bzw. hell (#dfdede) eingefärbt, currentColor gibt es dort nicht.
    Der Tausch läuft über die dark-Variante aus resources/css/app.css. Das
    jeweils versteckte Bild steht auf display:none und landet damit auch nicht
    im Accessibility-Baum, der Alternativtext wird also nur einmal vorgelesen.
--}}
<img
    src="{{ asset('images/logo-learn.svg') }}"
    alt="{{ config('app.name') }}"
    {{ $attributes->class('w-auto max-w-full dark:hidden') }}
/>
<img
    src="{{ asset('images/logo-learn-dark.svg') }}"
    alt="{{ config('app.name') }}"
    {{ $attributes->class('hidden w-auto max-w-full dark:block') }}
/>
