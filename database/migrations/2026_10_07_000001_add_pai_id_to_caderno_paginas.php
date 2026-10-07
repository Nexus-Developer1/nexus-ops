<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Caderno: SUBPÁGINAS (como no OneNote) — uma página pode ficar debaixo de outra do mesmo
// separador. Um só nível. Se a página de cima for apagada de vez, as de baixo sobem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caderno_paginas', function (Blueprint $table) {
            $table->foreignId('pai_id')->nullable()->after('separador_id')->constrained('caderno_paginas')->nullOnDelete();
            $table->index('pai_id');
        });
    }

    public function down(): void
    {
        Schema::table('caderno_paginas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pai_id');
        });
    }
};
