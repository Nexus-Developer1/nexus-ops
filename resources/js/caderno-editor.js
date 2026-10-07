// CADERNO — o editor (Tiptap/ProseMirror), carregado só na página do caderno (import dinâmico
// em caderno.js → ficheiro à parte). Títulos, tabelas, listas de tarefas, cor e realce, imagens
// e ficheiros anexados (bloco próprio, com cartão clicável). Também lê o HTML do editor
// anterior (Trix): <div> com <br>, e as <figure> das imagens e dos ficheiros.
import { Editor, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Paragraph from '@tiptap/extension-paragraph';
import { Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
import { TaskList, TaskItem } from '@tiptap/extension-list';
import { TextStyle, Color } from '@tiptap/extension-text-style';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import { Placeholder } from '@tiptap/extensions';

const ANEXO = /^\/anexos\/\d+$/;
const BLOCOS = 'p,div,ul,ol,table,h1,h2,h3,h4,blockquote,pre,figure,hr';

// Dados que o Trix guardava em <figure data-trix-attachment="{…}">.
const dadosTrix = (el) => {
    try {
        return JSON.parse(el.getAttribute('data-trix-attachment') || 'null');
    } catch (e) {
        return null;
    }
};

export const tamanhoLegivel = (bytes) => {
    const n = Number(bytes);
    if (!n) return '';
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
    return `${(n / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
};

// Parágrafo: também o <div> de texto do Trix (sem blocos lá dentro).
const Paragrafo = Paragraph.extend({
    parseHTML() {
        return [
            { tag: 'p' },
            { tag: 'div', priority: 20, getAttrs: (el) => (el.hasAttribute('data-ficheiro') || el.querySelector(BLOCOS) ? false : null) },
        ];
    },
});

// Imagem: só as dos anexos; lê também as <figure> do Trix (com ou sem os dados do anexo).
const Imagem = Image.extend({
    parseHTML() {
        return [
            { tag: 'img[src]', getAttrs: (el) => (ANEXO.test(el.getAttribute('src') || '') ? null : false) },
            {
                tag: 'figure',
                priority: 60,
                getAttrs: (el) => {
                    const d = dadosTrix(el);
                    if (d?.contentType && !d.contentType.startsWith('image/')) return false;
                    const src = el.querySelector('img[src]')?.getAttribute('src') || d?.url;
                    return src && ANEXO.test(src) ? { src, alt: d?.filename || null } : false;
                },
            },
        ];
    },
});

// Ficheiro anexado (PDF, manual, Excel…): bloco com nome, tamanho e ligação para o anexo.
const Ficheiro = Node.create({
    name: 'ficheiro',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return { href: { default: null }, nome: { default: 'ficheiro' }, tamanho: { default: null }, tipo: { default: null } };
    },

    parseHTML() {
        return [
            {
                tag: 'div[data-ficheiro]',
                priority: 100,
                getAttrs: (el) => {
                    const href = el.getAttribute('data-href') || '';
                    return ANEXO.test(href) ? {
                        href,
                        nome: el.getAttribute('data-nome') || el.textContent.trim() || 'ficheiro',
                        tamanho: el.getAttribute('data-tamanho'),
                        tipo: el.getAttribute('data-tipo'),
                    } : false;
                },
            },
            {
                tag: 'figure',
                priority: 70,
                getAttrs: (el) => {
                    const d = dadosTrix(el);
                    const href = d?.href || d?.url || '';
                    if (!d?.contentType || d.contentType.startsWith('image/') || !ANEXO.test(href)) return false;
                    return { href, nome: d.filename || 'ficheiro', tamanho: d.filesize ?? null, tipo: d.contentType };
                },
            },
        ];
    },

    renderHTML({ node }) {
        const a = node.attrs;
        return ['div', { 'data-ficheiro': '', 'data-href': a.href, 'data-nome': a.nome, 'data-tamanho': a.tamanho, 'data-tipo': a.tipo },
            ['a', { href: a.href }, a.nome]];
    },

    // No editor: um cartão (ícone, nome, tamanho) com «Abrir» — abre noutro separador.
    addNodeView() {
        return ({ node }) => {
            const a = node.attrs;
            const dom = document.createElement('div');
            dom.className = 'caderno-ficheiro';
            dom.contentEditable = 'false';
            const ext = (String(a.nome).match(/\.([a-z0-9]{1,5})$/i)?.[1] || 'ficheiro').toUpperCase();
            dom.innerHTML = '<span class="caderno-ficheiro-icone"></span><span class="caderno-ficheiro-texto"><span class="caderno-ficheiro-nome"></span><span class="caderno-ficheiro-tamanho"></span></span><a class="caderno-ficheiro-abrir" target="_blank" rel="noopener">Abrir</a>';
            dom.querySelector('.caderno-ficheiro-icone').textContent = ext;
            dom.querySelector('.caderno-ficheiro-nome').textContent = a.nome;
            dom.querySelector('.caderno-ficheiro-tamanho').textContent = tamanhoLegivel(a.tamanho);
            const abrir = dom.querySelector('.caderno-ficheiro-abrir');
            if (ANEXO.test(a.href || '')) abrir.href = a.href; else abrir.remove();
            dom.addEventListener('dblclick', () => { if (ANEXO.test(a.href || '')) window.open(a.href, '_blank', 'noopener'); });
            return { dom };
        };
    },
});

/**
 * Cria o editor. `aoFicheiros(ficheiros, posicao)` é chamado quando se colam/largam ficheiros
 * (a posição do largar, ou null = onde está o cursor); o upload é feito por quem chama.
 */
export function criarEditor({ elemento, conteudo, aoMudar, aoAtualizarBarra, aoFicheiros }) {
    const editor = new Editor({
        element: elemento,
        content: conteudo || '',
        extensions: [
            StarterKit.configure({
                paragraph: false,
                heading: { levels: [1, 2, 3] },
                link: {
                    openOnClick: false,
                    autolink: true,
                    defaultProtocol: 'https',
                    protocols: ['http', 'https', 'mailto'],
                    HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
                },
            }),
            Paragrafo,
            Table.configure({ resizable: true }),
            TableRow,
            TableHeader,
            TableCell,
            TaskList,
            TaskItem.configure({ nested: true }),
            TextStyle,
            Color,
            Highlight.configure({ multicolor: true }),
            Imagem,
            Ficheiro,
            Placeholder.configure({ placeholder: 'Escreva aqui… Cole ou arraste imagens e ficheiros (PDF, manuais).' }),
        ],
        editorProps: {
            attributes: { class: 'caderno-pm', spellcheck: 'true' },
            handlePaste: (view, event) => {
                const ficheiros = [...(event.clipboardData?.files || [])];
                if (ficheiros.length === 0) return false;
                aoFicheiros(ficheiros, null);
                return true;
            },
            handleDrop: (view, event, slice, moved) => {
                const ficheiros = [...(event.dataTransfer?.files || [])];
                if (moved || ficheiros.length === 0) return false;
                const pos = view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos ?? null;
                aoFicheiros(ficheiros, pos);
                return true;
            },
        },
        onUpdate: () => aoMudar(),
        onTransaction: () => aoAtualizarBarra(),
    });

    return editor;
}
