<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chave de correlação com o ERP (PHC ma.mastamp), para o import de equipamentos
// ser idempotente (upsert por id_erp). Só esquema; população é feita à parte.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->string('id_erp')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->dropUnique(['id_erp']);
            $table->dropColumn('id_erp');
        });
    }
};
