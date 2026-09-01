<?php

// Processo de validação das despesas (pedido da equipa, set. 2026).
$lista = fn (string $env, string $defeito) => array_values(array_filter(array_map(
    fn ($e) => strtolower(trim($e)),
    explode(',', (string) env($env, $defeito)),
)));

return [
    // Quem pode aprovar/rejeitar (emails das contas da aplicação, separados por vírgula).
    // Os administradores podem sempre — para o fluxo não ficar bloqueado se o aprovador não
    // tiver conta ou estiver ausente.
    'aprovadores' => $lista('DESPESAS_APROVADORES', 'pgouveia@nxs.pt'),

    // Quem recebe os emails do processo (submissão e decisão), além de quem criou a despesa.
    'notificar' => $lista('DESPESAS_NOTIFICAR', 'pgouveia@nxs.pt,financeiro@nxs.pt'),
];
