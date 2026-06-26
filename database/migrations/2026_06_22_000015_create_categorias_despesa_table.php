<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Categorias de despesa passam de enum fixo para lookup que CRESCE com o uso
// (o utilizador escreve, fica guardado, reaparece nas sugestões). Seed com as 4
// categorias atuais para não perder as opções existentes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_despesa', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_normalizado')->unique(); // impede duplicados (sem acentos/maiúsculas)
            $table->timestamps();
        });

        foreach (['Material', 'Mão de obra', 'Deslocação', 'Outro'] as $nome) {
            DB::table('categorias_despesa')->insert([
                'nome' => $nome,
                'nome_normalizado' => $this->normalizar($nome),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_despesa');
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
