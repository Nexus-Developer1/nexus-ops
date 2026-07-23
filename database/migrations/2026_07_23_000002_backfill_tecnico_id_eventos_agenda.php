<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Consolidação da identidade do técnico nos eventos: casa eventos legados (só nome em
// texto livre, sem conta) com a conta de técnico respetiva, por nome normalizado. Com a
// conta ligada, esses eventos passam a contar para ausências, iCal e notificações — o
// caminho legado (conflitoPorNome, cores por nome) fica reduzido aos nomes que realmente
// não têm conta. Só nomes que resolvem para EXATAMENTE uma conta (ambíguos ficam como
// estão); não filtra por ativo — eventos históricos de técnicos entretanto desativados
// também devem ficar ligados.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE eventos_agenda e
            SET tecnico_id = u.id
            FROM (
                SELECT lower(trim(nome)) AS chave, min(id) AS id
                FROM utilizadores
                WHERE papel = 'tecnico'
                GROUP BY lower(trim(nome))
                HAVING count(*) = 1
            ) u
            WHERE e.tecnico_id IS NULL
              AND e.tecnico_nome IS NOT NULL
              AND lower(trim(e.tecnico_nome)) = u.chave
        SQL);
    }

    public function down(): void
    {
        // Sem reversão: não há como distinguir os tecnico_id atribuídos aqui dos originais.
    }
};
