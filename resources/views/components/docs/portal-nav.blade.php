@props([
    'current',
    'placement' => 'header',
])

@php
    $portals = [
        'api' => ['label' => 'API reference', 'url' => route('api-docs'), 'enabled' => config('api-docs.enabled')],
        'workflows' => ['label' => 'Backend workflows', 'url' => route('workflow-docs'), 'enabled' => config('workflow-docs.enabled')],
    ];
@endphp

<nav {{ $attributes->class(['portal-nav', 'portal-nav-'.$placement]) }} aria-label="Documentation sections">
    @foreach ($portals as $key => $portal)
        @if ($portal['enabled'])
            <a href="{{ $portal['url'] }}" @class(['portal-link', 'is-active' => $current === $key]) @if ($current === $key) aria-current="page" @endif>{{ $portal['label'] }}</a>
        @endif
    @endforeach
</nav>
