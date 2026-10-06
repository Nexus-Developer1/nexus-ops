<?php

namespace App\Services\Caderno;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

// Limpa o HTML das páginas do caderno ANTES de gravar (e de mostrar). O conteúdo vem do
// browser — um pedido feito à mão pode trazer o que quiser —, por isso fica só o que o editor
// (Trix) produz: blocos, negrito/itálico/riscado, títulos, citações, código, listas, ligações
// http(s)/mailto e IMAGENS SÓ DOS ANEXOS desta aplicação (/anexos/{id}). Sem scripts, estilos,
// eventos (onerror…), iframes, nem imagens de fora (que serviam para seguir quem abre a página).
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

        return $limpo;
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
                ->allowElement('figure')
                ->allowElement('figcaption')
                ->allowElement('a', ['href'])
                ->allowElement('img', ['src', 'alt', 'width', 'height'])
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowRelativeLinks(false)
                ->allowMediaSchemes([])
                ->allowRelativeMedias(true)
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(self::MAX_BYTES)
        );
    }
}
