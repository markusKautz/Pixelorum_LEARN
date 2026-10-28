{{--
    Aufklappbarer Inhalt eines Eintrags. x-collapse (in Livewires Alpine
    enthalten) animiert die Höhe; x-cloak verhindert, dass der Inhalt vor dem
    Start von Alpine kurz aufblitzt.
--}}
<div
    x-show="open"
    x-collapse
    x-cloak
    x-bind:id="$id('accordion-content')"
    role="region"
>
    <div {{ $attributes->class('pb-4 text-zinc-500 dark:text-zinc-400') }}>
        {{ $slot }}
    </div>
</div>
