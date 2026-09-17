import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptLocale from '@fullcalendar/core/locales/pt';

// Ações que não devem mexer na posição da página (tirar/remover fotos): sem isto, o
// re-render do Livewire — que remove do DOM o botão em foco — devolvia o técnico ao topo,
// obrigando-o a descer outra vez a cada foto. Marca-se antes da ação e repõe-se no fim.
let scrollAPreservar = null;
window.preservarScroll = () => { scrollAPreservar = window.scrollY; };

// Reordenar colunas por arrastar (listagem de Encomendas): tira `mover` da sua posição e
// insere-o na posição de `alvo`, devolvendo a nova ordem. O servidor revalida a whitelist.
window.reordenar = (ordem, mover, alvo) => {
    const nova = [...(ordem || [])];
    const de = nova.indexOf(mover);
    const para = nova.indexOf(alvo);
    if (de === -1 || para === -1) return nova;
    nova.splice(para, 0, nova.splice(de, 1)[0]);
    return nova;
};

// Geolocalização pedida UMA vez por página (prova de presença nas fotos — Vaga 2). O prompt
// do browser é o consentimento; negado/indisponível/timeout → null e as fotos seguem sem geo.
let geoPromessa = null;
const obterGeoUmaVez = () => {
    geoPromessa ??= new Promise((resolve) => {
        if (!navigator.geolocation) return resolve(null);
        const t = setTimeout(() => resolve(null), 4000);
        navigator.geolocation.getCurrentPosition(
            (p) => { clearTimeout(t); resolve({ lat: p.coords.latitude, lng: p.coords.longitude }); },
            () => { clearTimeout(t); resolve(null); },
            { maximumAge: 300000, timeout: 3500 },
        );
    });

    return geoPromessa;
};

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            if (scrollAPreservar === null) return;
            const y = scrollAPreservar;
            scrollAPreservar = null;
            // Depois do morph (2 frames): a altura do bloco de fotos já estabilizou.
            requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo({ top: y })));
        });
    });
});

