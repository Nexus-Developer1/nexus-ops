<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Associação equipamento→equipamento (auto-referência): liga um banco de baterias (ou kit)
// ao UPS a que pertence. A associação é feita na app (não vem do ERP), pesquisando o banco
// por nº de série ou pelo local. nullOnDelete: apagar o UPS não apaga o banco, só o solta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->foreignId('equipamento_pai_id')->nullable()->after('local_id')
                ->constrained('equipamentos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipamento_pai_id');
        });
    }
};
