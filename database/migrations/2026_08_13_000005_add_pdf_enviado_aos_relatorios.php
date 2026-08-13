<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDF IMUTÁVEL do envio (Vaga 2): o pdf_path é documento de trabalho (regenerável com o
// template atual); o que o cliente recebeu fica congelado em pdf_enviado_path com hash
// sha256 — prova do que foi emitido, versão a versão (reenvio = versão nova; as antigas
// nunca são tocadas). O portal serve SEMPRE a cópia congelada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorios', function (Blueprint $table) {
            $table->string('pdf_enviado_path')->nullable();
            $table->string('pdf_enviado_sha256', 64)->nullable();
            $table->unsignedSmallInteger('enviado_versao')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('relatorios', function (Blueprint $table) {
            $table->dropColumn(['pdf_enviado_path', 'pdf_enviado_sha256', 'enviado_versao']);
        });
    }
};
