{{--
    Bildmarke von „LEARN": die aufsteigende Pixel-Treppe aus dem
    Logo, ohne Wortmarke. Die Quadrate liegen auf einem 3×3-Raster (Kantenlänge
    22,6 bei 24,7 Schrittweite); die Maße stammen aus public/images/logo-learn.svg,
    damit Marke und Icon deckungsgleich sind.

    Die Farben stehen bewusst als Literale im Markup, denn es ist Artwork und
    kein UI: die Marke darf nicht mit dem Theme kippen. Sie entsprechen den
    Tokens brand-500 / success / warning / danger aus resources/css/app.css.
--}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72" fill="none" {{ $attributes }}>
    <rect x="49.4" y="0" width="22.6" height="22.6" fill="#cb6ce6" />
    <rect x="24.7" y="24.7" width="22.6" height="22.6" fill="#ff3131" />
    <rect x="49.4" y="24.7" width="22.6" height="22.6" fill="#5271ff" />
    <rect x="0" y="49.4" width="22.6" height="22.6" fill="#00bf63" />
    <rect x="24.7" y="49.4" width="22.6" height="22.6" fill="#5271ff" />
    <rect x="49.4" y="49.4" width="22.6" height="22.6" fill="#ffbd59" />
</svg>
