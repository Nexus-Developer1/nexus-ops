<?php

// API de sincronização (routes/api.php): dispara e monitoriza os syncs PHC → Nexus Infra a
// partir de fora do browser (botão no PHC, cron externo, NXSync, curl). Autenticação por
// CHAVE PARTILHADA — quem chama é um sistema, não uma pessoa (sem utilizadores nem papéis).
// Sem chave configurada, a API está DESLIGADA (fail-closed: 503 em todos os pedidos).
return [
    // Gerar com: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  → guardar SÓ no .env do servidor.
    'chave' => env('API_SYNC_CHAVE'),
];
