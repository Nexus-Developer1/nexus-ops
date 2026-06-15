<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Observação por item de checklist (preenchida no formulário de relatório).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_itens', function (Blueprint $table) {
            $table->string('observacao')->nullable()->after('concluido');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_itens', function (Blueprint $table) {
            $table->dropColumn('observacao');
        });
    }
};
