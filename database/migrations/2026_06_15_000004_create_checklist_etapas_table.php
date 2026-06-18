<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapas (secções) da checklist de uma intervenção. Cada item passa a pertencer
// a uma etapa; a ordem das etapas e dos itens é persistida para o PDF/detalhe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->string('titulo');
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::table('checklist_itens', function (Blueprint $table) {
            // Etapa a que o item pertence (nullable para compatibilidade com itens antigos).
            $table->foreignId('etapa_id')->nullable()->after('intervencao_id')
                ->constrained('checklist_etapas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('etapa_id');
        });

        Schema::dropIfExists('checklist_etapas');
    }
};
