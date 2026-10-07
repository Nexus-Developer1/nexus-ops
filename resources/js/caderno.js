// CADERNO do cliente (out. 2026) — a página aberta:
//  · editor rico (Tiptap — caderno-editor.js, descarregado só aqui): títulos, negrito…, cor e
//    realce, listas e listas de tarefas, tabelas, citações, código, ligações;
//  · autosave (1,2 s depois de parar de escrever), e grava antes de mudar de página/separador;
//  · imagens e ficheiros (PDF, manuais, Office, zip) colados/arrastados/anexados: as imagens
//    grandes são comprimidas no browser; tudo fica como ANEXO da página (object storage);
//  · aviso se outra pessoa gravou a mesma página entretanto (versão) — nunca se escreve por
//    cima; o que se escreveu pode ser copiado antes de recarregar.

// Sem editor aberto, mudar de página é direto; o editor substitui isto enquanto está montado.
const mudarDireto = (seguir) => seguir();
window.cadernoMudar = mudarDireto;

let moduloEditor = null;
const carregarEditor = () => (moduloEditor ??= import('./caderno-editor.js'));

// O mesmo que o servidor aceita (Caderno::TIPOS_ANEXO, até 20 MB).
const TIPOS_ANEXO = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
const MAX_ANEXO = 20 * 1024 * 1024;
const extensao = (nome) => (String(nome || '').match(/\.([a-z0-9]+)$/i)?.[1] ?? '').toLowerCase();

// Paletas da barra (cor do texto e realce).
const CORES_TEXTO = [
    ['Preto', null], ['Cinzento', '#6b7280'], ['Vermelho', '#dc2626'], ['Laranja', '#ea580c'],
    ['Verde', '#16a34a'], ['Azul', '#2563eb'], ['Roxo', '#7c3aed'],
];
const CORES_REALCE = [
    ['Amarelo', '#fef08a'], ['Verde', '#bbf7d0'], ['Azul', '#bfdbfe'], ['Rosa', '#fbcfe8'], ['Laranja', '#fed7aa'],
];

