<?php

// Limiares dos alertas proativos (CLAUDE.md §6/§9).
return [
    // Antecedência para alertar a troca de baterias de UPS (item mais valioso de antecipar).
    'bateria_aviso_dias' => env('ALERTAS_BATERIA_DIAS', 90),

    // Janela "crítica" de uma renovação de contrato (dias até ao fim).
    'renovacao_critica_dias' => env('ALERTAS_RENOVACAO_DIAS', 15),
];
