<?php

namespace App\Services\Despesas;

use Illuminate\Support\Str;

/**
 * Tira do TEXTO de um talão (lido no telemóvel por OCR — resources/js/ocr-leitor.js) o que o QR
 * code não traz: quem vendeu e onde (→ Descrição), o tipo de despesa (→ Tipo) e a hora (→ almoço
 * ou jantar, nas refeições).
 *
 * São sugestões, não certezas: o OCR engana-se em letras soltas e cada talão tem o cabeçalho à
 * sua maneira. O editor só as põe em campos vazios, e o que a pessoa corrigir fica na memória de
 * fornecedores, que da vez seguinte manda sobre isto.
 *
 * O texto vem do browser: é só texto, cortado a TAMANHO_MAXIMO, e nada daqui é executado ou
 * mostrado sem escape.
 */
class LeitorTalao
{
    public const TAMANHO_MAXIMO = 20000;

    // Palavras que dizem o tipo de despesa, por ordem de prioridade: um posto de combustível
    // também vende cafés, um hotel também serve pequenos-almoços — o combustível e o hotel ganham.
    // Comparam-se sem acentos e em maiúsculas, como palavras inteiras.
    private const TIPOS = [
        'Combustíveis' => 'GASOLEO|GASOLINA|DIESEL|COMBUSTIVE[LI]S?|AD ?BLUE|GPL AUTO|SP ?95|SP ?98|EFITEC',
        'Hotel' => 'HOTEL|ALOJAMENTO|HOSPEDAGEM|DORMIDAS?|ESTADIA|PERNOITAS?|HOSTEL|POUSADA|GUEST ?HOUSE|TAXA TURISTICA',
        'Outros (veículos)' => 'PORTAGEM|PORTAGENS|VIA VERDE|ESTACIONAMENTO|PARQUIMETRO|PARKING|LAVAGEM|PNEUS?|INSPECAO AUTOMOVEL',
        'Táxi / Comboio / Avião' => 'TAXI|UBER|BOLT|COMBOIOS?|FERTAGUS|RYANAIR|EASYJET|AEROPORTO|CARTAO DE EMBARQUE',
        'Refeições' => 'RESTAURANTE|RESTAURACAO|SNACK[- ]?BAR|CERVEJARIA|MARISQUEIRA|CHURRASQUEIRA|TASCA|PIZZARIA|HAMBURGUERIA|PRATO DO DIA|MENU|REFEICAO|ALMOCO|JANTAR|SOPA|BIFANA|FRANCESINHA|SANDES|TOSTA|SOBREMESA|COUVERT|MEIA DOSE',
    ];

    // Linhas que marcam o fim do cabeçalho (daqui para baixo já não há nome nem morada da loja).
    private const FIM_CABECALHO = '/\b(NIF|N\.?\s?I\.?\s?F|CONTRIBUINTE|FATURA|FACTURA|DESCRI[CÇ][AÃ]O|ARTIGO|QTD)\b/iu';

    // Linhas do cabeçalho que não são o nome da loja.
    private const NAO_E_NOME = '/^(ORIGINAL|DUPLICADO|TRIPLICADO|2\.?\s?VIA|BEM[- ]?VINDO|OBRIGADO|CLIENTE|TAL[AÃ]O|RECIBO|DOCUMENTO|SEGUNDA VIA)\b/iu';

    // Palavras que tiram uma linha de «nome de terra»: são de empresas, contactos ou comércio.
    private const NAO_E_TERRA = '/\b(LDA|L\.DA|S\.?A|UNIPESSOAL|SOCIEDADE|SUPERMERCADOS?|HIPERMERCADO|TELEF|TEL|TLF|TLM|FAX|EMAIL|E-MAIL|WWW|CAPITAL|CONSERVAT[OÓ]RIA|RESTAURANTE|CAF[EÉ]|SNACK|BAR|POSTO|HOTEL|LOJA|OBRIGAD[OA])\b/iu';

    // Começo de uma linha de morada.
    private const MORADA = '/^(R|RUA|AV|AVENIDA|ESTR|ESTRADA|EN|E\.N|LG|LARGO|PRA[CÇ]A|P[CÇ]A|PCT|TRAV|TRAVESSA|ZONA|LUGAR|LG|QTA|QUINTA|ROTUNDA|URB|URBANIZA[CÇ][AÃ]O|ALAMEDA|BAIRRO|PARQUE)\b/iu';

    /**
     * @return array{descricao: ?string, categoria: ?string, hora: ?string}
     */
    public static function ler(string $texto): array
    {
        $texto = mb_substr($texto, 0, self::TAMANHO_MAXIMO);

        return [
            'descricao' => self::descricao($texto),
            'categoria' => self::categoria($texto),
            'hora' => self::hora($texto),
        ];
    }

