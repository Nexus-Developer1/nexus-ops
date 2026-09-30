<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Imagem que vai parar a um PDF (fotos dos relatórios, recibos e talões das despesas). O gerador
 * de PDF mete os JPEG tal como estão, mas DESCODIFICA píxel a píxel os outros formatos (PNG, GIF,
 * WEBP, BMP) — cerca de 8 bytes por píxel. Um PNG de 12000×12000 com poucas centenas de KB (uma
 * «bomba de descompressão») rebentava a geração do PDF, e no worker das filas, que corre sem
 * limite de memória, podia comer a memória do servidor (27.ª revisão de segurança). Por isso,
 * fora do JPEG, no máximo MAX_PIXEIS. Câmara e scanner geram JPEG: no dia a dia não muda nada.
 */
class ImagemParaPdf implements ValidationRule
{
    public const MAX_PIXEIS = 25_000_000; // 25 megapíxeis (uma captura de ecrã tem 2 a 8)

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_object($value) || ! method_exists($value, 'getRealPath')) {
            return; // não é um ficheiro — as outras regras tratam disso
        }

        $medidas = @getimagesize((string) $value->getRealPath());
        if (! $medidas || ($medidas[2] ?? null) === IMAGETYPE_JPEG) {
            return;
        }

        if ((int) $medidas[0] * (int) $medidas[1] > self::MAX_PIXEIS) {
            $fail('Esta imagem é grande demais para o relatório ('.$medidas[0].'×'.$medidas[1].'). Tire a fotografia ou digitalize — ou guarde-a em JPEG.');
        }
    }
}
