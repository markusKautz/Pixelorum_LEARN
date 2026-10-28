{{--
    Aufklappliste (S6.5, Ersatz für das Flux-Pro-Accordion, Clean-Room-Nachbau).
    Mehrere Einträge dürfen gleichzeitig offen sein; zwischen den Einträgen
    steht eine dezente Trennlinie. Den Zustand hält jeder Eintrag selbst
    (<x-ui.accordion.item>), daher kein x-data auf der Liste.
--}}
<div {{ $attributes->class('divide-y divide-zinc-200 dark:divide-white/10') }}>
    {{ $slot }}
</div>
