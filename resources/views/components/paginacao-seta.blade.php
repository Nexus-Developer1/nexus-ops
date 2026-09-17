{{-- Setas da barra de páginas: anterior/seguinte e primeira/última (seta dupla). --}}
<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($sentido)
        @case('anterior')
            <path d="M15 6l-6 6 6 6"/>
            @break
        @case('seguinte')
            <path d="M9 6l6 6-6 6"/>
            @break
        @case('primeira')
            <path d="M18 6l-6 6 6 6M11 6l-6 6 6 6"/>
            @break
        @case('ultima')
            <path d="M6 6l6 6-6 6M13 6l6 6-6 6"/>
            @break
    @endswitch
</svg>
