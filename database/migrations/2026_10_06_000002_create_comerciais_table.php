<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comerciais a avisar quando um serviço pode ser faturado (out. 2026). Não há na aplicação uma
// lista de comerciais com email — dos clientes só vem do PHC o CÓDIGO do vendedor (cl.vendedor).
// Esta lista APRENDE-SE: cada email usado no envio de um relatório fica aqui (sugestão na
// próxima vez) e ligado ao vendedor do cliente, para o próximo relatório de um cliente desse
// vendedor já vir com o email do comercial preenchido. Sem configuração e sem dados novos do PHC.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comerciais', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();              // sempre em minúsculas
            $table->integer('vendedor_phc')->nullable()->index(); // código do vendedor do último cliente
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comerciais');
    }
};
