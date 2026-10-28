{{--
    Ein Quellenbeleg in der Freigabe (DEV-3, E10): Titel statt source_id,
    Webquellen verlinkt. Eine Quelle, die `content_sources` nicht kennt, steht
    rot mit ihrer source_id da und blockiert die Freigabe.

    @var array{source_id: string, page?: int, slide?: int, section?: string} $reference
    @var \Illuminate\Support\Collection<string, \App\Models\ContentSource> $sources
--}}
@php
    $source = $sources->get($reference['source_id']);
    $label = collect([
        $source?->title ?? $reference['source_id'],
        isset($reference['page']) ? __('Page').' '.$reference['page'] : null,
        isset($reference['slide']) ? __('Slide').' '.$reference['slide'] : null,
        isset($reference['section']) ? __('Section').': '.$reference['section'] : null,
    ])->filter()->implode(', ');
@endphp

@if ($source === null)
    <flux:badge size="sm" color="red" data-test="source-reference-badge" data-unknown-source title="{{ __('Unknown source') }}">{{ $label }}</flux:badge>
@elseif ($source->url !== null)
    <a href="{{ $source->url }}" target="_blank" rel="noopener" title="{{ $source->source_id }}">
        <flux:badge size="sm" color="zinc" icon:trailing="arrow-top-right-on-square" data-test="source-reference-badge">{{ $label }}</flux:badge>
    </a>
@else
    <flux:badge size="sm" color="zinc" data-test="source-reference-badge" title="{{ $source->source_id }}">{{ $label }}</flux:badge>
@endif
