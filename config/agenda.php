<?php

// Parâmetros da agenda. O horário comercial é usado na deteção de conflitos
// ("fora de horário de cobertura" — CLAUDE.md §6) e como janela das visitas.
return [
    'hora_abertura' => env('AGENDA_HORA_ABERTURA', 8),   // 08:00
    'hora_fecho' => env('AGENDA_HORA_FECHO', 19),        // 19:00

    // Dias úteis (ISO: 1 = segunda … 7 = domingo).
    'dias_uteis' => [1, 2, 3, 4, 5],
];
