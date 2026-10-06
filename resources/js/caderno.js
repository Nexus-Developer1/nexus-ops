// CADERNO do cliente (out. 2026) — editor Trix com:
//  · autosave (1,2 s depois de parar de escrever), e grava antes de mudar de página/separador;
//  · imagens e ficheiros (PDF, manuais, Office, zip) colados/arrastados: as imagens grandes são
//    comprimidas no browser; tudo fica como ANEXO da página (object storage) e a página aponta
//    para /anexos/{id} (clicar no ficheiro abre-o noutro separador);
//  · aviso se outra pessoa gravou a mesma página entretanto (versão) — nunca se escreve por cima.
// O Trix só é descarregado na página do caderno (import dinâmico → ficheiro à parte).

// Sem editor aberto, mudar de página é direto; o editor substitui isto enquanto está montado.
const mudarDireto = (seguir) => seguir();
window.cadernoMudar = mudarDireto;

let trixPronto = null;
const carregarTrix = () => {
    trixPronto ??= Promise.all([import('trix'), import('trix/dist/trix.css')]).then(([m]) => {
        const Trix = m.default ?? window.Trix;
        // Barra em português — antes de criar o primeiro editor (a barra lê isto ao nascer).
        Object.assign(Trix.config.lang, {
            attachFiles: 'Anexar imagem ou ficheiro',
            bold: 'Negrito',
            bullets: 'Lista',
            byte: 'Byte',
            bytes: 'Bytes',
            captionPlaceholder: 'Legenda…',
            code: 'Código',
            heading1: 'Título',
            indent: 'Aumentar avanço',
            italic: 'Itálico',
            link: 'Ligação',
            numbers: 'Lista numerada',
            outdent: 'Diminuir avanço',
            quote: 'Citação',
            redo: 'Refazer',
            remove: 'Remover',
            strike: 'Riscado',
            undo: 'Desfazer',
            unlink: 'Tirar ligação',
            url: 'Endereço',
            urlPlaceholder: 'Escreva o endereço…',
        });

        return Trix;
    });

    return trixPronto;
};

