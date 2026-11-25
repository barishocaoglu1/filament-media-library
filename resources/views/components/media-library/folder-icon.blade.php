@props(['filled' => false])

<svg {{ $attributes->class(['ml-folder-svg']) }} viewBox="0 0 96 76" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path class="ml-folder-back" d="M6 10a6 6 0 0 1 6-6h20.5a6 6 0 0 1 4.6 2.1L42 12h42a6 6 0 0 1 6 6v46a6 6 0 0 1-6 6H12a6 6 0 0 1-6-6V10Z" />
    @if ($filled)
        <rect class="ml-folder-paper ml-folder-paper--back" x="14" y="16" width="68" height="40" rx="3" transform="rotate(-2 48 36)" />
        <rect class="ml-folder-paper" x="16" y="20" width="64" height="38" rx="3" />
    @endif
    <path class="ml-folder-front" d="M6 28a6 6 0 0 1 6-6h72a6 6 0 0 1 6 6v36a6 6 0 0 1-6 6H12a6 6 0 0 1-6-6V28Z" />
    <path class="ml-folder-shine" d="M12 22h72a6 6 0 0 1 6 6v2H6v-2a6 6 0 0 1 6-6Z" />
</svg>
