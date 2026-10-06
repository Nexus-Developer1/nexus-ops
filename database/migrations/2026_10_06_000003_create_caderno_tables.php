<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CADERNO do cliente (out. 2026) — o equivalente ao OneNote que a equipa usava: cada cliente
// tem SEPARADORES (normalmente um por cliente final) e cada separador tem PÁGINAS ("Equipamento
// 1", "Dados CCTV"…), criadas à medida que fazem falta. O conteúdo da página é HTML rico vindo
// do editor (Trix), sempre limpo no servidor antes de gravar; as imagens são ANEXOS (object
// storage — nunca blobs na BD, CLAUDE.md §2). Só a equipa vê; o portal do cliente nunca.
//
// A ligação ao OneNote da empresa não é possível: a Microsoft deixou de aceitar acesso de
// aplicação (sem utilizador) à API do OneNote — testado a 2026-10-06 (erro 40001).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caderno_separadores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->string('nome', 120);
            $t->string('cor', 20)->default('verde');
            $t->integer('ordem')->default(0);
            $t->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['cliente_id', 'ordem']);
        });

        Schema::create('caderno_paginas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('separador_id')->constrained('caderno_separadores')->cascadeOnDelete();
            $t->string('titulo', 200);
            $t->longText('conteudo')->nullable(); // HTML limpo (ver LimpezaHtmlCaderno)
            // Sobe a cada gravação do conteúdo: duas pessoas na mesma página não se apagam uma à
            // outra — quem grava sobre uma versão que já não é a última é avisado.
            $t->unsignedInteger('versao')->default(0);
            $t->integer('ordem')->default(0);
            $t->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $t->foreignId('atualizado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['separador_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caderno_paginas');
        Schema::dropIfExists('caderno_separadores');
    }
};
