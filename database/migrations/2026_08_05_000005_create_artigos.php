<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Catálogo de artigos do PHC (tabela st), sincronizado read-only como clientes/equipamentos.
// Serve a pesquisa por referência ao compor os componentes de um sistema — a pesquisa é
// sempre contra esta tabela local (o ERP nunca está no caminho de um pedido, CLAUDE.md §5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artigos', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->string('id_erp')->unique();      // PHC st.ref (chave de correlação do upsert)
            $tabela->string('designacao')->nullable(); // PHC st.design
            $tabela->string('familia')->nullable();    // PHC st.familia
            $tabela->string('faminome')->nullable();   // PHC st.faminome
            $tabela->string('hash_sync', 32)->nullable(); // incremental: hash da última corrida
            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artigos');
    }
};
