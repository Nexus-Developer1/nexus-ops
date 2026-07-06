<?php

namespace App\Support;

// Decide a "faixa" do fluxo de escolha de equipamentos conforme o nº que o cliente/contrato tem,
// para nunca montar centenas/milhares de fichas ou checkboxes (→ 500). Partilhada pelo editor de
// relatórios (individual) e pelo editor de contratos — uma só fonte de verdade para os limites.
//
//   ≤ MAX_ANEXA_AUTO ......... 'auto'     → carrega/anexa todos direto
//   ≤ MAX_LISTA_CHECKBOXES ... 'lista'    → lista de checkboxes (marca/desmarca)
//   acima .................... 'pesquisa' → pesquisa server-side (adiciona um a um)
final class FaixaEquipamentos
{
    public const MAX_ANEXA_AUTO = 10;

    public const MAX_LISTA_CHECKBOXES = 50;

    public static function para(int $total): string
    {
        if ($total <= self::MAX_ANEXA_AUTO) {
            return 'auto';
        }
        if ($total <= self::MAX_LISTA_CHECKBOXES) {
            return 'lista';
        }

        return 'pesquisa';
    }
}
