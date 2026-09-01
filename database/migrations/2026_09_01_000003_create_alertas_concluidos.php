<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Alertas dados como CONCLUÍDOS pela equipa (pedido da equipa): os alertas são calculados a
// cada momento, por isso guarda-se a CHAVE estável de cada um (tipo + entidade + data) e o
// instantâneo do que dizia. Enquanto a chave estiver aqui, o alerta não aparece.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_concluidos', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 120)->unique();
            $table->string('tipo', 40)->index();
            $table->string('titulo');
            $table->string('descricao')->nullable();
            $table->string('url')->nullable();
            $table->foreignId('concluido_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestamp('concluido_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_concluidos');
    }
};
