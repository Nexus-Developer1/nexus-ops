<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Índices nas ligações (chaves estrangeiras) que as páginas usam para ir buscar os registos de
// um cliente, equipamento, contrato ou registo de despesas (set. 2026). O PostgreSQL não cria
// índice sozinho numa chave estrangeira: sem ele, «os equipamentos deste cliente», «as linhas
// deste registo» ou «os serviços deste equipamento» percorriam a tabela inteira. A mais pesada
// hoje é equipamentos.local_id (~18 mil); as outras crescem com o uso. Idempotente.
return new class extends Migration
{
    private const INDICES = [
        'equipamentos' => ['local_id'],
        'locais' => ['cliente_id'],
        'contratos' => ['cliente_id'],
        'contrato_equipamentos' => ['equipamento_id'],
        'intervencoes' => ['equipamento_id'],
        'intervencao_equipamentos' => ['equipamento_id'],
        'fichas_medicao' => ['equipamento_id'],
        'eventos_agenda' => ['cliente_id', 'equipamento_id', 'contrato_id', 'intervencao_id'],
        'despesas' => ['registo_despesa_id'],
        'levantamentos_despesa' => ['registo_despesa_id'],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabela => $colunas) {
            foreach ($colunas as $coluna) {
                if (Schema::hasColumn($tabela, $coluna) && ! Schema::hasIndex($tabela, [$coluna])) {
                    Schema::table($tabela, fn (Blueprint $t) => $t->index($coluna));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabela => $colunas) {
            foreach ($colunas as $coluna) {
                if (Schema::hasIndex($tabela, [$coluna])) {
                    Schema::table($tabela, fn (Blueprint $t) => $t->dropIndex([$coluna]));
                }
            }
        }
    }
};
