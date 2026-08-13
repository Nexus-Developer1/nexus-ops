<?php

// Limiares dos alertas proativos (CLAUDE.md §6/§9).
return [
    // Antecedência para alertar a troca de baterias de UPS (item mais valioso de antecipar).
    'bateria_aviso_dias' => env('ALERTAS_BATERIA_DIAS', 90),

    // Janela "crítica" de uma renovação de contrato (dias até ao fim).
    'renovacao_critica_dias' => env('ALERTAS_RENOVACAO_DIAS', 15),

    // Vigia de backups (18.ª análise → melhoria #2): quando ativa, o painel/email de alertas
    // dispara ALTA se o marcador que o scripts/backup.sh toca no fim de cada backup bem
    // sucedido faltar ou tiver mais de `backup_max_horas`. Opt-in por ambiente: liga-se em
    // produção DEPOIS de instalar o cron do backup (senão alertava em dev/staging à toa).
    'backup_vigia' => env('ALERTAS_BACKUP_VIGIA', false),
    'backup_max_horas' => env('ALERTAS_BACKUP_MAX_HORAS', 26),
];
