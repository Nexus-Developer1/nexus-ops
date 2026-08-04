<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Um REGISTO de despesas = o documento (a folha preenchida de uma vez): cabeçalho
// (colaborador, matrícula, departamento) + linhas. Cada célula da grelha continua a ser
// uma linha em `despesas` (mantém os KPIs por categoria), mas a listagem e o PDF passam
// a tratar o registo como UMA só entrada. Despesas existentes ganham um registo próprio.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registos_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores');
            $table->string('matricula')->nullable();
            $table->string('departamento')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('despesas', function (Blueprint $table) {
            $table->foreignId('registo_despesa_id')->nullable()->constrained('registos_despesa')->nullOnDelete();
        });

        // Backfill: cada despesa existente passa a ter o seu registo (cabeçalho copiado).
        foreach (DB::table('despesas')->whereNull('registo_despesa_id')->orderBy('id')->get() as $d) {
            $registoId = DB::table('registos_despesa')->insertGetId([
                'criado_por' => $d->criado_por,
                'matricula' => $d->matricula ?? null,
                'departamento' => $d->departamento ?? null,
                'created_at' => $d->created_at,
                'updated_at' => $d->updated_at,
            ]);
            DB::table('despesas')->where('id', $d->id)->update(['registo_despesa_id' => $registoId]);
        }

        // Recibos anexados diretamente a despesas passam para o registo respetivo.
        foreach (DB::table('anexos')->where('anexavel_type', 'App\\Models\\Despesa')->get() as $a) {
            $registoId = DB::table('despesas')->where('id', $a->anexavel_id)->value('registo_despesa_id');
            if ($registoId) {
                DB::table('anexos')->where('id', $a->id)
                    ->update(['anexavel_type' => 'App\\Models\\RegistoDespesa', 'anexavel_id' => $registoId]);
            }
        }

        // O cabeçalho vive agora no registo — sai das despesas (linhas).
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropColumn(['matricula', 'departamento']);
        });
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->string('matricula')->nullable();
            $table->string('departamento')->nullable();
        });
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registo_despesa_id');
        });
        Schema::dropIfExists('registos_despesa');
    }
};
