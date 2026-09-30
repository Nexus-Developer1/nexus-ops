<?php

// Configuração do gerador de PDF (barryvdh/laravel-dompdf) — a do pacote, com três apertos de
// segurança (27.ª revisão, set. 2026). Os PDFs só usam imagens embutidas (data:) e as fontes do
// próprio dompdf; nada disto muda o resultado, fecha portas caso algum dia entre HTML malicioso:
//  - sem JavaScript nos PDFs;
//  - só os protocolos data:// e file:// (http/https já estavam barrados pelo enable_remote=false);
//  - acesso a ficheiros locais só às pastas das FONTES (antes: a aplicação inteira, .env incluído).
$config = require base_path('vendor/barryvdh/laravel-dompdf/config/dompdf.php');

$config['options']['enable_javascript'] = false;
$config['options']['enable_remote'] = false;
$config['options']['enable_php'] = false;
$config['options']['allowed_protocols'] = [
    'data://' => ['rules' => []],
    'file://' => ['rules' => []],
];
$config['options']['chroot'] = array_values(array_filter([
    realpath(storage_path('fonts')) ?: null,
    realpath(base_path('vendor/dompdf/dompdf/lib/fonts')) ?: null,
]));

return $config;