    /** «Nome - Localidade» a partir do cabeçalho (ou só o nome, se a terra não se perceber). */
    public static function descricao(string $texto): ?string
    {
        $linhas = self::cabecalho($texto);

        $nome = null;
        $iNome = -1;
        foreach ($linhas as $i => $linha) {
            if (self::pareceNome($linha)) {
                $nome = $linha;
                $iNome = $i;
                break;
            }
        }
        if ($nome === null) {
            return null;
        }

        // A terra é a da LOJA, que vem logo a seguir ao nome — não a da sede da empresa, que
        // muitos talões imprimem mais abaixo (no da Mercadona: loja em Moreira, sede em Gaia).
        // Vale a primeira que aparecer: uma linha com código postal, ou uma linha só com um nome
        // de terra logo por baixo de uma linha de morada.
        $terra = null;
        for ($i = $iNome + 1; $i < count($linhas); $i++) {
            $linha = $linhas[$i];
            if (preg_match('/\b\d{4}\s?-\s?\d{3}\s+(\p{L}[\p{L}\s\'.-]*)/u', $linha, $m)) {
                $terra = $m[1];
                break;
            }
            if (self::pareceTerra($linha) && self::pareceMorada($linhas[$i - 1])) {
                $terra = $linha;
                break;
            }
        }

        $nome = self::titulo($nome);
        if ($terra !== null) {
            // «VILA NOVA DE GAIA - PORTO»: fica a terra, sem o distrito.
            $terra = self::titulo(trim(preg_split('/\s+-\s+|,/u', $terra)[0]));
            if ($terra !== '' && ! Str::contains(Str::lower($nome), Str::lower($terra))) {
                $nome .= ' - '.$terra;
            }
        }

        return Str::limit($nome, 120, '');
    }

    public static function categoria(string $texto): ?string
    {
        $maiusculas = Str::upper(Str::ascii($texto));
        foreach (self::TIPOS as $tipo => $palavras) {
            if (preg_match('/\b('.$palavras.')\b/', $maiusculas)) {
                return $tipo;
            }
        }

        return null;
    }

    /** Hora da emissão (HH:MM). Prefere a linha da «emissão»/«hora»; senão, a primeira hora. */
    public static function hora(string $texto): ?string
    {
        // O OCR às vezes lê os dois pontos como «;» ou «.».
        $padrao = '/(?<![\d:])([01]?\d|2[0-3])\s?[:;h]\s?([0-5]\d)(?::[0-5]\d)?(?![\d])/u';
        $primeira = null;
        foreach (preg_split('/\R/u', $texto) as $linha) {
            if (preg_match($padrao, $linha, $m)) {
                $hora = sprintf('%02d:%s', $m[1], $m[2]);
                if (preg_match('/EMISS|HORA/iu', Str::ascii($linha))) {
                    return $hora;
                }
                $primeira ??= $hora;
            }
        }

        return $primeira;
    }

    /** A (almoço) das 11h às 17h, J (jantar) das 18h às 5h; fora disso, não se sabe. */
    public static function refeicao(?string $hora): ?string
    {
        if ($hora === null) {
            return null;
        }
        $h = (int) substr($hora, 0, 2);

        return match (true) {
            $h >= 11 && $h < 17 => 'A',
            $h >= 18 || $h < 5 => 'J',
            default => null,
        };
    }

    /** @return list<string> as linhas do topo do talão, até ao NIF/fatura/artigos (máx. 15). */
    private static function cabecalho(string $texto): array
    {
        $linhas = [];
        foreach (preg_split('/\R/u', $texto) as $linha) {
            $linha = trim(preg_replace('/\s+/u', ' ', $linha));
            if ($linha === '') {
                continue;
            }
            if (preg_match(self::FIM_CABECALHO, $linha) || count($linhas) >= 15) {
                break;
            }
            $linhas[] = $linha;
        }

        return $linhas;
    }

    private static function pareceNome(string $linha): bool
    {
        $letras = preg_match_all('/\p{L}/u', $linha);

        return $letras >= 3
            && $letras / max(1, mb_strlen(str_replace(' ', '', $linha))) >= 0.7
            && ! preg_match(self::NAO_E_NOME, $linha);
    }

    private static function pareceTerra(string $linha): bool
    {
        return preg_match('/^\p{L}[\p{L}\s\'.-]{2,29}$/u', $linha)
            && count(preg_split('/\s+/u', $linha)) <= 4
            && ! preg_match(self::NAO_E_TERRA, $linha)
            && ! preg_match(self::MORADA, $linha);
    }

    private static function pareceMorada(string $linha): bool
    {
        return preg_match('/\d/', $linha) || preg_match(self::MORADA, $linha);
    }

    // «VILA NOVA DE GAIA» → «Vila Nova de Gaia». Siglas sem vogais (BP, CP) ficam em maiúsculas.
    private static function titulo(string $texto): string
    {
        $palavras = preg_split('/\s+/u', trim($texto));
        foreach ($palavras as $i => $p) {
            $semAcento = Str::upper(Str::ascii($p));
            if (mb_strlen($p) <= 4 && ! preg_match('/[AEIOUY]/', $semAcento) && preg_match('/\p{L}/u', $p)) {
                $palavras[$i] = mb_strtoupper($p);
            } elseif ($i > 0 && in_array(mb_strtolower($p), ['de', 'da', 'do', 'das', 'dos', 'e', 'em'], true)) {
                $palavras[$i] = mb_strtolower($p);
            } else {
                $palavras[$i] = mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return implode(' ', $palavras);
    }
}
