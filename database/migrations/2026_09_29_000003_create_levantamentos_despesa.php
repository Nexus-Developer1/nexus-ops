<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Levantamentos de dinheiro do cartão do técnico (set. 2026): no multibanco, com o cartão da
// empresa, para pagar o que não aceita cartão. Vivem DENTRO do registo de despesas (decisão da
// equipa): o registo mostra o levantado, o gasto em dinheiro (linhas «Dinheiro levantado») e o
// que sobra. O talão do multibanco é obrigatório e fica em `anexos` (polimórfico), como os recibos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levantamentos_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registo_despesa_id')->constrained('registos_despesa')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('valor', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('levantamentos_despesa');
    }
};
