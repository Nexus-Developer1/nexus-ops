<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Folha MENSAL de despesas por colaborador (espelho da folha Excel da empresa): cabeçalho
// (matrícula, departamento, adiantado) + lançamentos por dia nas colunas fixas. As despesas
// existentes passam a categorias FIXAS (as 6 colunas da folha); as antigas caem em "Outras
// despesas". Despesas avulsas (sem folha) continuam válidas — folha_despesa_id é nullable.
return new class extends Migration
{
    private const COLUNAS = ['Combustíveis', 'Outros (veículos)', 'Hotel', 'Refeições', 'Táxi / Comboio / Avião', 'Outras despesas'];

    public function up(): void
    {
        Schema::create('folhas_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('utilizadores');
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');
            $table->string('matricula')->nullable();
            $table->string('departamento')->nullable();
            $table->decimal('adiantado', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'ano', 'mes']);
        });

        Schema::table('despesas', function (Blueprint $table) {
            $table->foreignId('folha_despesa_id')->nullable()->constrained('folhas_despesa')->nullOnDelete();
        });

        // Categorias passam a ser as colunas fixas da folha; tudo o resto vira "Outras despesas".
        DB::table('despesas')->whereNotIn('categoria', self::COLUNAS)->update(['categoria' => 'Outras despesas']);
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folha_despesa_id');
        });
        Schema::dropIfExists('folhas_despesa');
        // O remap de categorias não é reversível (os nomes originais perderam-se).
    }
};
