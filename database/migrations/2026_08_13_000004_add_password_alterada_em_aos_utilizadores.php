<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vaga 1 (segurança): instante da última mudança de password. Sessões autenticadas ANTES
// desta marca são invalidadas pelo VerificaPapel — quem muda a password (ex.: suspeita de
// compromisso) expulsa as sessões antigas, incluindo a de um eventual atacante.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->timestamp('password_alterada_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropColumn('password_alterada_em');
        });
    }
};
