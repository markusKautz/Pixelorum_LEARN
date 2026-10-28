<?php

use Livewire\Component;

/**
 * Seite „Über" (S6.5): Urheberbezeichnung nach § 19 UrhG, Lizenzhinweis und
 * Link zum Quelltext (§ 13 AGPL),
 * erreichbar über das Nutzermenü statt über einen Footer.
 */
new class extends Component {
    public function render(): mixed
    {
        return $this->view()->title(__('About :app', ['app' => config('app.name')]));
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('About :app', ['app' => config('app.name')]) }}</flux:heading>
        <div class="mt-2 space-y-2" data-test="about-description">
            <flux:text>
                {{ __(':app is a lean learning platform for training groups and their trainers. The content is checked against sources and approved, and practice focuses on your own weak spots.', ['app' => config('app.name')]) }}
            </flux:text>
            <flux:text>
                {{ __(':app is meant to support learning, not to monitor it: it stores what you have learned, not where you click.', ['app' => config('app.name')]) }}
            </flux:text>
        </div>
    </div>

    <flux:separator variant="subtle" />

    <dl class="space-y-4">
        <div data-test="about-author">
            <dt><flux:text class="text-sm">{{ __('Author') }}</flux:text></dt>
            <dd><flux:heading>{{ __('Developed by :name', ['name' => 'Markus Kautz']) }}</flux:heading></dd>
        </div>

        <div data-test="about-license">
            <dt><flux:text class="text-sm">{{ __('Licence') }}</flux:text></dt>
            <dd>
                <flux:heading>© 2026 Markus Kautz</flux:heading>
                <flux:text>{{ __('Free software under the GNU Affero General Public License, version 3 or later.') }}</flux:text>
            </dd>
        </div>

        <div data-test="about-source">
            <dt><flux:text class="text-sm">{{ __('Source code') }}</flux:text></dt>
            <dd><flux:link href="{{ config('lapp.source_url') }}" external>{{ config('lapp.source_url') }}</flux:link></dd>
        </div>
    </dl>
</section>
