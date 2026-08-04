<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Campos do cabeçalho da folha de despesas da empresa, agora na despesa individual:
// matrícula (veículo usado — relevante em combustíveis/portagens) e departamento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->string('matricula')->nullable();
            $table->string('departamento')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropColumn(['matricula', 'departamento']);
        });
    }
};
