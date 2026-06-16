<?php

// Integração com o ERP — leitura read-only (ver secção 5 do CLAUDE.md).
// A aplicação trabalha sempre contra a própria BD; o ERP é sincronizado periodicamente.
return [

    // Driver ativo: 'sqlsrv' (views read-only do ERP) ou 'fake' (dados de teste).
    // Vazio/ausente => driver inativo (NullErpDriver), não injeta dados fictícios.
    'driver' => env('ERP_DRIVER'),

    // Hora do sync diário de clientes (HH:MM). O comando corre 1x/dia a esta hora.
    'sync_hora' => env('ERP_SYNC_HORA', '03:00'),

    // Ligação read-only ao SQL Server do ERP (apenas a views dedicadas).
    'connections' => [
        'sqlsrv' => [
            'host' => env('ERP_DB_HOST'),
            'port' => env('ERP_DB_PORT', 1433),
            'database' => env('ERP_DB_DATABASE'),
            'username' => env('ERP_DB_USERNAME'),
            'password' => env('ERP_DB_PASSWORD'),
        ],
    ],
];
