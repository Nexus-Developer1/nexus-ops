<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A DESCRIÇÃO de uma despesa passou a ser o CLIENTE (pedido da equipa, set. 2026), e a loja /
// restaurante que o recibo diz passou para o «o que é». A memória de fornecedores aprendia a
// descrição — o que lá está pode ser o nome de um cliente, que não serve de sugestão para o
// «o que é» de um talão desse fornecedor. Esquece-se o texto (e a marca de cadeia, que dependia
// dele) e fica o TIPO, que continua certo; a loja volta a aprender-se com o uso.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('memoria_fornecedores')->update(['descricao' => null, 'varias_lojas' => false]);
    }

    public function down(): void
    {
        // Acerto de dados: o texto esquecido não se repõe.
    }
};