// Componente Alpine da Agenda (FullCalendar). O $wire vem do componente Livewire
// que envolve este DOM. Eventos e reagendamento passam pelo backend (fonte de verdade).
document.addEventListener('alpine:init', () => {
    // Editor de relatórios — autosave HONESTO com a rede (Vaga 2): o autoGravar falhava em
    // silêncio numa cave sem cobertura e o técnico julgava-se protegido. Agora: falha →
    // badge persistente "sem ligação"; volta a rede → tenta logo; e um ESPELHO do formulário
    // vive em localStorage como rede de segurança contra descarte da tab (Safari/iPad).
    window.Alpine.data('editorRelatorio', () => ({
        tab: 'gerais',
        suja: false,
        semRede: false,

        chave() { return 'nexus-rascunho:' + window.location.pathname; },

        init() {
            const tentar = () => this.$wire.autoGravar()
                .then(() => { this.semRede = false; })
                .catch(() => { this.semRede = true; });
            this.tentar = tentar;

            setInterval(() => { if (this.suja && !document.hidden) tentar(); }, 120000);
            document.addEventListener('visibilitychange', () => { if (this.suja && document.hidden) tentar(); });
            window.addEventListener('online', () => { if (this.suja) tentar(); });
            window.addEventListener('offline', () => { this.semRede = true; });

            // Espelho local de uma sessão anterior (tab descartada antes de gravar)?
            try {
                const bruto = localStorage.getItem(this.chave());
                if (bruto) {
                    const s = JSON.parse(bruto);
                    const quando = new Date(s.ts).toLocaleString('pt-PT');
                    if (confirm('Há alterações não gravadas neste dispositivo (' + quando + ').\n\nRestaurá-las para o formulário?')) {
                        ['fichas', 'resumo', 'data', 'data_fim', 'hora_inicio', 'hora_fim'].forEach((c) => {
                            if (s.dados && s.dados[c] !== undefined) this.$wire.set(c, s.dados[c], false);
                        });
                        this.suja = true;
                        tentar();
                    } else {
                        localStorage.removeItem(this.chave());
                    }
                }
            } catch (e) { /* localStorage indisponível — segue sem espelho */ }
        },

        // Espelho local do formulário — SEM as assinaturas (data URIs pesados e sensíveis:
        // prova de conclusão que não deve ficar no localStorage de um tablet partilhado; já
        // são gravadas depressa no servidor pelo Bloquear). 19.ª revisão de segurança.
        marcarSuja() {
            this.suja = true;
            clearTimeout(this._espelhoT);
            this._espelhoT = setTimeout(() => {
                try {
                    const fichasSemAssinatura = {};
                    for (const [id, f] of Object.entries(this.$wire.fichas ?? {})) {
                        const { assinatura_cliente, assinatura_tecnico, ...resto } = f ?? {};
                        fichasSemAssinatura[id] = resto;
                    }
                    localStorage.setItem(this.chave(), JSON.stringify({ ts: Date.now(), dados: {
                        fichas: fichasSemAssinatura, resumo: this.$wire.resumo, data: this.$wire.data,
                        data_fim: this.$wire.data_fim, hora_inicio: this.$wire.hora_inicio, hora_fim: this.$wire.hora_fim,
                    } }));
                } catch (e) { /* quota/privado — o autosave continua a ser a proteção principal */ }
            }, 2000);
        },

        // Gravação confirmada (auto ou manual): limpa o sujo, o badge e o espelho.
        gravado(url) {
            this.suja = false;
            this.semRede = false;
            try { localStorage.removeItem(this.chave()); } catch (e) {}
            if (url) history.replaceState(null, '', url);
        },
    }));

    // Fotos da intervenção: COMPRIME no telemóvel antes de enviar (CLAUDE.md §6). Uma foto
    // de telemóvel tem 3–6 MB; redimensionada a 1920px e recomprimida em JPEG fica em
    // ~300–600 KB — sobe depressa em 4G e deixa de esbarrar nos limites de upload do PHP.
    // O ficheiro comprimido é enviado ao Livewire por uploadMultiple (a propriedade é a
    // mesma do wire:model, por isso o hook updatedFotos continua a acumular as seleções).
    window.Alpine.data('fotosUpload', (campo) => ({
        MAX_LADO: 1920,
        QUALIDADE: 0.82,

        async escolher(evento) {
            const ficheiros = Array.from(evento.target.files || []);
            evento.target.value = ''; // permite escolher/tirar a MESMA foto outra vez
            if (!ficheiros.length) return;

            window.preservarScroll(); // fica onde está: dá para tirar várias seguidas

            // Carimbo de captura (Vaga 2): a compressão destrói o EXIF — o instante original
            // (lastModified) e a geolocalização (consentimento = o prompt nativo do browser;
            // negado → segue sem) viajam como metadados ao lado do upload.
            const geo = await obterGeoUmaVez();
            const equipId = campo.split('.')[1] ?? null;

            const prontos = [];
            const metas = [];
            for (const f of ficheiros) {
                const pronto = f.type.startsWith('image/') ? await this.comprimir(f) : f;
                prontos.push(pronto);
                metas.push({
                    nome: pronto.name,
                    capturada_em: f.lastModified ? new Date(f.lastModified).toISOString() : null,
                    latitude: geo?.lat ?? null,
                    longitude: geo?.lng ?? null,
                });
            }

            if (equipId) {
                const atuais = (this.$wire.fotosMeta ?? {})[equipId] ?? [];
                this.$wire.set('fotosMeta.' + equipId, [...atuais, ...metas], false);
            }

            this.$wire.uploadMultiple(campo, prontos);
        },

        comprimir(ficheiro) {
            return new Promise((resolve) => {
                const url = URL.createObjectURL(ficheiro);
                const img = new Image();

                img.onload = () => {
                    URL.revokeObjectURL(url);
                    const escala = Math.min(1, this.MAX_LADO / Math.max(img.width, img.height));
                    // Já pequena: envia como está (não vale a pena recomprimir e perder nitidez).
                    if (escala === 1 && ficheiro.size <= 1.5 * 1024 * 1024) return resolve(ficheiro);

                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(img.width * escala);
                    canvas.height = Math.round(img.height * escala);
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

                    canvas.toBlob((blob) => {
                        if (!blob || blob.size >= ficheiro.size) return resolve(ficheiro);
                        const nome = ficheiro.name.replace(/\.[^.]+$/, '') + '.jpg';
                        // lastModified ORIGINAL preservado (Vaga 2): é o carimbo de captura.
                        resolve(new File([blob], nome, { type: 'image/jpeg', lastModified: ficheiro.lastModified }));
                    }, 'image/jpeg', this.QUALIDADE);
                };

                // Formato que o browser não abre (HEIC antigo, etc.) → envia o original.
                img.onerror = () => { URL.revokeObjectURL(url); resolve(ficheiro); };
                img.src = url;
            });
        },
    }));

    // Assinatura desenhada (iPad + Apple Pencil, dedo ou rato). Captura por pointer events
    // num canvas e envia o PNG ao Livewire ao levantar a caneta. O canvas é redimensionado
    // ao pixel-ratio do ecrã para o traço não sair serrilhado no PDF.
    window.Alpine.data('assinaturaPad', (campo, jaGravada) => ({
        temTraco: false,
        desenhando: false,
        bloqueada: false, // bloqueio pós-assinatura: ignora traços e o Limpar (anti-riscos acidentais)
        apagada: false,   // o Limpar escondeu a assinatura GRAVADA — reabre o retângulo para re-assinar
        gravou: false,    // o Bloquear já gravou nesta sessão (o jaGravada do arranque ficou desatualizado)
        ctx: null,

        init() {
            const pad = this.$refs.pad;
            const ratio = window.devicePixelRatio || 1;
            const ajustar = () => {
                const r = pad.getBoundingClientRect();
                if (!r.width) return;
                // Redimensionar o canvas LIMPA-O: rodar o iPad a meio apagava o traço visível
                // (a assinatura "desaparecia" sem explicação). Preserva-se o bitmap e
                // redesenha-se à nova dimensão.
                const copia = this.temTraco ? pad.toDataURL('image/png') : null;
                pad.width = r.width * ratio;
                pad.height = r.height * ratio;
                this.ctx = pad.getContext('2d');
                this.ctx.scale(ratio, ratio);
                this.ctx.lineWidth = 2;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                this.ctx.strokeStyle = '#111827';
                if (copia) {
                    const img = new Image();
                    img.onload = () => this.ctx.drawImage(img, 0, 0, r.width, r.height);
                    img.src = copia;
                }
            };
            ajustar();
            window.addEventListener('resize', ajustar);

            const pos = (e) => {
                const r = pad.getBoundingClientRect();
                return [e.clientX - r.left, e.clientY - r.top];
            };

            pad.addEventListener('pointerdown', (e) => {
                if (this.bloqueada) return;
                if (!this.ctx) ajustar();
                pad.setPointerCapture(e.pointerId);
                this.desenhando = true;
                this.temTraco = true;
                const [x, y] = pos(e);
                this.ctx.beginPath();
                this.ctx.moveTo(x, y);
                e.preventDefault();
            });

            pad.addEventListener('pointermove', (e) => {
                if (!this.desenhando) return;
                const [x, y] = pos(e);
                this.ctx.lineTo(x, y);
                this.ctx.stroke();
                e.preventDefault();
            });

            const terminar = () => {
                if (!this.desenhando) return;
                this.desenhando = false;
                // Só ao levantar a caneta: um PNG por traço seria pesado de mais.
                this.$wire.set(campo, pad.toDataURL('image/png'), false);
            };
            pad.addEventListener('pointerup', terminar);
            pad.addEventListener('pointerleave', terminar);
            pad.addEventListener('pointercancel', terminar);
        },

        alternarBloqueio() {
            this.bloqueada = !this.bloqueada;
            // Bloquear é o "assinatura terminada": grava o rascunho no servidor no mesmo
            // gesto — um refresh (ou o pull-to-refresh do iPad) deixa de poder levar a
            // assinatura. Só quando há traço NOVO: uma já gravada não precisa (e gravar à
            // toa mexia no estado de um relatório finalizado aberto para consulta).
            if (this.bloqueada && this.temTraco) {
                window.preservarScroll();
                this.gravou = true;
                this.$wire.call('guardarRascunho');
            }
        },

        limpar() {
            if (this.bloqueada) return;
            const pad = this.$refs.pad;
            this.ctx?.clearRect(0, 0, pad.width, pad.height);
            this.temTraco = false;
            // Com uma assinatura GRAVADA à vista: esconde-a e reabre o retângulo para
            // assinar de novo (sem isto o Limpar parecia morto — a imagem ficava lá).
            this.apagada = true;
            // 'limpar' diz ao servidor para apagar a gravada na próxima gravação (se
            // existir — sem nada gravado é um no-op). O 'gravou' cobre as gravadas pelo
            // Bloquear nesta sessão. Enquanto não se gravar, um refresh recupera-a.
            this.$wire.set(campo, (jaGravada || this.gravou) ? 'limpar' : '', false);
        },
    }));

    // "Digitalizar recibo" (despesas): abre a câmara na própria app (getUserMedia), captura
    // para canvas e aplica um filtro de DOCUMENTO (tons de cinzento + contraste alto — texto
    // escuro, papel claro) antes de enviar ao Livewire. Precisa de HTTPS (produção tem).
    window.Alpine.data('scannerRecibo', () => ({
        aberto: false,
        fase: 'camara', // camara → recorte (arrastar os cantos) → pronto
        stream: null,
        foto: null,     // ImageCapture: fotografia do sensor, quando o browser a dá
        bruta: null,    // fotografia inteira, como saiu da câmara
        quad: null,     // os 4 cantos do papel, em coordenadas da fotografia
        plana: null,    // recorte endireitado SEM filtro (alternar não repete a captura)
        previa: null,   // cópia reduzida da fotografia, para o passo do recorte
        escala: 1,      // fotografia → tela do ecrã, no passo do recorte
        arrastar: -1,   // canto agarrado com o dedo/rato
        aCapturar: false,
        filtro: true,   // filtro de documento ligado por omissão
        erro: '',

        async abrir() {
            this.erro = '';
            this.fase = 'camara';
            this.aberto = true;
            this.plana = null;
            this.bruta = null;
            this.previa = null;
            this.quad = null;
            this.foto = null;
            if (!navigator.mediaDevices?.getUserMedia) {
                this.erro = 'A câmara não está disponível neste dispositivo/navegador.';
                return;
            }
            try {
                // Pede a maior resolução que o aparelho der. A pré-visualização de um
                // telemóvel fica-se muitas vezes pelos 1280×720 e um recibo capturado assim
                // sai pixelizado — o `advanced` é uma escada, o browser fica no primeiro
                // degrau que consegue.
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 3840 },
                        height: { ideal: 2160 },
                        advanced: [{ width: 3840 }, { width: 2560 }, { width: 1920 }],
                    },
                    audio: false,
                });

                // A fotografia do sensor é bastante maior do que o vídeo (onde existe:
                // Chrome/Android). Se não existir, capturamos o frame do vídeo.
                const faixa = this.stream.getVideoTracks()[0];
                if (window.ImageCapture && faixa) {
                    try {
                        this.foto = new ImageCapture(faixa);
                    } catch (e) {
                        this.foto = null;
                    }
                }

                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
            } catch (e) {
                this.erro = 'Não foi possível abrir a câmara (verifica as permissões).';
            }
        },

        async capturar() {
            // A fotografia do sensor demora um instante a chegar: sem tranca, dois toques
            // seguidos no botão lançavam duas capturas ao mesmo tempo.
            if (this.aCapturar) return;
            this.aCapturar = true;
            try {
                await this.capturarAgora();
            } finally {
                this.aCapturar = false;
            }
        },

        async capturarAgora() {
            const video = this.$refs.video;

            // Origem: fotografia do sensor se houver, senão o frame do vídeo. Se a
            // fotografia vier MENOR do que o vídeo (acontece em alguns aparelhos), fica o
            // vídeo — o que se quer é sempre o maior número de píxeis.
            let origem = null, ow = 0, oh = 0;
            if (this.foto) {
                try {
                    const blob = await this.foto.takePhoto();
                    origem = await createImageBitmap(blob, { imageOrientation: 'from-image' });
                    ow = origem.width;
                    oh = origem.height;
                    if (video.videoWidth && ow * oh < video.videoWidth * video.videoHeight) {
                        origem.close?.();
                        origem = null;
                    }
                } catch (e) {
                    origem = null;
                }
            }
            if (!origem) {
                if (!video.videoWidth) return;
                origem = video;
                ow = video.videoWidth;
                oh = video.videoHeight;
            }

            // Frame completo numa tela de trabalho (fora do ecrã).
            const bruta = document.createElement('canvas');
            bruta.width = ow;
            bruta.height = oh;
            bruta.getContext('2d').drawImage(origem, 0, 0, ow, oh);
            origem.close?.();

            // Procura o papel: os 4 cantos, se der; senão a caixa aparada; falhando tudo, a
            // fotografia inteira. Seja como for, é só uma PROPOSTA — o passo seguinte mostra
            // os cantos e deixa arrastá-los.
            const det = this.detetarPapel(bruta);
            const zona = det?.caixa ?? { x: 0, y: 0, w: bruta.width, h: bruta.height };
            this.bruta = bruta;
            this.previa = null;
            this.quad = det?.quad ?? [
                { x: zona.x, y: zona.y },
                { x: zona.x + zona.w, y: zona.y },
                { x: zona.x + zona.w, y: zona.y + zona.h },
                { x: zona.x, y: zona.y + zona.h },
            ];

            this.fase = 'recorte';
            this.pararCamara(); // congela a captura; "Repetir" reabre
            this.$nextTick(() => this.desenharRecorte());
        },

        // ---- passo do recorte: fotografia com os cantos por cima, para os arrastar ----

        desenharRecorte() {
            if (!this.bruta) return;
            const tela = this.$refs.tela;

            // A tela do ecrã não precisa da fotografia inteira: 1000px chegam. A cópia
            // reduzida faz-se UMA vez — arrastar um canto redesenha dezenas de vezes por
            // segundo, e reduzir a fotografia toda a cada movimento engasgava o telemóvel.
            this.escala = Math.min(1, 1000 / Math.max(this.bruta.width, this.bruta.height));
            if (!this.previa) {
                this.previa = document.createElement('canvas');
                this.previa.width = Math.round(this.bruta.width * this.escala);
                this.previa.height = Math.round(this.bruta.height * this.escala);
                this.previa.getContext('2d').drawImage(this.bruta, 0, 0, this.previa.width, this.previa.height);
            }
            tela.width = this.previa.width;
            tela.height = this.previa.height;

            const ctx = tela.getContext('2d');
            ctx.drawImage(this.previa, 0, 0);

            const pontos = this.quad.map((c) => ({ x: c.x * this.escala, y: c.y * this.escala }));

            // Escurecer o que fica de fora do papel.
            ctx.save();
            ctx.beginPath();
            ctx.rect(0, 0, tela.width, tela.height);
            ctx.moveTo(pontos[0].x, pontos[0].y);
            for (let i = 1; i < 4; i++) ctx.lineTo(pontos[i].x, pontos[i].y);
            ctx.closePath();
            ctx.fillStyle = 'rgba(15, 23, 42, 0.55)';
            ctx.fill('evenodd');
            ctx.restore();

            // Contorno e pegas dos cantos.
            const traco = Math.max(2, Math.round(tela.width / 260));
            ctx.strokeStyle = '#22c55e';
            ctx.lineWidth = traco;
            ctx.beginPath();
            ctx.moveTo(pontos[0].x, pontos[0].y);
            for (let i = 1; i < 4; i++) ctx.lineTo(pontos[i].x, pontos[i].y);
            ctx.closePath();
            ctx.stroke();

            const raio = Math.max(9, Math.round(tela.width / 46));
            for (const ponto of pontos) {
                ctx.beginPath();
                ctx.arc(ponto.x, ponto.y, raio, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255, 255, 255, 0.92)';
                ctx.fill();
                ctx.lineWidth = traco;
                ctx.strokeStyle = '#16a34a';
                ctx.stroke();
            }
        },

        // Ponto do ecrã → ponto da fotografia.
        pontoNaFoto(evento) {
            const tela = this.$refs.tela;
            const caixa = tela.getBoundingClientRect();
            const x = ((evento.clientX - caixa.left) * (tela.width / caixa.width)) / this.escala;
            const y = ((evento.clientY - caixa.top) * (tela.height / caixa.height)) / this.escala;

            return { x, y };
        },

        agarrar(evento) {
            if (this.fase !== 'recorte') return;
            const ponto = this.pontoNaFoto(evento);

            // Canto mais perto, desde que o dedo tenha caído razoavelmente em cima dele.
            const alcance = Math.max(this.bruta.width, this.bruta.height) * 0.09;
            let perto = -1, menor = Infinity;
            this.quad.forEach((canto, i) => {
                const d = Math.hypot(canto.x - ponto.x, canto.y - ponto.y);
                if (d < menor) { menor = d; perto = i; }
            });
            if (menor > alcance) return;

            this.arrastar = perto;
            this.$refs.tela.setPointerCapture?.(evento.pointerId);
            evento.preventDefault();
        },

        mover(evento) {
            if (this.arrastar < 0) return;
            const ponto = this.pontoNaFoto(evento);
            this.quad[this.arrastar] = {
                x: Math.max(0, Math.min(this.bruta.width, ponto.x)),
                y: Math.max(0, Math.min(this.bruta.height, ponto.y)),
            };
            this.desenharRecorte();
            evento.preventDefault();
        },

        largar(evento) {
            if (this.arrastar < 0) return;
            this.arrastar = -1;
            this.$refs.tela.releasePointerCapture?.(evento.pointerId);
        },

        // Endireita a folha pelos cantos que ficaram (detetados ou arrastados) e aplica o
        // filtro. Guarda-se o recorte em bruto: alternar o filtro não repete tudo.
        confirmarRecorte() {
            if (!this.bruta || !this.quad) return;
            this.plana = this.corrigirPerspetiva(this.bruta, this.quad);
            this.fase = 'pronto';
            this.aplicar();
        },

        voltarAoRecorte() {
            this.fase = 'recorte';
            this.$nextTick(() => this.desenharRecorte());
        },

        // Recorte à caixa, com tecto de resolução: o recibo tem de sair legível, mas um
        // frame 4K inteiro só engorda o ficheiro.
        recortar(bruta, zona) {
            const esc = Math.min(1, 2400 / Math.max(zona.w, zona.h, 1));
            const saida = document.createElement('canvas');
            saida.width = Math.max(1, Math.round(zona.w * esc));
            saida.height = Math.max(1, Math.round(zona.h * esc));
            const ctx = saida.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(bruta, zona.x, zona.y, zona.w, zona.h, 0, 0, saida.width, saida.height);

            return saida;
        },

        // Põe o recorte na tela visível, com ou sem filtro. Guardar o recorte em bruto é o
        // que permite ligar/desligar o filtro sem voltar a fotografar.
        aplicar() {
            if (!this.plana) return;
            const tela = this.$refs.tela;
            tela.width = this.plana.width;
            tela.height = this.plana.height;
            const ctx = tela.getContext('2d');
            ctx.drawImage(this.plana, 0, 0);
            if (this.filtro) this.filtroDocumento(ctx, tela.width, tela.height);
        },

        alternarFiltro() {
            this.filtro = !this.filtro;
            this.aplicar();
        },

        // A fotografia vem do sensor com a resolução que der; o recorte final fica-se pelos
        // 2400px do lado maior, que é quanto basta para ler um recibo e não atochar o envio.

        // Encontra o papel: análise numa miniatura, ENCHENTE a partir do centro sobre os
        // píxeis claros e, da mancha, tira os 4 CANTOS (para endireitar a perspetiva) e a
        // caixa aparada (fallback). O limiar ancora no BRANCO do próprio papel (mediana da
        // janela central) — um fundo cinzento-claro ou as argolas de um caderno ficam abaixo
        // e já não se colam à mancha. Devolve { quad, caixa } ou null (usa o frame inteiro).
        detetarPapel(bruta) {
            const W = 200;
            const H = Math.max(1, Math.round(bruta.height * (W / bruta.width)));
            const mini = document.createElement('canvas');
            mini.width = W;
            mini.height = H;
            const mctx = mini.getContext('2d');
            mctx.drawImage(bruta, 0, 0, W, H);
            const d = mctx.getImageData(0, 0, W, H).data;

            const cinza = new Uint8Array(W * H);
            const hist = new Uint32Array(256);
            for (let p = 0, i = 0; p < cinza.length; p++, i += 4) {
                const g = (0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) | 0;
                cinza[p] = g;
                hist[g]++;
            }

            // Branco de referência do papel: mediana da janela central (±10%) — o recibo
            // aponta-se ao centro. O limiar é "perto desse branco", nunca abaixo do Otsu.
            const cx = W >> 1, cy = H >> 1;
            const janela = [];
            for (let y = Math.max(0, cy - (H / 10 | 0)); y <= Math.min(H - 1, cy + (H / 10 | 0)); y++) {
                for (let x = Math.max(0, cx - (W / 10 | 0)); x <= Math.min(W - 1, cx + (W / 10 | 0)); x++) {
                    janela.push(cinza[y * W + x]);
                }
            }
            janela.sort((a, b) => a - b);
            const brancoPapel = janela[janela.length >> 1] ?? 255;
            const limiar = Math.max(this.limiarOtsu(hist, W * H), brancoPapel - 45);

            // Semente: o píxel claro mais próximo do centro (anéis a crescer).
            let semente = -1;
            busca: for (let r = 0; r <= Math.min(W, H) >> 1; r += 2) {
                for (let dy = -r; dy <= r; dy += 2) {
                    for (let dx = -r; dx <= r; dx += 2) {
                        const x = cx + dx, y = cy + dy;
                        if (x < 0 || y < 0 || x >= W || y >= H) continue;
                        if (cinza[y * W + x] > limiar) { semente = y * W + x; break busca; }
                    }
                }
            }
            if (semente < 0) return null;

            // Enchente (4 vizinhos) sobre os claros, a acumular a caixa envolvente.
            const visto = new Uint8Array(W * H);
            const fila = [semente];
            visto[semente] = 1;
            let minX = W, maxX = 0, minY = H, maxY = 0, area = 0;
            while (fila.length) {
                const p = fila.pop();
                const x = p % W, y = (p / W) | 0;
                area++;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
                if (x > 0 && !visto[p - 1] && cinza[p - 1] > limiar) { visto[p - 1] = 1; fila.push(p - 1); }
                if (x < W - 1 && !visto[p + 1] && cinza[p + 1] > limiar) { visto[p + 1] = 1; fila.push(p + 1); }
                if (y > 0 && !visto[p - W] && cinza[p - W] > limiar) { visto[p - W] = 1; fila.push(p - W); }
                if (y < H - 1 && !visto[p + W] && cinza[p + W] > limiar) { visto[p + W] = 1; fila.push(p + W); }
            }

            // Nem uma nesga (não há papel) nem quase tudo (a mancha fugiu para a mesa).
            const fracao = area / (W * H);
            if (fracao < 0.08 || fracao > 0.92) return null;

            const porLinha = new Uint32Array(H);
            const porColuna = new Uint32Array(W);
            for (let p = 0; p < visto.length; p++) {
                if (!visto[p]) continue;
                porLinha[(p / W) | 0]++;
                porColuna[p % W]++;
            }

            // Apara a caixa até às MARGENS da folha: filas/colunas de borda que não sejam
            // maioritariamente papel (densidade da mancha < 55%) são cortadas.
            let mudou = true;
            while (mudou && minX < maxX && minY < maxY) {
                mudou = false;
                const largura = maxX - minX + 1;
                const altura = maxY - minY + 1;
                if (porLinha[minY] < 0.55 * largura) { minY++; mudou = true; continue; }
                if (porLinha[maxY] < 0.55 * largura) { maxY--; mudou = true; continue; }
                if (porColuna[minX] < 0.55 * altura) { minX++; mudou = true; continue; }
                if (porColuna[maxX] < 0.55 * altura) { maxX--; mudou = true; }
            }

            // Caixa aparada demasiado pequena → deteção sem préstimo, fica o frame inteiro.
            if (maxX - minX + 1 < 0.15 * W || maxY - minY + 1 < 0.15 * H) return null;

            const ex = bruta.width / W, ey = bruta.height / H;

            // Caixa da miniatura → coordenadas reais, com 1% de margem à volta (fallback).
            const margem = Math.round(0.01 * bruta.width);
            const x0 = Math.max(0, Math.round(minX * ex) - margem);
            const y0 = Math.max(0, Math.round(minY * ey) - margem);
            const x1 = Math.min(bruta.width, Math.round((maxX + 1) * ex) + margem);
            const y1 = Math.min(bruta.height, Math.round((maxY + 1) * ey) + margem);
            const caixa = { x: x0, y: y0, w: Math.max(1, x1 - x0), h: Math.max(1, y1 - y0) };

            const quad = this.quadrilateroDoPapel(cinza, visto, W, H, area, ex, ey, bruta.width, bruta.height);

            return { quad, caixa };
        },

        /**
         * Os quatro cantos do papel, por AJUSTE DE RETAS aos lados.
         *
         * Antes tomavam-se por cantos os pontos mais extremos da mancha. Bastava o brilho de
         * uma lâmpada colado à folha, ou uma dobra, para um desses extremos saltar para fora
         * do papel — e a folha saía torta, com um canto cortado e um bocado de mesa dentro.
         *
         * Agora, de cada lado junta-se a margem da mancha (o ponto mais à esquerda de cada
         * linha, o mais acima de cada coluna, etc.), ajusta-se uma reta que DESPREZA os
         * pontos desgarrados, e os cantos são os cruzamentos dessas quatro retas. Um reflexo
         * mexe com uma minoria das linhas e é posto de lado; o lado continua onde está.
         *
         * As pontas ficam de fora do ajuste (15% de cada lado): é aí que a margem deixa de
         * seguir um lado e passa a seguir o vizinho.
         */
        quadrilateroDoPapel(cinza, visto, W, H, area, ex, ey, larguraReal, alturaReal) {
            let minX = W, maxX = 0, minY = H, maxY = 0;
            for (let p = 0; p < visto.length; p++) {
                if (!visto[p]) continue;
                const x = p % W, y = (p / W) | 0;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }

            const esquerda = [], direita = [], cima = [], baixo = [];
            const recuoY = (maxY - minY) * 0.15, recuoX = (maxX - minX) * 0.15;

            for (let y = Math.ceil(minY + recuoY); y <= Math.floor(maxY - recuoY); y++) {
                let a = -1, b = -1;
                for (let x = 0; x < W; x++) if (visto[y * W + x]) { if (a < 0) a = x; b = x; }
                if (a >= 0) { esquerda.push({ u: y, v: a }); direita.push({ u: y, v: b }); }
            }
            for (let x = Math.ceil(minX + recuoX); x <= Math.floor(maxX - recuoX); x++) {
                let a = -1, b = -1;
                for (let y = 0; y < H; y++) if (visto[y * W + x]) { if (a < 0) a = y; b = y; }
                if (a >= 0) { cima.push({ u: x, v: a }); baixo.push({ u: x, v: b }); }
            }
            if (esquerda.length < 8 || cima.length < 8) return null;

            let rEsq = this.ajustarReta(esquerda), rDir = this.ajustarReta(direita);
            let rCima = this.ajustarReta(cima), rBaixo = this.ajustarReta(baixo);
            if (!rEsq || !rDir || !rCima || !rBaixo) return null;

            // Encostar ao contraste: a mancha decide pelo brilho e erra uns píxeis quando a
            // mesa é quase tão clara como o papel. A margem verdadeira é onde a luz dá o
            // salto — é aí que as retas vão parar.
            rEsq = this.encostarReta(cinza, W, H, rEsq, true, minY, maxY);
            rDir = this.encostarReta(cinza, W, H, rDir, true, minY, maxY);
            rCima = this.encostarReta(cinza, W, H, rCima, false, minX, maxX);
            rBaixo = this.encostarReta(cinza, W, H, rBaixo, false, minX, maxX);

            // Lados: x = a·y + b. Topo e base: y = a·x + b. O cruzamento é o canto.
            const canto = (lateral, horizontal) => {
                const den = 1 - lateral.a * horizontal.a;
                if (Math.abs(den) < 1e-6) return null;
                const x = (lateral.a * horizontal.b + lateral.b) / den;

                return { x, y: horizontal.a * x + horizontal.b };
            };
            const quad = [canto(rEsq, rCima), canto(rDir, rCima), canto(rDir, rBaixo), canto(rEsq, rBaixo)];
            if (quad.some((c) => !c || !Number.isFinite(c.x) || !Number.isFinite(c.y))) return null;

            // Cantos muito fora do frame = folha cortada na fotografia: mais vale não
            // endireitar do que endireitar uma folha que não se vê toda.
            const folga = 0.03;
            if (quad.some((c) => c.x < -folga * W || c.x > W * (1 + folga) || c.y < -folga * H || c.y > H * (1 + folga))) {
                return null;
            }

            // Tem de ser um quadrilátero convexo, de lados com tamanho e cantos em esquadria
            // razoável, e com a área da mancha. Senão, fica o recorte direito pela caixa.
            const comprimento = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
            let sinal = 0;
            for (let i = 0; i < 4; i++) {
                const a = quad[(i + 3) % 4], b = quad[i], c = quad[(i + 1) % 4];
                const v1 = { x: a.x - b.x, y: a.y - b.y }, v2 = { x: c.x - b.x, y: c.y - b.y };
                const n1 = Math.hypot(v1.x, v1.y), n2 = Math.hypot(v2.x, v2.y);
                if (n1 < 1e-6 || n2 < 1e-6) return null;
                const cosseno = (v1.x * v2.x + v1.y * v2.y) / (n1 * n2);
                if (Math.abs(cosseno) > 0.72) return null; // cantos fora de ~44°–136°
                const cruz = v1.x * v2.y - v1.y * v2.x;
                if (!sinal) sinal = Math.sign(cruz);
                else if (Math.sign(cruz) !== sinal) return null; // não é convexo
            }
            if (Math.min(
                comprimento(quad[0], quad[1]), comprimento(quad[1], quad[2]),
                comprimento(quad[2], quad[3]), comprimento(quad[3], quad[0]),
            ) < 0.12 * Math.min(W, H)) return null;

            const areaQuad = Math.abs(
                (quad[0].x * quad[1].y - quad[1].x * quad[0].y) + (quad[1].x * quad[2].y - quad[2].x * quad[1].y) +
                (quad[2].x * quad[3].y - quad[3].x * quad[2].y) + (quad[3].x * quad[0].y - quad[0].x * quad[3].y)
            ) / 2;
            if (areaQuad < 0.7 * area || areaQuad > 1.35 * area) return null;

            // Miniatura → coordenadas reais, com 1% para fora do centro (a margem do papel
            // não deve ser rapada) e presos ao frame.
            const mx = (quad[0].x + quad[1].x + quad[2].x + quad[3].x) / 4;
            const my = (quad[0].y + quad[1].y + quad[2].y + quad[3].y) / 4;

            return quad.map((c) => ({
                x: Math.max(0, Math.min(larguraReal - 1, (c.x + (c.x - mx) * 0.01 + 0.5) * ex)),
                y: Math.max(0, Math.min(alturaReal - 1, (c.y + (c.y - my) * 0.01 + 0.5) * ey)),
            }));
        },

        /**
         * Empurra a reta de um lado para onde está o degrau de luz mais forte.
         *
         * Experimenta deslocá-la para um lado e para o outro (e inclinar um pouco), e fica
         * onde a diferença entre o que está por dentro e o que está por fora é maior — ou
         * seja, em cima da margem do papel. Sem contraste nenhum à volta, fica como estava.
         */
        encostarReta(cinza, W, H, reta, eLateral, u0, u1) {
            const luz = (x, y) => cinza[Math.max(0, Math.min(H - 1, Math.round(y))) * W
                + Math.max(0, Math.min(W - 1, Math.round(x)))];
            const passos = Math.max(12, Math.min(90, Math.round(u1 - u0)));

            // Conta em QUANTOS pontos do lado há mesmo um degrau de luz, sempre no mesmo
            // sentido. Não se usa a média dos degraus: num recibo o texto começa todo na
            // mesma coluna, e a média ia encostar a reta ao texto em vez da margem. Assim
            // não: a margem tem degrau ao longo do lado inteiro, o texto só onde há linhas.
            const pontuar = (a, b) => {
                let claros = 0, escuros = 0;
                for (let i = 0; i <= passos; i++) {
                    const u = u0 + ((u1 - u0) * i) / passos;
                    const v = a * u + b;
                    const degrau = eLateral ? luz(v + 2, u) - luz(v - 2, u) : luz(u, v + 2) - luz(u, v - 2);
                    if (degrau >= 8) claros++;
                    else if (degrau <= -8) escuros++;
                }

                return Math.max(claros, escuros);
            };

            // Só se troca de sítio quando o novo é claramente melhor do que onde já estava.
            let melhor = { a: reta.a, b: reta.b, pontos: pontuar(reta.a, reta.b) * 1.15 + 1 };
            for (let dInclinacao = -0.03; dInclinacao <= 0.031; dInclinacao += 0.01) {
                for (let dLado = -8; dLado <= 8.01; dLado += 0.5) {
                    const a = reta.a + dInclinacao, b = reta.b + dLado;
                    const pontos = pontuar(a, b);
                    if (pontos > melhor.pontos) melhor = { a, b, pontos };
                }
            }

            return { a: melhor.a, b: melhor.b };
        },

        /**
         * Reta v = a·u + b pelos pontos de um lado, imune aos desgarrados.
         *
         * Fica a reta com MAIS pontos em cima dela: duas amostras afastadas definem uma
         * candidata, conta-se quantos pontos lhe ficam a menos de um píxel e meio, e no fim
         * afina-se por mínimos quadrados só com esses.
         *
         * Porquê assim e não a média dos pontos: o brilho de uma lâmpada encostado à folha
         * chega a empurrar MAIS DE METADE dos pontos de um lado, e a média vai atrás dele.
         * A reta com mais pontos alinhados continua a ser a do papel — o contorno do brilho
         * é redondo, não alinha com nada.
         */
        ajustarReta(pontos) {
            const n = pontos.length;
            if (n < 8) return null;

            const vao = Math.abs(pontos[n - 1].u - pontos[0].u);
            const afastamento = Math.max(2, vao * 0.25);
            const tolerancia = 1.5;

            // Sorteio próprio, sempre igual: a mesma fotografia dá sempre o mesmo recorte.
            let semente = 987654321;
            const sorte = () => {
                semente = (semente * 1103515245 + 12345) % 2147483648;

                return semente / 2147483648;
            };

            let melhorA = 0, melhorB = 0, melhorConta = -1;
            for (let tentativa = 0; tentativa < 200; tentativa++) {
                const p1 = pontos[Math.min(n - 1, (sorte() * n) | 0)];
                const p2 = pontos[Math.min(n - 1, (sorte() * n) | 0)];
                if (Math.abs(p1.u - p2.u) < afastamento) continue;
                const a = (p2.v - p1.v) / (p2.u - p1.u);
                const b = p1.v - a * p1.u;
                let conta = 0;
                for (let i = 0; i < n; i++) {
                    if (Math.abs(pontos[i].v - (a * pontos[i].u + b)) <= tolerancia) conta++;
                }
                if (conta > melhorConta) { melhorConta = conta; melhorA = a; melhorB = b; }
            }
            if (melhorConta < Math.max(8, n * 0.25)) return null;

            // Afinação com os pontos que ficaram em cima da reta escolhida.
            for (let volta = 0; volta < 2; volta++) {
                let su = 0, sv = 0, suu = 0, suv = 0, m = 0;
                for (let i = 0; i < n; i++) {
                    if (Math.abs(pontos[i].v - (melhorA * pontos[i].u + melhorB)) > tolerancia * 1.5) continue;
                    su += pontos[i].u;
                    sv += pontos[i].v;
                    suu += pontos[i].u * pontos[i].u;
                    suv += pontos[i].u * pontos[i].v;
                    m++;
                }
                if (m < 6) break;
                const den = m * suu - su * su;
                if (Math.abs(den) < 1e-9) break;
                melhorA = (m * suv - su * sv) / den;
                melhorB = (sv - melhorA * su) / m;
            }

            return { a: melhorA, b: melhorB };
        },

        // Endireita a folha: mapeamento projetivo (Heckbert, quadrado unitário → quadrilátero)
        // com amostragem bilinear — a folha inclinada/em perspetiva sai plana e retangular.
        corrigirPerspetiva(bruta, q) {
            const [tl, tr, br, bl] = q;
            const lado = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
            let w = Math.round(Math.max(lado(tl, tr), lado(bl, br)));
            let h = Math.round(Math.max(lado(tl, bl), lado(tr, br)));
            const esc = Math.min(1, 2400 / Math.max(w, h, 1));
            w = Math.max(1, Math.round(w * esc));
            h = Math.max(1, Math.round(h * esc));

            const x0 = tl.x, x1 = tr.x, x2 = br.x, x3 = bl.x;
            const y0 = tl.y, y1 = tr.y, y2 = br.y, y3 = bl.y;
            const sx = x0 - x1 + x2 - x3, sy = y0 - y1 + y2 - y3;
            const dx1 = x1 - x2, dx2 = x3 - x2, dy1 = y1 - y2, dy2 = y3 - y2;
            const den = dx1 * dy2 - dy1 * dx2;
            let g = 0, hh = 0;
            if (Math.abs(den) > 1e-9 && (Math.abs(sx) > 1e-9 || Math.abs(sy) > 1e-9)) {
                g = (sx * dy2 - sy * dx2) / den;
                hh = (dx1 * sy - dy1 * sx) / den;
            }
            const a = x1 - x0 + g * x1, b = x3 - x0 + hh * x3, c = x0;
            const dd = y1 - y0 + g * y1, e = y3 - y0 + hh * y3, f = y0;

            const SW = bruta.width, SH = bruta.height;
            const sd = bruta.getContext('2d').getImageData(0, 0, SW, SH).data;

            const saida = document.createElement('canvas');
            saida.width = w;
            saida.height = h;
            const sctx = saida.getContext('2d');
            const out = sctx.createImageData(w, h);
            const od = out.data;

            for (let Y = 0, o = 0; Y < h; Y++) {
                const v = Y / (h - 1 || 1);
                for (let X = 0; X < w; X++, o += 4) {
                    const u = X / (w - 1 || 1);
                    const denom = g * u + hh * v + 1;
                    const xs = Math.max(0, Math.min(SW - 1.001, (a * u + b * v + c) / denom));
                    const ys = Math.max(0, Math.min(SH - 1.001, (dd * u + e * v + f) / denom));
                    const xi = xs | 0, yi = ys | 0;
                    const fx = xs - xi, fy = ys - yi;
                    const p00 = (yi * SW + xi) * 4, p10 = p00 + 4, p01 = p00 + SW * 4, p11 = p01 + 4;
                    for (let ch = 0; ch < 3; ch++) {
                        od[o + ch] =
                            sd[p00 + ch] * (1 - fx) * (1 - fy) + sd[p10 + ch] * fx * (1 - fy) +
                            sd[p01 + ch] * (1 - fx) * fy + sd[p11 + ch] * fx * fy;
                    }
                    od[o + 3] = 255;
                }
            }
            sctx.putImageData(out, 0, 0);

            return saida;
        },

        // Limiar de Otsu: separa "papel claro" de "fundo escuro" pelo histograma.
        limiarOtsu(hist, total) {
            let soma = 0;
            for (let t = 0; t < 256; t++) soma += t * hist[t];
            let somaFundo = 0, pesoFundo = 0, melhor = 127, maxVar = 0;
            for (let t = 0; t < 256; t++) {
                pesoFundo += hist[t];
                if (!pesoFundo) continue;
                const pesoFrente = total - pesoFundo;
                if (!pesoFrente) break;
                somaFundo += t * hist[t];
                const mFundo = somaFundo / pesoFundo;
                const mFrente = (soma - somaFundo) / pesoFrente;
                const entre = pesoFundo * pesoFrente * (mFundo - mFrente) * (mFundo - mFrente);
                if (entre > maxVar) { maxVar = entre; melhor = t; }
            }
            return melhor;
        },

        // Filtro de documento pela LUZ LOCAL. O que havia antes esticava os níveis de toda
        // a imagem entre dois percentis: bastava um reflexo ou um canto às escuras para o
        // papel ficar cinzento — a queixa de "está muito escuro". Agora cada píxel é
        // comparado com o papel À VOLTA dele, por isso o papel fica branco mesmo com a
        // sombra da mão, luz de lado ou papel térmico acinzentado, e a tinta escurece.
        filtroDocumento(ctx, w, h) {
            const img = ctx.getImageData(0, 0, w, h);
            const d = img.data;
            const total = w * h;

            const cinza = new Uint8Array(total);
            for (let p = 0, i = 0; p < total; p++, i += 4) {
                cinza[p] = (0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) | 0;
            }

            const fundo = this.fundoLocal(cinza, w, h);

            for (let p = 0, i = 0; p < total; p++, i += 4) {
                // Quanto o píxel é mais escuro do que o papel à volta: papel → branco,
                // tinta → escuro. O mínimo de 32 no divisor evita ampliar ruído numa
                // fotografia tirada às escuras.
                let v = (cinza[p] / Math.max(fundo[p], 32)) * 255;
                if (v > 255) v = 255;
                // Gama > 1 aprofunda a tinta sem mexer no branco do papel.
                v = 255 * Math.pow(v / 255, 1.3);
                d[i] = d[i + 1] = d[i + 2] = v < 0 ? 0 : (v > 255 ? 255 : v);
            }
            ctx.putImageData(img, 0, 0);
        },

        /**
         * Mapa da iluminação: para cada píxel, quão claro é o PAPEL naquela zona.
         *
         * Calcula-se numa miniatura — a luz é uma superfície suave, não precisa de
         * resolução, e assim isto corre num telemóvel sem engasgar. Em cada bloco fica o
         * píxel MAIS CLARO (o papel entre as letras, nunca a tinta), depois alarga-se e
         * suaviza-se, e no fim estica-se de volta ao tamanho real por interpolação.
         */
        fundoLocal(cinza, w, h) {
            const W = Math.max(2, Math.min(160, w));
            const H = Math.max(2, Math.round(h * (W / w)) || 2);
            const passoX = w / W, passoY = h / H;

            const peq = new Float32Array(W * H);
            for (let y = 0; y < H; y++) {
                const y0 = Math.floor(y * passoY);
                const y1 = Math.max(y0 + 1, Math.min(h, Math.floor((y + 1) * passoY)));
                for (let x = 0; x < W; x++) {
                    const x0 = Math.floor(x * passoX);
                    const x1 = Math.max(x0 + 1, Math.min(w, Math.floor((x + 1) * passoX)));
                    let maximo = 0;
                    for (let yy = y0; yy < y1; yy++) {
                        const base = yy * w;
                        for (let xx = x0; xx < x1; xx++) {
                            const g = cinza[base + xx];
                            if (g > maximo) maximo = g;
                        }
                    }
                    peq[y * W + x] = maximo;
                }
            }

            const alargado = this.janela(peq, W, H, 2, true);
            const suave = this.janela(alargado, W, H, 3, false);

            const fundo = new Float32Array(w * h);
            for (let y = 0; y < h; y++) {
                const fy = Math.max(0, Math.min(H - 1.001, ((y + 0.5) / h) * H - 0.5));
                const yi = fy | 0, ty = fy - yi, yi2 = Math.min(H - 1, yi + 1);
                for (let x = 0; x < w; x++) {
                    const fx = Math.max(0, Math.min(W - 1.001, ((x + 0.5) / w) * W - 0.5));
                    const xi = fx | 0, tx = fx - xi, xi2 = Math.min(W - 1, xi + 1);
                    const a = suave[yi * W + xi], b = suave[yi * W + xi2];
                    const c = suave[yi2 * W + xi], e = suave[yi2 * W + xi2];
                    fundo[y * w + x] = (a * (1 - tx) + b * tx) * (1 - ty) + (c * (1 - tx) + e * tx) * ty;
                }
            }

            return fundo;
        },

        // Passagem separável (linhas e depois colunas) numa janela de raio r: máximo
        // (alargar) ou média (suavizar).
        janela(origem, W, H, r, maximo) {
            const meio = new Float32Array(W * H);
            const saida = new Float32Array(W * H);

            for (let y = 0; y < H; y++) {
                for (let x = 0; x < W; x++) {
                    let acc = 0, n = 0;
                    for (let k = -r; k <= r; k++) {
                        const v = origem[y * W + Math.min(W - 1, Math.max(0, x + k))];
                        if (maximo) { if (v > acc) acc = v; } else { acc += v; n++; }
                    }
                    meio[y * W + x] = maximo ? acc : acc / n;
                }
            }
            for (let y = 0; y < H; y++) {
                for (let x = 0; x < W; x++) {
                    let acc = 0, n = 0;
                    for (let k = -r; k <= r; k++) {
                        const v = meio[Math.min(H - 1, Math.max(0, y + k)) * W + x];
                        if (maximo) { if (v > acc) acc = v; } else { acc += v; n++; }
                    }
                    saida[y * W + x] = maximo ? acc : acc / n;
                }
            }

            return saida;
        },

        repetir() {
            this.abrir();
        },

        usar() {
            this.$refs.tela.toBlob((blob) => {
                const ficheiro = new File([blob], 'recibo-digitalizado.jpg', { type: 'image/jpeg' });
                this.$wire.upload('reciboDigitalizado', ficheiro, () => {}, () => {
                    this.erro = 'Falha ao enviar a digitalização.';
                });
                this.fechar();
            }, 'image/jpeg', 0.92);
        },

        pararCamara() {
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
        },

        fechar() {
            this.pararCamara();
            this.aberto = false;
            // Uma fotografia de 12 MP ocupa dezenas de MB em memória: largar as telas ao
            // fechar evita deixar isso pendurado no telemóvel.
            this.bruta = null;
            this.previa = null;
            this.plana = null;
            this.quad = null;
        },
    }));

    // Toolbar/vista responsivos: num telemóvel a barra do FullCalendar (prev/next/today +
    // título + 3 botões de vista) não cabe. Em ecrã estreito reduzimos os botões e abrimos
    // na vista de DIA (a semana fica ilegível a 375px).
    const ecraEstreito = () => window.innerWidth < 640;
    const toolbarResponsiva = (estreito) => estreito
        ? { left: 'prev,next', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' }
        : { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' };

    window.Alpine.data('agendaCalendario', () => ({
        calendar: null,
        erro: '',

        init() {
            const estreito = ecraEstreito();
            this.calendar = new Calendar(this.$refs.cal, {
                plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
                initialView: estreito ? 'timeGridDay' : 'timeGridWeek',
                locale: ptLocale,
                timeZone: 'local',
                firstDay: 1,
                // Dia inteiro (00h–24h): os técnicos não têm horário fixo — trabalho noturno e
                // serviços que atravessam a meia-noite têm de se ver e arrastar como qualquer outro.
                // A vista abre às 07h (scrollTime), que é onde a maioria do trabalho está.
                slotMinTime: '00:00:00',
                slotMaxTime: '24:00:00',
                scrollTime: '07:00:00',
                // Faixa "dia inteiro" no topo: férias/ausências (00:00–23:59) vivem aqui e deixam
                // a grelha das horas livre para o trabalho desse dia.
                allDaySlot: true,
                allDayText: 'Dia inteiro',
                // Eventos à mesma hora ficam LADO A LADO, cada um com a sua coluna, em vez de
                // meio sobrepostos uns aos outros (por defeito o FullCalendar sobrepõe metade).
                slotEventOverlap: false,
                nowIndicator: true,
                expandRows: true,
                height: 'auto',
                headerToolbar: toolbarResponsiva(estreito),
                // Ao rodar/redimensionar, reajusta a barra (mantém a vista escolhida pelo utilizador).
                windowResize: () => {
                    this.calendar.setOption('headerToolbar', toolbarResponsiva(ecraEstreito()));
                },
                editable: true,
                selectable: true,
                selectMirror: true,
                // Toque (telemóvel): agarra um evento para arrastar com um toque curto (250 ms) em vez
                // do 1 s por defeito — torna o arrasto vertical (mudar a hora) fluido. A seleção de um
                // intervalo livre para criar evento exige um toque um pouco mais longo (500 ms), para
                // não criar eventos sem querer ao tentar fazer scroll numa zona vazia.
                eventLongPressDelay: 250,
                selectLongPressDelay: 500,
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                events: (info, success, failure) => {
                    this.$wire.eventos(info.startStr, info.endStr).then(success).catch(failure);
                },
                // Evento com VÁRIOS técnicos: fundo dividido em faixas verticais, uma cor por
                // técnico (as cores vêm do backend em extendedProps.cores, principal primeiro).
                // Filtro a hex: as cores entram num style inline — só valores #rrggbb passam
                // (defesa em profundidade; hoje o backend só envia a paleta fixa).
                eventDidMount: (info) => {
                    const cores = (info.event.extendedProps.cores || []).filter((c) => /^#[0-9a-f]{6}$/i.test(c));
                    if (cores.length > 1) {
                        const largura = 100 / cores.length;
                        const faixas = cores.map((c, i) => `${c} ${largura * i}% ${largura * (i + 1)}%`).join(', ');
                        info.el.style.background = `linear-gradient(to right, ${faixas})`;
                        info.el.style.borderColor = cores[0];
                        // Texto pela cor da PRIMEIRA faixa (categorias claras do Outlook
                        // — o roxo claro, os cinzentos — pedem texto escuro).
                        const texto = (info.event.extendedProps.textos || [])[0];
                        if (/^#[0-9a-f]{6}$/i.test(texto || '')) {
                            info.el.style.color = texto;
                        }
                    }
                },
                eventDrop: (info) => this.aoMover(info),
                eventResize: (info) => this.aoMover(info),
                // Dias feriados não se selecionam: o servidor recusaria de qualquer forma
                // (AgendadorEvento), mas assim a pessoa percebe logo porquê, sem abrir o
                // formulário e perder o que lá escreveu.
                selectAllow: (info) => {
                    const nome = this.feriadoEm(info.start);
                    if (nome) {
                        this.erro = info.start.toLocaleDateString('pt-PT') + ' é feriado nacional (' + nome + ') — não é possível marcar neste dia.';
                        clearTimeout(this.avisoFeriado);
                        this.avisoFeriado = setTimeout(() => { this.erro = ''; }, 5000);
                    }
                    return !nome;
                },
                select: (info) => {
                    // Criação por DIA: manda só a data (sem hora) — as horas reais escrevem-se
                    // no formulário, que aceita vários dias. Antes o evento nascia colado à
                    // faixa horária clicada, o que obrigava a corrigir sempre a seguir.
                    this.$wire.abrirCriacao(info.startStr.slice(0, 10), info.startStr.slice(0, 10));
                    this.calendar.unselect();
                },
                eventClick: (info) => {
                    // Segmentos de eventos multi-dia têm id "123:0" — o id do EVENTO vem
                    // sempre em extendedProps.evento_id (fallback ao id para o formato antigo).
                    this.$wire.selecionar(Number(info.event.extendedProps.evento_id ?? info.event.id));
                },
            });

            this.calendar.render();

            // Refrescar quando o filtro de técnico muda (evento despachado pelo Livewire).
            window.addEventListener('agenda:refetch', () => this.calendar.refetchEvents());
        },

        // Nome do feriado nesse dia (os feriados chegam como blocos de fundo na mesma
        // lista de eventos). As tolerâncias de ponto não contam — não bloqueiam nada.
        feriadoEm(data) {
            const dia = new Date(data.getTime() - data.getTimezoneOffset() * 60000)
                .toISOString().slice(0, 10);
            const f = this.calendar.getEvents().find((e) =>
                e.extendedProps.kind === 'feriado' &&
                !e.extendedProps.tolerancia &&
                e.startStr.slice(0, 10) === dia);

            return f ? f.extendedProps.nome : null;
        },

        async aoMover(info) {
            const e = info.event;
            const res = await this.$wire.reagendar(
                e.id,
                e.startStr,
                e.endStr,
                e.extendedProps.tecnico_id ?? null,
            );

            if (!res || !res.ok) {
                info.revert();
                this.erro = (res && res.mensagem) ? res.mensagem : 'Não foi possível reagendar.';
                setTimeout(() => { this.erro = ''; }, 5000);
            }
        },
    }));
});
