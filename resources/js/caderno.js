// CADERNO do cliente (out. 2026) — editor Trix com:
//  · autosave (1,2 s depois de parar de escrever), e grava antes de mudar de página/separador;
//  · imagens coladas/arrastadas: comprimidas no browser se forem grandes e guardadas como
//    ANEXOS da página (object storage); o <img> aponta para /anexos/{id};
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
            attachFiles: 'Inserir imagem',
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

// Fotos grandes (telemóvel: 3–6 MB) → 1920px em JPEG. Capturas de ecrã pequenas ficam como
// estão (o texto nelas perdia nitidez em JPEG).
const comprimirImagem = (ficheiro, maxLado = 1920, qualidade = 0.85) => new Promise((resolve) => {
    if (ficheiro.size < 1_500_000) return resolve(ficheiro);
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
            ed.addEventListener('trix-initialize', () => setTimeout(() => { this.pronto = true; }, 0));
            ed.addEventListener('trix-change', () => this.mudou());
            ed.addEventListener('trix-file-accept', (e) => {
                if (!e.file.type.startsWith('image/')) {
                    e.preventDefault();
                    this.erro = 'Nas páginas só entram imagens.';
                }
            });
            ed.addEventListener('trix-attachment-add', (e) => this.enviarImagem(e.attachment));
            this.$refs.lugar.appendChild(ed);
            this.editor = ed;
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

        // Uma imagem de cada vez (o upload usa uma só propriedade no componente).
        enviarImagem(anexo) {
            if (!anexo.file) return; // imagem que já estava na página
            this._fila = this._fila.then(() => this.subir(anexo)).catch(() => {});
        },

        async subir(anexo) {
            const ficheiro = await comprimirImagem(anexo.file);
            await new Promise((resolve) => {
                this.$wire.upload('imagem', ficheiro,
                    async () => {
                        try {
                            const r = await this.$wire.guardarImagem(paginaId);
                            if (r && r.ok) {
                                anexo.setAttributes({ url: r.url, href: r.url });
                            } else {
                                anexo.remove();
                                this.erro = r?.motivo ?? 'A imagem não é válida (JPEG, PNG, GIF ou WebP, até 20 MB).';
                            }
                        } finally {
                            resolve();
                        }
                    },
                    () => { anexo.remove(); this.erro = 'A imagem não subiu — tente outra vez.'; resolve(); },
                    (ev) => anexo.setUploadProgress(ev.detail.progress),
                );
            });
        },
    }));
});
