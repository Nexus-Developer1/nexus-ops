<?php

// Processo de validação das despesas (pedido da equipa, set. 2026).
$lista = fn (string $env, string $defeito) => array_values(array_filter(array_map(
    fn ($e) => strtolower(trim($e)),
    explode(',', (string) env($env, $defeito)),
)));

return [
    // Quem pode aprovar/rejeitar (emails das contas da aplicação, separados por vírgula).
    // SÓ estes — os administradores não aprovam (set. 2026). Um substituto (férias, ausência)
    // entra acrescentando o email dele aqui.
    'aprovadores' => $lista('DESPESAS_APROVADORES', 'pgouveia@nxs.pt'),

    // Quem recebe os emails do processo (submissão e decisão), além de quem criou a despesa.
    'notificar' => $lista('DESPESAS_NOTIFICAR', 'pgouveia@nxs.pt,financeiro@nxs.pt'),

    // Quem recebe SÓ o email de despesa APROVADA (nem a submissão, nem a rejeição): a
    // contabilidade, que trata do que já está aprovado (set. 2026).
    'notificar_aprovacao' => $lista('DESPESAS_NOTIFICAR_APROVACAO', 'contabilidade@nxs.pt'),
];
