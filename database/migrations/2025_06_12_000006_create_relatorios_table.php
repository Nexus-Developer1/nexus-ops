<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Relatórios, anexos (polimórfico) e itens de checklist.
return new class extends Migration
{
    public function up(): void
    {
        // Relatório gerado de uma intervenção.
        Schema::create('relatorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->string('numero')->unique();        // ex.: 2026/0042
            $table->date('data');
            $table->string('estado')->default('rascunho'); // rascunho | finalizado | enviado
            $table->string('pdf_path')->nullable();     // storage_key no object storage
            $table->timestamp('enviado_em')->nullable();
            $table->string('enviado_para')->nullable(); // email do destinatário
            $table->timestamps();
        });

        // Anexos (fotos/documentos) — polimórfico. NUNCA o blob na BD (só metadados).
        Schema::create('anexos', function (Blueprint $table) {
            $table->id();
            $table->morphs('anexavel'); // anexavel_type, anexavel_id
            $table->string('nome_ficheiro');
            $table->string('storage_key');   // chave no object storage (MinIO/S3)
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestamps();
        });

        // Itens de checklist de uma intervenção (checklist simples).
        Schema::create('checklist_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->string('descricao');
            $table->boolean('concluido')->default(false);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_itens');
        Schema::dropIfExists('anexos');
        Schema::dropIfExists('relatorios');
    }
};
