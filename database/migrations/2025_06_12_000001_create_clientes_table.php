<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Clientes — origem: ERP (read-only na aplicação, correlacionados por id_erp).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('id_erp')->nullable()->unique(); // chave de correlação com o ERP
            $table->string('nome');
            $table->string('nif', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 60)->nullable();
            $table->text('morada')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
