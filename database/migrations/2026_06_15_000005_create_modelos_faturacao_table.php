<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Modelos de faturação passam de enum/string para tabela de lookup (extensível).
// A coluna string do contrato é migrada para FK, preservando os contratos existentes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modelos_faturacao', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_normalizado')->unique(); // impede duplicados (sem acentos/maiúsculas)
            $table->timestamps();
        });

        // Seed dos modelos atuais + mapa do valor antigo (enum) -> novo id.
        $mapa = [
            'avenca' => 'Avença',
            'por_visita' => 'Por visita',
            't_m' => 'Tempo & Materiais',
        ];
        $idPorValorAntigo = [];
        foreach ($mapa as $valorAntigo => $nome) {
            $idPorValorAntigo[$valorAntigo] = DB::table('modelos_faturacao')->insertGetId([
                'nome' => $nome,
                'nome_normalizado' => $this->normalizar($nome),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Nova FK no contrato.
        Schema::table('contratos', function (Blueprint $table) {
            $table->foreignId('modelo_faturacao_id')->nullable()->after('tipo')
                ->constrained('modelos_faturacao')->nullOnDelete();
        });

        // Backfill dos contratos existentes (mapeia a string antiga para a FK).
        if (Schema::hasColumn('contratos', 'modelo_faturacao')) {
            foreach ($idPorValorAntigo as $valorAntigo => $id) {
                DB::table('contratos')->where('modelo_faturacao', $valorAntigo)->update(['modelo_faturacao_id' => $id]);
            }

            // Salvaguarda: qualquer valor inesperado fica no 1.º modelo.
            $fallback = reset($idPorValorAntigo);
            DB::table('contratos')->whereNull('modelo_faturacao_id')->update(['modelo_faturacao_id' => $fallback]);

            Schema::table('contratos', function (Blueprint $table) {
                $table->dropColumn('modelo_faturacao');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contratos', 'modelo_faturacao')) {
            Schema::table('contratos', function (Blueprint $table) {
                $table->string('modelo_faturacao')->nullable();
            });
        }

        // Repõe a string a partir da FK (best-effort, por nome normalizado).
        $reverso = [
            $this->normalizar('Avença') => 'avenca',
            $this->normalizar('Por visita') => 'por_visita',
            $this->normalizar('Tempo & Materiais') => 't_m',
        ];
        foreach (DB::table('modelos_faturacao')->get() as $m) {
            $valor = $reverso[$m->nome_normalizado] ?? 'avenca';
            DB::table('contratos')->where('modelo_faturacao_id', $m->id)->update(['modelo_faturacao' => $valor]);
        }

        Schema::table('contratos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modelo_faturacao_id');
        });

        Schema::dropIfExists('modelos_faturacao');
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç'];
        $para = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c'];
        $s = str_replace($de, $para, $s);

        return preg_replace('/\s+/', ' ', $s);
    }
};
