<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabela de auditoria (CLAUDE.md §4/§11 — prevista desde o início, vivia só no laravel.log,
// que roda e não se consulta sem SSH). APPEND-ONLY por convenção: a app só insere (via
// Auditor::registar); não existe caminho de update/delete. O email é um snapshot — o registo
// sobrevive à remoção do utilizador.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->string('email')->nullable();          // snapshot de quem fez (null = sistema/anónimo)
            $table->string('acao')->index();              // ex.: login_falhado, relatorio_reaberto
            $table->string('entidade_tipo')->nullable();  // ex.: relatorio, equipamento
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->jsonb('detalhe')->nullable();         // contexto (de/para, destinatários, resumo)
            $table->string('ip', 45)->nullable();
            $table->timestamp('criado_em')->useCurrent()->index();

            $table->index(['entidade_tipo', 'entidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
