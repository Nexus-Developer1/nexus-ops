<?php

namespace App\Services\Caderno;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

// Limpa o HTML das páginas do caderno ANTES de gravar (e de mostrar). O conteúdo vem do
// browser — um pedido feito à mão pode trazer o que quiser —, por isso fica só o que o editor
// (Trix) produz: blocos, negrito/itálico/riscado, títulos, citações, código, listas, ligações
// http(s)/mailto e IMAGENS/FICHEIROS SÓ DOS ANEXOS desta aplicação (/anexos/{id}). Sem scripts,
// estilos, eventos (onerror…), iframes, nem imagens de fora (que serviam para seguir quem abre a
// página). Os dados de cada anexo que o Trix guarda na <figure> (data-trix-attachment: nome,
// tamanho, tipo, endereço) são refeitos aqui só com os campos conhecidos e endereços /anexos/{id}.
class LimpezaHtmlCaderno
{
    // Teto do HTML de uma página (as imagens não contam — são anexos).
    public const MAX_BYTES = 500_000;

    private ?HtmlSanitizer $sanitizador = null;

    public function limpar(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $limpo = $this->sanitizador()->sanitize($html);

        // Imagens: só as dos anexos desta aplicação (o sanitizador deixa caminhos relativos).
        $limpo = (string) preg_replace('#<img\b(?![^>]*\bsrc="/anexos/\d+")[^>]*>#i', '', $limpo);

        // Ligações relativas: só para os anexos (as de fora são http/https/mailto).
        $limpo = (string) preg_replace('#(<a\b[^>]*?)\s+href="(?!https?:|mailto:|/anexos/\d+")[^"]*"#i', '$1', $limpo);

        // Dados dos anexos na <figure>: refeitos campo a campo (ou retirados).
        $limpo = (string) preg_replace_callback('#\s+data-trix-(attachment|attributes)="([^"]*)"#i',
            fn ($m) => self::dadosTrix(strtolower($m[1]), $m[2]), $limpo);

        return $limpo;
    }

    // data-trix-attachment / data-trix-attributes → JSON só com o que o editor usa.
    private static function dadosTrix(string $qual, string $valor): string
    {
        $dados = json_decode(html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (! is_array($dados)) {
            return '';
        }

        $limpo = [];
        if ($qual === 'attachment') {
            foreach (['href', 'url'] as $k) {
                if (isset($dados[$k])) {
                    if (! is_string($dados[$k]) || ! preg_match('#^/anexos/\d+$#', $dados[$k])) {
                        return ''; // anexo que não é desta aplicação
                    }
                    $limpo[$k] = $dados[$k];
                }
            }
            if ($limpo === []) {
                return '';
            }
            if (is_string($dados['contentType'] ?? null) && preg_match('#^[\w.+-]+/[\w.+-]+$#', $dados['contentType'])) {
                $limpo['contentType'] = $dados['contentType'];
            }
            if (is_string($dados['filename'] ?? null)) {
                $limpo['filename'] = mb_substr($dados['filename'], 0, 200);
            }
            foreach (['filesize', 'width', 'height'] as $k) {
                if (is_int($dados[$k] ?? null) && $dados[$k] >= 0) {
                    $limpo[$k] = $dados[$k];
                }
            }
        } else {
            if (($dados['presentation'] ?? null) === 'gallery') {
                $limpo['presentation'] = 'gallery';
            }
            if (is_string($dados['caption'] ?? null)) {
                $limpo['caption'] = mb_substr($dados['caption'], 0, 500);
            }
            if ($limpo === []) {
                return '';
            }
        }

        return ' data-trix-'.$qual.'="'.htmlspecialchars((string) json_encode($limpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8').'"';
    }

    // Texto simples da página (pesquisa e pré-visualização).
    public static function texto(?string $html): string
    {
        $texto = html_entity_decode(strip_tags(str_replace(['</div>', '<br>', '</p>', '</li>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private function sanitizador(): HtmlSanitizer
    {
        return $this->sanitizador ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowElement('div')
                ->allowElement('p')
                ->allowElement('br')
                ->allowElement('strong')
                ->allowElement('b')
                ->allowElement('em')
                ->allowElement('i')
                ->allowElement('del')
                ->allowElement('s')
                ->allowElement('u')
                ->allowElement('span')
                ->allowElement('h1')
                ->allowElement('h2')
                ->allowElement('h3')
                ->allowElement('blockquote')
                ->allowElement('pre')
                ->allowElement('code')
                ->allowElement('ul')
                ->allowElement('ol')
                ->allowElement('li')
                ->allowElement('figure', ['data-trix-attachment', 'data-trix-attributes'])
                ->allowElement('figcaption')
                ->allowElement('a', ['href'])
                ->allowElement('img', ['src', 'alt', 'width', 'height'])
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks(true) // só /anexos/{id} sobrevive (ver limpar())
                ->allowMediaSchemes([])
                ->allowRelativeMedias(true)
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(self::MAX_BYTES)
        );
    }
}
