<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Memória de fornecedores das despesas (set. 2026): o que se escolheu da última vez para um
// talão deste vendedor — a descrição («Mercadona - Moreira») e o tipo. Chave: o NIF do vendedor
// e a série de faturação, os dois lidos do QR code (a série é de cada loja/terminal). A linha com
// série '' é a do NIF em geral; varias_lojas marca os NIF com lojas diferentes (cadeias), em que
// a descrição geral não serve — só a de cada loja.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memoria_fornecedores', function (Blueprint $table) {
            $table->id();
            $table->string('nif', 9);
            $table->string('serie', 60)->default('');
            $table->string('descricao', 255)->nullable();
            $table->string('categoria', 50)->nullable();
            $table->boolean('varias_lojas')->default(false);
            $table->timestamps();

            $table->unique(['nif', 'serie']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memoria_fornecedores');
    }
};
