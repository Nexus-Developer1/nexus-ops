<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Soft delete nos relatórios: eliminar marca deleted_at (recuperável), mantendo
// o registo (e o número ocupado) na BD. Nunca DELETE físico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorios', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('relatorios', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