// Fotos grandes (telemóvel: 3–6 MB) → 1920px em JPEG. Capturas de ecrã pequenas ficam como
// estão (o texto nelas perdia nitidez em JPEG).
const comprimirImagem = (ficheiro, maxLado = 1920, qualidade = 0.85) => new Promise((resolve) => {
    if (ficheiro.size < 1_500_000 || !/^image\/(jpeg|png|webp)$/.test(ficheiro.type)) return resolve(ficheiro);
    const url = URL.createObjectURL(ficheiro);
    const img = new Image();
    img.onload = () => {
        URL.revokeObjectURL(url);
        const escala = Math.min(1, maxLado / Math.max(img.width, img.height));
        const tela = document.createElement('canvas');
        tela.width = Math.round(img.width * escala);
        tela.height = Math.round(img.height * escala);
        tela.getContext('2d').drawImage(img, 0, 0, tela.width, tela.height);
        tela.toBlob((blob) => resolve(blob
            ? new File([blob], (ficheiro.name || 'imagem').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })
            : ficheiro), 'image/jpeg', qualidade);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(ficheiro); };
    img.src = url;
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cadernoEditor', (paginaId, versaoInicial) => {
        // O editor fica FORA dos dados do Alpine: o proxy reativo estraga o ProseMirror.
        let editor = null;

        return {
            versao: versaoInicial,
            estado: 'Guardado',
            erro: '',
            conflito: false,
            alteracoes: 0,
            gravadas: 0,
            aGuardar: false,
            pronto: false,
            aEnviar: 0,
            progresso: 0,
            ativo: {},
            bloco: 'p',
            painel: null, // 'cor' | 'realce' | 'ligacao' | null
            ligacao: '',
            coresTexto: CORES_TEXTO,
            coresRealce: CORES_REALCE,
            _t: null,
            _fila: Promise.resolve(),

            async init() {
                this._mudar = async (seguir) => { await this.gravarAntesDeSair(); seguir(); };
                window.cadernoMudar = this._mudar;
                this._antesDeSair = (e) => {
                    if (this.alteracoes !== this.gravadas || this.aEnviar > 0) { e.preventDefault(); e.returnValue = ''; }
                };
                window.addEventListener('beforeunload', this._antesDeSair);

                const { criarEditor } = await carregarEditor();
                const entrada = document.getElementById('caderno-conteudo-' + paginaId);
                editor = criarEditor({
                    elemento: this.$refs.lugar,
                    conteudo: entrada ? entrada.value : '',
                    aoMudar: () => this.mudou(),
                    aoAtualizarBarra: () => this.atualizarBarra(),
                    aoFicheiros: (ficheiros, pos) => this.receberFicheiros(ficheiros, pos),
                });
                this.atualizarBarra();
                // Só conta como alteração depois de carregar o conteúdo gravado.
                setTimeout(() => { this.pronto = true; }, 0);
            },

            destroy() {
                if (window.cadernoMudar === this._mudar) window.cadernoMudar = mudarDireto;
                window.removeEventListener('beforeunload', this._antesDeSair);
                clearTimeout(this._t);
                editor?.destroy();
                editor = null;
            },

            // ---- barra -------------------------------------------------------------------

            atualizarBarra() {
                if (!editor) return;
                const e = editor;
                this.ativo = {
                    bold: e.isActive('bold'), italic: e.isActive('italic'), underline: e.isActive('underline'),
                    strike: e.isActive('strike'), bulletList: e.isActive('bulletList'), orderedList: e.isActive('orderedList'),
                    taskList: e.isActive('taskList'), blockquote: e.isActive('blockquote'), codeBlock: e.isActive('codeBlock'),
                    code: e.isActive('code'), link: e.isActive('link'), tabela: e.isActive('table'),
                    highlight: e.isActive('highlight'), cor: e.getAttributes('textStyle').color || null,
                    desfazer: e.can().undo(), refazer: e.can().redo(),
                };
                this.bloco = [1, 2, 3].find((n) => e.isActive('heading', { level: n }))?.toString() ?? 'p';
            },

            // Corre um comando do Tiptap (ex.: acao('toggleBold')) e volta o foco ao texto.
            acao(nome, ...args) {
                if (!editor || this.conflito) return;
                editor.chain().focus()[nome](...args).run();
                this.painel = null;
            },

            mudarBloco(valor) {
                if (valor === 'p') this.acao('setParagraph');
                else this.acao('toggleHeading', { level: Number(valor) });
            },

            corTexto(cor) {
                cor ? this.acao('setColor', cor) : this.acao('unsetColor');
            },

            realce(cor) {
                cor ? this.acao('setHighlight', { color: cor }) : this.acao('unsetHighlight');
            },

            abrirLigacao() {
                if (!editor) return;
                this.ligacao = editor.getAttributes('link').href || '';
                this.painel = this.painel === 'ligacao' ? null : 'ligacao';
                if (this.painel) this.$nextTick(() => this.$refs.campoLigacao?.focus());
            },

            aplicarLigacao() {
                let url = this.ligacao.trim();
                if (url === '') { this.acao('unsetLink'); return; }
                if (!/^(https?:|mailto:)/i.test(url)) url = (url.includes('@') && !url.includes('/') ? 'mailto:' : 'https://') + url;
                editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
                this.painel = null;
            },

            inserirTabela() {
                this.acao('insertTable', { rows: 3, cols: 3, withHeaderRow: true });
            },

            escolherFicheiros() {
                this.$refs.escolher.click();
            },

            // Texto da página para a área de transferência (no conflito, antes de recarregar).
            async copiarTexto() {
                if (!editor) return;
                try {
                    await navigator.clipboard.writeText(editor.getText({ blockSeparator: '\n' }));
                    this.estado = 'Texto copiado — recarregue e cole onde quiser.';
                } catch (e) {
                    this.estado = 'Não foi possível copiar — selecione o texto e copie à mão.';
                }
            },

            // ---- gravação ----------------------------------------------------------------

            mudou() {
                if (!this.pronto || this.conflito) return;
                this.alteracoes++;
                this.estado = 'Por guardar…';
                clearTimeout(this._t);
                this._t = setTimeout(() => this.gravarJa(), 1200);
            },

            async gravarJa() {
                clearTimeout(this._t);
                if (this.conflito || !editor || this.alteracoes === this.gravadas) return;
                if (this.aGuardar) { this._t = setTimeout(() => this.gravarJa(), 400); return; }

                const alvo = this.alteracoes;
                this.aGuardar = true;
                this.estado = 'A guardar…';
                try {
                    const r = await this.$wire.guardarConteudo(paginaId, editor.getHTML(), this.versao);
                    if (r && r.ok) {
                        this.versao = r.versao;
                        this.gravadas = alvo;
                        this.erro = '';
                        this.estado = this.alteracoes === this.gravadas ? 'Guardado' : 'Por guardar…';
                        if (this.alteracoes !== this.gravadas) this._t = setTimeout(() => this.gravarJa(), 600);
                    } else {
                        this.conflito = true;
                        editor.setEditable(false);
                        this.erro = r?.motivo ?? 'Não foi possível guardar.';
                        this.estado = 'Não guardado';
                    }
                } catch (e) {
                    this.estado = 'Sem ligação — a tentar outra vez…';
                    this._t = setTimeout(() => this.gravarJa(), 5000);
                } finally {
                    this.aGuardar = false;
                }
            },

            // Antes de mudar de página/separador: espera a gravação em curso e grava o que falta.
            async gravarAntesDeSair() {
                for (let i = 0; i < 50 && this.aGuardar; i++) await new Promise((r) => setTimeout(r, 100));
                await this.gravarJa();
            },

            // ---- imagens e ficheiros -----------------------------------------------------

            receberFicheiros(lista, pos) {
                for (const f of lista) {
                    // Imagem colada sem nome (captura de ecrã) vem como image/png "image.png".
                    const ext = extensao(f.name) || (f.type.split('/')[1] ?? '');
                    if (!TIPOS_ANEXO.includes(ext)) {
                        this.erro = `«${f.name || 'ficheiro'}» não entra (só imagens, PDF, Word, Excel, PowerPoint, txt, csv ou zip).`;
                        continue;
                    }
                    if (f.size > MAX_ANEXO) {
                        this.erro = `«${f.name}» tem mais de 20 MB.`;
                        continue;
                    }
                    this.aEnviar++;
                    // Um de cada vez (o upload usa uma só propriedade no componente).
                    const posicao = pos;
                    pos = null; // os seguintes vão para onde o cursor ficar
                    this._fila = this._fila.then(() => this.subir(f, posicao)).catch(() => {});
                }
            },

            async subir(original, pos) {
                const imagem = original.type.startsWith('image/');
                const ficheiro = imagem ? await comprimirImagem(original) : original;
                this.progresso = 0;
                await new Promise((resolve) => {
                    this.$wire.upload('ficheiro', ficheiro,
                        async () => {
                            try {
                                const r = await this.$wire.guardarAnexo(paginaId);
                                if (r && r.ok) {
                                    this.inserirAnexo(r.url, original, imagem, pos);
                                    this.erro = '';
                                } else {
                                    this.erro = r?.motivo ?? `«${original.name}» não é válido (imagem, PDF, Office, txt, csv ou zip, até 20 MB).`;
                                }
                            } catch (e) {
                                this.erro = `«${original.name}» não é válido (imagem, PDF, Office, txt, csv ou zip, até 20 MB).`;
                            } finally {
                                this.aEnviar--;
                                resolve();
                            }
                        },
                        () => { this.aEnviar--; this.erro = `«${original.name}» não subiu — tente outra vez.`; resolve(); },
                        (ev) => { this.progresso = ev.detail.progress; },
                    );
                });
            },

            inserirAnexo(url, original, imagem, pos) {
                if (!editor || this.conflito) return;
                const no = imagem
                    ? { type: 'image', attrs: { src: url, alt: original.name || null } }
                    : { type: 'ficheiro', attrs: { href: url, nome: original.name || 'ficheiro', tamanho: original.size, tipo: original.type || null } };
                const cadeia = editor.chain().focus();
                (pos !== null && pos <= editor.state.doc.content.size ? cadeia.insertContentAt(pos, no) : cadeia.insertContent(no)).run();
            },

            ficheirosEscolhidos(evento) {
                this.receberFicheiros([...evento.target.files], null);
                evento.target.value = '';
            },
        };
    });
});
