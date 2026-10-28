@props(['block', 'glossary' => null])

<x-ui.accordion data-test="lesson-block-accordion">
    @foreach ($block->content['items'] ?? [] as $item)
        <x-ui.accordion.item>
            <x-ui.accordion.heading>{{ $item['heading'] ?? '' }}</x-ui.accordion.heading>
            <x-ui.accordion.content>
                <x-lesson.markdown :text="$item['body'] ?? ''" :glossary="$glossary" />
            </x-ui.accordion.content>
        </x-ui.accordion.item>
    @endforeach
</x-ui.accordion>
