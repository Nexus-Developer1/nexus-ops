<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Assunto de evento próprio (lookup extensível pela app). Apenas sugestões —
// o evento guarda o texto em eventos_agenda.titulo (sem FK).
class AssuntoEvento extends Model
{
    protected $table = 'assuntos_evento';

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
        static::saving(function (self $assunto) {
            $assunto->nome_normalizado = static::normalizar((string) $assunto->nome);
        });
    }
}
