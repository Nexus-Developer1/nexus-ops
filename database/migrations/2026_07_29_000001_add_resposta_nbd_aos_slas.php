<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SLA com resposta NBD (Next Business Day): alternativa às horas no tempo de resposta —
// prática comum em contratos de manutenção. Quando true, tempo_resposta_horas fica null
// (mutuamente exclusivos); a resolução continua em horas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_slas', fn (Blueprint $t) => $t->boolean('resposta_nbd')->default(false));
    }

    public function down(): void
    {
        Schema::table('contrato_slas', fn (Blueprint $t) => $t->dropColumn('resposta_nbd'));
    }
};
