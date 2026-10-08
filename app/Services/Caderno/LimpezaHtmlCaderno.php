<?php

namespace App\Services\Caderno;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

// Limpa o HTML das páginas do caderno ANTES de gravar. O conteúdo vem do browser — um pedido
// feito à mão pode trazer o que quiser —, por isso fica só o que o editor (Tiptap) produz:
// parágrafos, títulos, negrito/itálico/sublinhado/riscado, cor do texto e realce, listas e
// listas de tarefas, tabelas, citações, código, linhas, ligações http(s)/mailto e IMAGENS/
// FICHEIROS SÓ DOS ANEXOS desta aplicação (/anexos/{id}). Sem scripts, eventos (onerror…),
// iframes, imagens de fora (que serviam para seguir quem abre a página) nem CSS livre: dos
// estilos só ficam cores, alinhamento e larguras das tabelas, com valores verificados.
// (As páginas gravadas pelo editor anterior, o Trix, são convertidas pelo próprio editor ao
// abrir — caderno-editor.js — e chegam aqui já no formato novo.)
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

        // Caixas das listas de tarefas: só checkbox.
        $limpo = (string) preg_replace('#<input\b(?![^>]*\btype="checkbox")[^>]*>#i', '', $limpo);

        // Estilos: só as propriedades e os valores que o editor usa.
        $limpo = (string) preg_replace_callback('#\s+style="([^"]*)"#i', fn ($m) => self::estilo($m[1]), $limpo);

        // Atributos com valor: cor do realce, largura das colunas, dados do ficheiro.
        $limpo = (string) preg_replace_callback('#\s+(data-color|colwidth|data-tamanho)="([^"]*)"#i', function ($m) {
            $valor = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ok = match (strtolower($m[1])) {
                'data-color' => self::cor($valor),
                'colwidth' => preg_match('/^\d{1,4}(,\d{1,4})*$/', $valor) === 1,
                'data-tamanho' => preg_match('/^\d{1,12}$/', $valor) === 1,
            };

            return $ok ? $m[0] : '';
        }, $limpo);
        $limpo = (string) preg_replace_callback('#\s+data-tipo="([^"]*)"#i',
            fn ($m) => preg_match('#^[\w.+-]+/[\w.+-]+$#', html_entity_decode($m[1])) ? $m[0] : '', $limpo);
        $limpo = (string) preg_replace_callback('#\s+data-(type|checked)="([^"]*)"#i', function ($m) {
            $ok = strtolower($m[1]) === 'type' ? in_array($m[2], ['taskList', 'taskItem'], true) : in_array($m[2], ['true', 'false'], true);

            return $ok ? $m[0] : '';
        }, $limpo);

        // Ficheiro anexado (bloco do editor): só se a ligação for para um anexo desta aplicação.
        return (string) preg_replace('#<div\b(?=[^>]*\bdata-ficheiro)(?![^>]*\bdata-href="/anexos/\d+")[^>]*>.*?</div>#is', '', $limpo);
    }

    // Texto simples da página (pesquisa e pré-visualização).
    public static function texto(?string $html): string
    {
        $html = (string) preg_replace('#</(div|p|li|h[1-6]|td|th|tr|blockquote|pre)>|<br\s*/?>#i', ' ', (string) $html);
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    // Cor aceite: #rgb/#rrggbb(aa) ou rgb()/rgba() com números.
    private static function cor(string $valor): bool
    {
        return preg_match('/^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*){2,3}\))$/i', trim($valor)) === 1;
    }

    // style="…" → só color, background-color, text-align, width e min-width, com valores certos.
    private static function estilo(string $css): string
    {
        $css = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fica = [];
        foreach (explode(';', $css) as $regra) {
            if (! str_contains($regra, ':')) {
                continue;
            }
            [$prop, $valor] = array_map('trim', explode(':', $regra, 2));
            $prop = strtolower($prop);
            $ok = match ($prop) {
                'color', 'background-color' => self::cor($valor) || strtolower($valor) === 'inherit',
                'text-align' => in_array(strtolower($valor), ['left', 'center', 'right', 'justify'], true),
                'width', 'min-width' => preg_match('/^\d{1,5}(\.\d+)?px$/', $valor) === 1,
                default => false,
            };
            if ($ok) {
                $fica[] = $prop.': '.$valor;
            }
        }

        return $fica === [] ? '' : ' style="'.htmlspecialchars(implode('; ', $fica), ENT_QUOTES, 'UTF-8').'"';
    }

    private function sanitizador(): HtmlSanitizer
    {
        if ($this->sanitizador) {
            return $this->sanitizador;
        }

        $config = new HtmlSanitizerConfig;
        foreach (['p', 'br', 'strong', 'em', 's', 'u', 'h1', 'h2', 'h3', 'blockquote', 'pre', 'code',
            'hr', 'tbody', 'tr', 'label', 'colgroup'] as $tag) {
            $config = $config->allowElement($tag);
        }

        $config = $config
            ->allowElement('div', ['data-ficheiro', 'data-href', 'data-nome', 'data-tamanho', 'data-tipo'])
            ->allowElement('span', ['style'])
            ->allowElement('mark', ['style', 'data-color'])
            ->allowElement('ol', ['start'])
            ->allowElement('ul', ['data-type'])
            ->allowElement('li', ['data-type', 'data-checked'])
            ->allowElement('input', ['type', 'checked'])
            ->allowElement('table', ['style'])
            ->allowElement('col', ['style'])
            ->allowElement('th', ['colspan', 'rowspan', 'colwidth', 'style'])
            ->allowElement('td', ['colspan', 'rowspan', 'colwidth', 'style'])
            ->allowElement('a', ['href'])
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(true) // só /anexos/{id} sobrevive (ver limpar())
            ->allowMediaSchemes([])
            ->allowRelativeMedias(true)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(self::MAX_BYTES);

        return $this->sanitizador = new HtmlSanitizer($config);
    }
}
