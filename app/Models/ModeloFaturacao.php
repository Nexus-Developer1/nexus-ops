<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Modelo de faturação (lookup extensível pela app). Substitui o antigo enum.
class ModeloFaturacao extends Model
{
    protected $table = 'modelos_faturacao';

    /** @var list<string> */
    protected $fillable = [
        'nome',
        'nome_normalizado',
    ];

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'modelo_faturacao_id');
    }

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
        static::saving(function (self $modelo) {
            $modelo->nome_normalizado = static::normalizar((string) $modelo->nome);
        });
    }
}
