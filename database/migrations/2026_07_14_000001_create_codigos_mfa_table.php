<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Códigos de verificação em duas etapas (MFA por email). Cada linha é um código
// de uso único, com validade curta e limite de tentativas. Guarda-se só o HASH do
// código — nunca o valor em claro. Ver secção 7 (autenticação) do CLAUDE.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigos_mfa', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('user_id')->constrained('utilizadores')->cascadeOnDelete();
            $tabela->string('codigo_hash');
            $tabela->timestamp('expira_em');
            $tabela->unsignedSmallInteger('tentativas')->default(0);
            $tabela->timestamp('usado_em')->nullable();
            $tabela->timestamps();

            // Procura habitual: último código vivo de um utilizador.
            $tabela->index(['user_id', 'usado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_mfa');
    }
};
