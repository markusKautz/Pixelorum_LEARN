<?php

use Symfony\Component\Finder\Finder;

/**
 * Eigene Ersatzkomponenten für das entfernte Flux Pro (S6.5). Die Tests
 * prüfen das serverseitig gerenderte Markup; Öffnen, Schließen und Ablegen
 * laufen in Alpine und gehören in die Browser-Abnahme.
 */
test('the accordion renders each heading as a button wired to its content', function () {
    $view = $this->blade(<<<'BLADE'
        <x-ui.accordion data-test="demo-accordion">
            <x-ui.accordion.item>
                <x-ui.accordion.heading>Erste Frage</x-ui.accordion.heading>
                <x-ui.accordion.content class="space-y-3">Erste Antwort</x-ui.accordion.content>
            </x-ui.accordion.item>
            <x-ui.accordion.item>
                <x-ui.accordion.heading>Zweite Frage</x-ui.accordion.heading>
                <x-ui.accordion.content>Zweite Antwort</x-ui.accordion.content>
            </x-ui.accordion.item>
        </x-ui.accordion>
        BLADE);

    $view->assertSee('data-test="demo-accordion"', escape: false)
        ->assertSee('divide-y', escape: false)
        ->assertSeeInOrder(['Erste Frage', 'Erste Antwort', 'Zweite Frage', 'Zweite Antwort'])
        ->assertSee('x-bind:aria-controls="$id(\'accordion-content\')"', escape: false)
        ->assertSee('x-bind:id="$id(\'accordion-content\')"', escape: false)
        ->assertSee('x-collapse', escape: false)
        ->assertSee('space-y-3', escape: false);

    expect(substr_count((string) $view, 'x-data="{ open: false }"'))->toBe(2)
        ->and(substr_count((string) $view, '<button'))->toBe(2);
});

test('the popover anchors its panel to the trigger on the requested side', function () {
    $view = $this->blade(<<<'BLADE'
        <x-ui.popover position="top" panel-class="w-96" wire:key="demo-popover">
            <x-slot:trigger>
                <button type="button">Öffnen</button>
            </x-slot:trigger>
            Panelinhalt
        </x-ui.popover>
        BLADE);

    $view->assertSee('x-anchor.top.offset.8="$refs.trigger"', escape: false)
        ->assertSee('role="dialog"', escape: false)
        ->assertSee('w-96', escape: false)
        ->assertSee('wire:key="demo-popover"', escape: false)
        ->assertSee('x-on:click.outside="open = false"', escape: false)
        ->assertSeeInOrder(['Öffnen', 'Panelinhalt']);
});

test('the popover opens downwards by default', function () {
    // Mehrzeilig: Direkt angehängter Text machte aus `</x-slot:trigger>` die
    // unbekannte Direktive `@endslotInhalt`, der Slot-Puffer blieb offen.
    $this->blade(<<<'BLADE'
        <x-ui.popover>
            <x-slot:trigger>
                <button>Auf</button>
            </x-slot:trigger>
            Inhalt
        </x-ui.popover>
        BLADE)
        ->assertSee('x-anchor.bottom.offset.8', escape: false)
        ->assertSeeInOrder(['Auf', 'Inhalt']);
});

test('the file upload puts wire attributes on the hidden input and the rest on the wrapper', function () {
    $view = $this->blade(<<<'BLADE'
        <x-ui.file-upload
            wire:model="imageUpload"
            accept="image/*"
            label="Bilddatei"
            description="Wird beim Speichern abgelegt."
            heading="Bild hierher ziehen"
            text="JPG oder PNG"
            data-test="demo-upload"
        />
        BLADE);

    $view->assertSee('<input type="file" x-ref="input" class="sr-only" wire:model="imageUpload" accept="image/*" />', escape: false)
        ->assertSee('data-test="demo-upload"', escape: false)
        ->assertSeeInOrder(['Bilddatei', 'Wird beim Speichern abgelegt.', 'Bild hierher ziehen', 'JPG oder PNG'])
        ->assertSee('x-on:drop.prevent', escape: false)
        ->assertSee('x-on:livewire-upload-progress', escape: false);

    expect((string) $view)->not->toMatch('/<div[^>]*wire:model/');
});

test('the file item shows name, readable size, preview and its actions', function () {
    $this->blade(<<<'BLADE'
        <x-ui.file-item heading="schaubild.png" image="/vorschau.png" :size="1048576" data-test="demo-item">
            <x-slot name="actions">
                <x-ui.file-item.remove wire:click="removeImageUpload" aria-label="Datei entfernen" />
            </x-slot>
        </x-ui.file-item>
        BLADE)
        ->assertSee('schaubild.png')
        ->assertSee('1 MB')
        ->assertSee('src="/vorschau.png"', escape: false)
        ->assertSee('wire:click="removeImageUpload"', escape: false)
        ->assertSee('aria-label="Datei entfernen"', escape: false)
        ->assertSee('data-test="demo-item"', escape: false);
});

test('the file item falls back to a file icon without a preview', function () {
    $this->blade('<x-ui.file-item heading="unterlagen.pdf" />')
        ->assertSee('unterlagen.pdf')
        ->assertDontSee('<img', escape: false);
});

/**
 * Wächter gegen den Rückfall: Flux Pro ist entfernt (S6.5). Ein Pro-Tag in
 * einer View würde ohne das Paket ungerendert im HTML landen, ohne dass ein
 * anderer Test es bemerkt.
 */
test('no view uses a Flux Pro component and composer no longer requires it', function () {
    $proTag = '/<flux:(accordion|autocomplete|calendar|carousel|chart|color-picker|command|composer|context|date-picker|editor|file-item|file-upload|kanban|pillbox|popover|slider|tab|tabs|timeline|time-picker)[\s.>\/]/';
    $offenders = [];

    $files = Finder::create()
        ->files()
        ->in(resource_path('views'))
        ->name('*.blade.php');

    foreach ($files as $file) {
        if (preg_match($proTag, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($offenders)->toBe([], implode(', ', $offenders).' — stattdessen die x-ui-Komponenten verwenden.')
        ->and($composer['require'])->not->toHaveKey('livewire/flux-pro')
        ->and($composer)->not->toHaveKey('repositories');
});
