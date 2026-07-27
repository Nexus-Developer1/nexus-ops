<?php

// Integração com o ERP — leitura read-only (ver secção 5 do CLAUDE.md).
// A aplicação trabalha sempre contra a própria BD; o ERP é sincronizado periodicamente.
return [

    // Driver ativo: 'sqlsrv' (views read-only do ERP) ou 'fake' (dados de teste).
    // Vazio/ausente => driver inativo (NullErpDriver), não injeta dados fictícios.
    // A ligação 'erp' (SQL Server, dblib) vive em config/database.php; o agendamento
    // do sync está em routes/console.php (3x/dia, hardcoded).
    'driver' => env('ERP_DRIVER'),

    // Destinatário do email de RESULTADO do sync agendado (08h/13h/19h) — sucesso ou falha.
    // O sync manual (botão no dashboard) é silencioso, não envia email.
    'email_sync' => env('ERP_EMAIL_SYNC', 'suporte@nxs.pt'),
];
