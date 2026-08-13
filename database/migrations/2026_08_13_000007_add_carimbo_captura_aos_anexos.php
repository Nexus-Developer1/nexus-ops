<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vaga 2 (prova de presença): a compressão de fotos no browser destrói o EXIF — o carimbo
// de captura (instante original) e a geolocalização (com o consentimento nativo do browser)
// passam a viajar como metadados ao lado do upload e ficam no anexo. Best-effort e
// declarados pelo cliente: valor probatório de contexto, não criptográfico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anexos', function (Blueprint $table) {
            $table->timestamp('capturada_em')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('anexos', function (Blueprint $table) {
            $table->dropColumn(['capturada_em', 'latitude', 'longitude']);
        });
    }
};
