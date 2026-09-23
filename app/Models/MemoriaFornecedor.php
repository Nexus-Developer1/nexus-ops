<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// O que se escolheu da última vez para um talão deste vendedor (ver a migração). Aprende ao
// GUARDAR um registo de despesas — só com linhas cujo QR foi lido nesta edição — e sugere quando
// se junta um recibo novo com o mesmo NIF. Sobrepõe-se ao OCR: foi uma pessoa que o confirmou.
class MemoriaFornecedor extends Model
{
    protected $table = 'memoria_fornecedores';

    protected $fillable = ['nif', 'serie', 'descricao', 'categoria', 'varias_lojas'];

    protected function casts(): array
    {
        return ['varias_lojas' => 'boolean'];
    }

    /** @return array{descricao: ?string, categoria: ?string} */
    public static function sugestao(string $nif, ?string $serie): array
    {
        $registos = static::where('nif', $nif)->whereIn('serie', array_unique(['', (string) $serie]))->get()->keyBy('serie');
        $loja = $serie ? $registos->get($serie) : null;
        $geral = $registos->get('');

        return [
            // A descrição geral do NIF só serve se ele tiver uma loja só — numa cadeia, a terra muda.
            'descricao' => $loja?->descricao ?? ($geral && ! $geral->varias_lojas ? $geral->descricao : null),
            'categoria' => $loja?->categoria ?? $geral?->categoria,
        ];
    }

    public static function aprender(string $nif, ?string $serie, string $descricao, string $categoria): void
    {
        if ($serie) {
            static::updateOrCreate(['nif' => $nif, 'serie' => $serie], ['descricao' => $descricao, 'categoria' => $categoria]);
        }

        // Outra série do mesmo NIF com outra descrição = outra loja da mesma empresa. (Corrigir a
        // descrição da MESMA loja não conta.)
        $outraLoja = static::where('nif', $nif)
            ->where('serie', '!=', '')
            ->when($serie, fn ($q) => $q->where('serie', '!=', $serie))
            ->whereNotNull('descricao')
            ->whereRaw('lower(descricao) != ?', [mb_strtolower($descricao)])
            ->exists();

        $geral = static::firstOrNew(['nif' => $nif, 'serie' => '']);
        if ($outraLoja) {
            $geral->varias_lojas = true;
        }
        $geral->fill(['descricao' => $descricao, 'categoria' => $categoria])->save();
    }
}
