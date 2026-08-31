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
        capturado: false,
        stream: null,
        erro: '',

        async abrir() {
            this.erro = '';
            this.capturado = false;
            this.aberto = true;
            if (!navigator.mediaDevices?.getUserMedia) {
                this.erro = 'A câmara não está disponível neste dispositivo/navegador.';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 2560 } },
                    audio: false,
                });
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
            } catch (e) {
                this.erro = 'Não foi possível abrir a câmara (verifica as permissões).';
            }
        },

        capturar() {
            const video = this.$refs.video;
            const tela = this.$refs.tela;
            if (!video.videoWidth) return;

            // Frame completo numa tela de trabalho (fora do ecrã).
            const bruta = document.createElement('canvas');
            bruta.width = video.videoWidth;
            bruta.height = video.videoHeight;
            bruta.getContext('2d').drawImage(video, 0, 0);

            // Recorta automaticamente ao PAPEL: com os 4 cantos detetados, ENDIREITA a folha
            // (correção de perspetiva — comportamento de scanner); senão, recorta à caixa
            // aparada; falhando tudo, fica o frame inteiro. Filtro de documento no fim.
            const det = this.detetarPapel(bruta);
            const ctx = tela.getContext('2d');
            if (det?.quad) {
                const plano = this.corrigirPerspetiva(bruta, det.quad);
                tela.width = plano.width;
                tela.height = plano.height;
                ctx.drawImage(plano, 0, 0);
            } else {
                const zona = det?.caixa ?? { x: 0, y: 0, w: bruta.width, h: bruta.height };
                tela.width = zona.w;
                tela.height = zona.h;
                ctx.drawImage(bruta, zona.x, zona.y, zona.w, zona.h, 0, 0, zona.w, zona.h);
            }
            this.filtroDocumento(ctx, tela.width, tela.height);

            this.capturado = true;
            this.pararCamara(); // congela a captura; "Repetir" reabre
        },

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

            const fracao = area / (W * H);
            if (fracao < 0.08) return null;

            // 4 CANTOS da mancha (antes de aparar): extremos de x+y e x−y — é isto que
            // permite endireitar folhas inclinadas/em perspetiva.
            let tl = null, tr = null, br = null, bl = null;
            let sMin = Infinity, sMax = -Infinity, dMin = Infinity, dMax = -Infinity;
            const porLinha = new Uint32Array(H);
            const porColuna = new Uint32Array(W);
            for (let p = 0; p < visto.length; p++) {
                if (!visto[p]) continue;
                const x = p % W, y = (p / W) | 0;
                porLinha[y]++;
                porColuna[x]++;
                const s = x + y, dif = x - y;
                if (s < sMin) { sMin = s; tl = { x, y }; }
                if (s > sMax) { sMax = s; br = { x, y }; }
                if (dif > dMax) { dMax = dif; tr = { x, y }; }
                if (dif < dMin) { dMin = dif; bl = { x, y }; }
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

            // Validação do quadrilátero: área (shoelace) coerente com a mancha (se a enchente
            // arrastou uma nesga diagonal, os extremos disparam e a área rebenta) e lados
            // mínimos. Falhando, fica o recorte pela caixa.
            const areaQuad = Math.abs(
                (tl.x * tr.y - tr.x * tl.y) + (tr.x * br.y - br.x * tr.y) +
                (br.x * bl.y - bl.x * br.y) + (bl.x * tl.y - tl.x * bl.y)
            ) / 2;
            const lado = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
            const ladoMin = Math.min(lado(tl, tr), lado(tr, br), lado(br, bl), lado(bl, tl));
            const quadOk = areaQuad >= 0.8 * area && areaQuad <= 1.5 * area && ladoMin >= 0.15 * Math.min(W, H);

            let quad = null;
            if (quadOk) {
                // Cantos → coordenadas reais, empurrados 1,5% para fora do centróide (para a
                // margem da folha não ser rapada) e presos ao frame.
                const mx = (tl.x + tr.x + br.x + bl.x) / 4, my = (tl.y + tr.y + br.y + bl.y) / 4;
                quad = [tl, tr, br, bl].map((c) => ({
                    x: Math.max(0, Math.min(bruta.width - 1, (c.x + (c.x - mx) * 0.015 + 0.5) * ex)),
                    y: Math.max(0, Math.min(bruta.height - 1, (c.y + (c.y - my) * 0.015 + 0.5) * ey)),
                }));
            }

            return { quad, caixa };
        },

        // Endireita a folha: mapeamento projetivo (Heckbert, quadrado unitário → quadrilátero)
        // com amostragem bilinear — a folha inclinada/em perspetiva sai plana e retangular.
        corrigirPerspetiva(bruta, q) {
            const [tl, tr, br, bl] = q;
            const lado = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
            let w = Math.round(Math.max(lado(tl, tr), lado(bl, br)));
            let h = Math.round(Math.max(lado(tl, bl), lado(tr, br)));
            const esc = Math.min(1, 1600 / Math.max(w, h, 1));
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

        // Filtro de documento ADAPTATIVO: em vez da curva fixa de antes (rebentava com luz
        // forte/fraca), estica os níveis entre os percentis 5 e 92 do próprio recorte — o
        // papel fica branco seja qual for a iluminação — e dá um empurrão suave ao contraste
        // para escurecer a tinta (aspeto de digitalização).
        filtroDocumento(ctx, w, h) {
            const img = ctx.getImageData(0, 0, w, h);
            const d = img.data;
            const total = w * h;
            const cinza = new Uint8Array(total);
            const hist = new Uint32Array(256);
            for (let p = 0, i = 0; p < total; p++, i += 4) {
                const g = (0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) | 0;
                cinza[p] = g;
                hist[g]++;
            }
            const percentil = (fr) => {
                let acc = 0;
                for (let t = 0; t < 256; t++) { acc += hist[t]; if (acc >= total * fr) return t; }
                return 255;
            };
            const preto = percentil(0.05);
            const branco = Math.max(preto + 30, percentil(0.92));
            for (let p = 0, i = 0; p < total; p++, i += 4) {
                let v = ((cinza[p] - preto) / (branco - preto)) * 255;
                v = (v - 128) * 1.15 + 136;
                v = Math.max(0, Math.min(255, v));
                d[i] = d[i + 1] = d[i + 2] = v;
            }
            ctx.putImageData(img, 0, 0);
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
            }, 'image/jpeg', 0.9);
        },

        pararCamara() {
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
        },

        fechar() {
            this.pararCamara();
            this.aberto = false;
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
                    }
                },
                eventDrop: (info) => this.aoMover(info),
                eventResize: (info) => this.aoMover(info),
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