// O mesmo que o servidor aceita (Caderno::TIPOS_ANEXO, até 20 MB).
const TIPOS_ANEXO = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
const MAX_ANEXO = 20 * 1024 * 1024;
const extensao = (nome) => (String(nome || '').match(/\.([a-z0-9]+)$/i)?.[1] ?? '').toLowerCase();

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
    window.Alpine.data('cadernoEditor', (paginaId, versaoInicial) => ({
        versao: versaoInicial,
        estado: 'Guardado',
        erro: '',
        conflito: false,
        alteracoes: 0,
        gravadas: 0,
        aGuardar: false,
        pronto: false,
        ficheiros: [],
        editor: null,
        _t: null,
        _fila: Promise.resolve(),

        async init() {
            this._mudar = async (seguir) => { await this.gravarAntesDeSair(); seguir(); };
            window.cadernoMudar = this._mudar;
            this._antesDeSair = (e) => {
                if (this.alteracoes !== this.gravadas) { e.preventDefault(); e.returnValue = ''; }
            };
            window.addEventListener('beforeunload', this._antesDeSair);

            await carregarTrix();

            const ed = document.createElement('trix-editor');
            ed.setAttribute('input', 'caderno-conteudo-' + paginaId);
            ed.className = 'trix-content caderno-editor';
            // Só conta como alteração depois de o editor carregar o conteúdo gravado.
            ed.addEventListener('trix-initialize', () => setTimeout(() => { this.pronto = true; this.listarFicheiros(); }, 0));
            ed.addEventListener('trix-change', () => { this.listarFicheiros(); this.mudou(); });
            ed.addEventListener('trix-file-accept', (e) => {
                // Imagem colada sem nome (captura de ecrã) vem como image/png "image.png".
                const ext = extensao(e.file.name) || (e.file.type.split('/')[1] ?? '');
                if (!TIPOS_ANEXO.includes(ext)) {
                    e.preventDefault();
                    this.erro = 'Esse tipo de ficheiro não entra (só imagens, PDF, Word, Excel, PowerPoint, txt, csv ou zip).';
                } else if (e.file.size > MAX_ANEXO) {
                    e.preventDefault();
                    this.erro = 'O ficheiro tem mais de 20 MB.';
                }
            });
            ed.addEventListener('trix-attachment-add', (e) => this.enviarAnexo(e.attachment));
            // Dentro do editor um clique só seleciona o anexo; duplo clique abre-o noutro
            // separador (PDF no browser; os outros descarregam). Há também a lista por baixo.
            ed.addEventListener('dblclick', (e) => {
                const href = this.hrefDoAnexo(e.target);
                if (href) {
                    e.preventDefault();
                    window.open(href, '_blank', 'noopener');
                }
            });
            this.$refs.lugar.appendChild(ed);
            this.editor = ed;
        },

        // Ficheiros (não imagens) já gravados nesta página — lista de atalhos por baixo do editor.
        listarFicheiros() {
            const doc = this.editor?.editor?.getDocument();
            if (!doc) return;
            this.ficheiros = doc.getAttachments()
                .filter((a) => !a.isPreviewable() && /^\/anexos\/\d+$/.test(a.getHref() || ''))
                .map((a) => ({ href: a.getHref(), nome: a.getFilename() || 'ficheiro', tamanho: a.getFormattedFilesize() }));
        },

        hrefDoAnexo(alvo) {
            const fig = alvo.closest('figure[data-trix-attachment]');
            if (!fig) return null;
            try {
                const href = JSON.parse(fig.dataset.trixAttachment).href;
                return /^\/anexos\/\d+$/.test(href || '') ? href : null;
            } catch (e) {
                return null;
            }
        },

        destroy() {
            if (window.cadernoMudar === this._mudar) window.cadernoMudar = mudarDireto;
            window.removeEventListener('beforeunload', this._antesDeSair);
            clearTimeout(this._t);
        },

        mudou() {
            if (!this.pronto || this.conflito) return;
            this.alteracoes++;
            this.estado = 'Por guardar…';
            clearTimeout(this._t);
            this._t = setTimeout(() => this.gravarJa(), 1200);
        },

        async gravarJa() {
            clearTimeout(this._t);
            if (this.conflito || !this.editor || this.alteracoes === this.gravadas) return;
            if (this.aGuardar) { this._t = setTimeout(() => this.gravarJa(), 400); return; }

            const alvo = this.alteracoes;
            this.aGuardar = true;
            this.estado = 'A guardar…';
            try {
                const r = await this.$wire.guardarConteudo(paginaId, this.editor.value, this.versao);
                if (r && r.ok) {
                    this.versao = r.versao;
                    this.gravadas = alvo;
                    this.erro = '';
                    this.estado = this.alteracoes === this.gravadas ? 'Guardado' : 'Por guardar…';
                    if (this.alteracoes !== this.gravadas) this._t = setTimeout(() => this.gravarJa(), 600);
                } else {
                    this.conflito = true;
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

        // Um ficheiro de cada vez (o upload usa uma só propriedade no componente).
        enviarAnexo(anexo) {
            if (!anexo.file) return; // anexo que já estava na página
            this._fila = this._fila.then(() => this.subir(anexo)).catch(() => {});
        },

        async subir(anexo) {
            const ficheiro = await comprimirImagem(anexo.file);
            await new Promise((resolve) => {
                this.$wire.upload('ficheiro', ficheiro,
                    async () => {
                        try {
                            const r = await this.$wire.guardarAnexo(paginaId);
                            if (r && r.ok) {
                                anexo.setAttributes({ url: r.url, href: r.url });
                                this.erro = '';
                            } else {
                                anexo.remove();
                                this.erro = r?.motivo ?? 'O ficheiro não é válido (imagem, PDF, Office, txt, csv ou zip, até 20 MB).';
                            }
                        } catch (e) {
                            anexo.remove();
                            this.erro = 'O ficheiro não é válido (imagem, PDF, Office, txt, csv ou zip, até 20 MB).';
                        } finally {
                            resolve();
                        }
                    },
                    () => { anexo.remove(); this.erro = 'O ficheiro não subiu — tente outra vez.'; resolve(); },
                    (ev) => anexo.setUploadProgress(ev.detail.progress),
                );
            });
        },
    }));
});
