<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Processo de validação das despesas: cada REGISTO nasce "pendente", é aprovado/rejeitado por
// um aprovador (config despesas.aprovadores) e a decisão fica registada (quem, quando, motivo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registos_despesa', function (Blueprint $table) {
            $table->string('estado', 20)->default('pendente')->index(); // pendente | aprovada | rejeitada
            $table->timestamp('submetido_em')->nullable();
            $table->foreignId('decidido_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->text('motivo_rejeicao')->nullable();
        });

        // Registos anteriores ao processo: ficam pendentes (o aprovador decide), submetidos na
        // data em que foram criados.
        DB::table('registos_despesa')->whereNull('submetido_em')->update(['submetido_em' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('registos_despesa', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decidido_por');
            $table->dropColumn(['estado', 'submetido_em', 'decidido_em', 'motivo_rejeicao']);
        });
    }
};
