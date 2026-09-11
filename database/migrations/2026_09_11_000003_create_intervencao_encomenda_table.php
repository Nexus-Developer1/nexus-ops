<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Intervenção ↔ encomenda de peças (dossiê PHC do tipo 1 — pedido da equipa, set. 2026).
// N:M: uma intervenção pode precisar de mais do que uma encomenda, e a mesma encomenda serve
// muitas vezes duas visitas (a do diagnóstico e a que instala a peça quando chega).
//
// O dossiê é read-only (vem do PHC); a ligação é da aplicação. Se o dossiê sair do sync, a
// ligação cai com ele (cascade) — nunca fica a apontar para nada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervencao_encomenda', function (Blueprint $t) {
            $t->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $t->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $t->timestamps();
            $t->primary(['intervencao_id', 'dossier_id']);
            $t->index('dossier_id'); // "que intervenções usam esta encomenda?" (ficha do dossiê)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervencao_encomenda');
    }
};
