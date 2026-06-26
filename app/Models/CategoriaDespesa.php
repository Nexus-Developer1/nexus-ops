<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Categoria de despesa (lookup extensível pela app — cresce com o uso). O valor é
// guardado como texto em despesas.categoria; esta tabela serve as sugestões.
class CategoriaDespesa extends Model
{
    protected $table = 'categorias_despesa';

    /** @var list<string> */
    protected $fillable = [
        'nome',
        'nome_normalizado',
    ];

    // Forma normalizada para deteção de duplicados (minúsculas, sem acentos, espaços colapsados).
    public static function normalizar(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç'];
        $para = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c'];
        $valor = str_replace($de, $para, $valor);

        return preg_replace('/\s+/', ' ', $valor);
    }

    // Mantém o nome_normalizado sempre coerente com o nome.
    protected static function booted(): void
    {
        static::saving(function (self $categoria) {
            $categoria->nome_normalizado = static::normalizar((string) $categoria->nome);
        });
    }
}
