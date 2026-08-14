<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dossiês espelhados do ERP PHC (tabela `bo`) — tipos 1 (Encomenda Peças), 3 (Proposta) e
// 7 (Encomenda Produção). Read-only na app, correlação por id_erp = bo.bostamp (CLAUDE.md
// §2/§5). O nº de cliente (bo.no) liga a clientes.id_erp; o nome vem denormalizado da própria
// linha (bo.nome), como na faturação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->string('id_erp', 50)->unique();       // PHC bo.bostamp (chave de correlação)
            $table->integer('ndos')->nullable();          // PHC bo.ndos (tipo: 1|3|7)
            $table->string('nmdos', 60)->nullable();      // PHC bo.nmdos (nome do tipo de dossiê)
            $table->integer('obrano')->nullable();        // PHC bo.obrano (nº do dossiê/obra)
            $table->date('data')->nullable();             // PHC bo.dataobra
            $table->integer('ano')->nullable();           // PHC bo.boano
            $table->string('cliente_no', 20)->nullable(); // PHC bo.no = clientes.id_erp
            $table->string('nome', 255)->nullable();      // PHC bo.nome (cliente, denormalizado)
            $table->decimal('total_debito', 14, 2)->nullable(); // PHC bo.etotaldeb
            $table->boolean('fechada')->default(false);   // PHC bo.fechada
            $table->text('u_relat')->nullable();          // PHC bo.u_relat (campo de utilizador)
            $table->string('hash_sync', 32)->nullable();  // impressão digital do sync incremental
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('ndos');        // filtrar por tipo de dossiê
            $table->index('cliente_no');  // dossiês de um cliente
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers');
    }
};
