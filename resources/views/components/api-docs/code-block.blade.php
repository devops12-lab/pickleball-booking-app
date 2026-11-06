@props([
    'code',
    'label' => null,
    'language' => 'text',
    'copyLabel' => 'Copy',
])

<figure {{ $attributes->class('code-block') }}>
    <figcaption class="code-block-head">
        <span class="caption">{{ $label }}</span>
        <button type="button" class="copy-button" data-copy-text="{{ $code }}" data-copy-message="{{ $label ? $label.' copied' : 'Copied' }}">{{ $copyLabel }}</button>
    </figcaption>
    <pre><code @class(['language-json' => $language === 'json']) data-highlight="{{ $language }}">{{ $code }}</code></pre>
</figure>
