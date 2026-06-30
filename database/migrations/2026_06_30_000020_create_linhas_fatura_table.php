<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Linhas de faturação espelhadas do ERP PHC (tabela `fi`, data via `ft` por `ftstamp`).
// Só linhas com número de série (equipamentos físicos). Read-only na app, correlação
// por id_erp = fi.fistamp (CLAUDE.md §2/§5). `series` é a chave de cruzamento com equipamentos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linhas_fatura', function (Blueprint $table) {
            $table->id();
            $table->string('id_erp', 50)->unique();          // PHC fi.fistamp (ex.: "NV25040462485,9390000-4")
            $table->string('nmdoc', 60)->nullable();          // PHC fi.nmdoc (tipo de documento)
            $table->integer('fno')->nullable();               // PHC fi.fno (nº da fatura)
            $table->date('data')->nullable();                 // PHC ft.fdata (via ftstamp)
            $table->string('ref', 100)->nullable();           // PHC fi.ref (referência do artigo)
            $table->string('design', 255)->nullable();        // PHC fi.design (descrição da linha)
            $table->string('series', 255)->nullable();        // PHC fi.series (nº(s) de série)
            $table->decimal('qtt', 14, 3)->nullable();        // PHC fi.qtt (quantidade)
            $table->timestamp('synced_at')->nullable();       // momento do último sync
            $table->timestamps();

            $table->index('series'); // cruzamento com equipamentos por número de série
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linhas_fatura');
    }
};
