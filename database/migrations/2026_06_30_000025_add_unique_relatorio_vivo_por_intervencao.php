<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Invariante "um relatório VIVO por intervenção", imposto na BD por índice único PARCIAL.
// Parcial (WHERE deleted_at IS NULL) por causa dos soft-deletes: um relatório apagado NÃO
// pode bloquear a criação de um novo para a mesma intervenção. O ->unique() do schema builder
// não faz índices parciais no Postgres — daí o SQL cru.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX relatorios_intervencao_vivo_unique ON relatorios (intervencao_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS relatorios_intervencao_vivo_unique');
    }
};
