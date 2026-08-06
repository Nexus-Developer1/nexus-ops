import './bootstrap';

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

            const prontos = [];
            for (const f of ficheiros) {
                prontos.push(f.type.startsWith('image/') ? await this.comprimir(f) : f);
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
                        resolve(new File([blob], nome, { type: 'image/jpeg', lastModified: Date.now() }));
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
        ctx: null,

        init() {
            const pad = this.$refs.pad;
            const ratio = window.devicePixelRatio || 1;
            const ajustar = () => {
                const r = pad.getBoundingClientRect();
                if (!r.width) return;
                pad.width = r.width * ratio;
                pad.height = r.height * ratio;
                this.ctx = pad.getContext('2d');
                this.ctx.scale(ratio, ratio);
                this.ctx.lineWidth = 2;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                this.ctx.strokeStyle = '#111827';
            };
            ajustar();
            window.addEventListener('resize', ajustar);

            const pos = (e) => {
                const r = pad.getBoundingClientRect();
                return [e.clientX - r.left, e.clientY - r.top];
            };

            pad.addEventListener('pointerdown', (e) => {
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

        limpar() {
            const pad = this.$refs.pad;
            this.ctx?.clearRect(0, 0, pad.width, pad.height);
            this.temTraco = false;
            // 'limpar' diz ao servidor para apagar a assinatura gravada (se existir).
            this.$wire.set(campo, jaGravada ? 'limpar' : '', false);
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

            // Recorta automaticamente ao PAPEL (é suposto apanhar só o recibo) e aplica o
            // filtro de documento adaptativo. Se a deteção falhar, fica o frame inteiro.
            const zona = this.detetarPapel(bruta);
            tela.width = zona.w;
            tela.height = zona.h;
            const ctx = tela.getContext('2d');
            ctx.drawImage(bruta, zona.x, zona.y, zona.w, zona.h, 0, 0, zona.w, zona.h);
            this.filtroDocumento(ctx, zona.w, zona.h);

            this.capturado = true;
            this.pararCamara(); // congela a captura; "Repetir" reabre
        },

        // Encontra o papel: análise numa miniatura (cinzentos + limiar de Otsu) e ENCHENTE a
        // partir do centro sobre os píxeis claros — o recibo aponta-se ao centro, e a mancha
        // clara ligada ao centro é o papel; a caixa envolvente dela é o recorte. Mancha
        // minúscula (não há papel) ou praticamente o frame todo (fundo também claro, sem
        // fronteira útil) → devolve o frame inteiro, comportamento de antes.
        detetarPapel(bruta) {
            const inteiro = { x: 0, y: 0, w: bruta.width, h: bruta.height };
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
            const limiar = this.limiarOtsu(hist, W * H);

            // Semente: o píxel claro mais próximo do centro (anéis a crescer).
            const cx = W >> 1, cy = H >> 1;
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
            if (semente < 0) return inteiro;

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
            if (fracao < 0.08) return inteiro;

            // Apara a caixa até às MARGENS da folha: a enchente pode arrastar nesgas de fundo
            // claro (mesa clara, outra folha atrás) que esticam a caixa. Filas/colunas de borda
            // que não sejam maioritariamente papel (densidade da mancha < 55%) são cortadas.
            const porLinha = new Uint32Array(H);
            const porColuna = new Uint32Array(W);
            for (let p = 0; p < visto.length; p++) {
                if (visto[p]) { porLinha[(p / W) | 0]++; porColuna[p % W]++; }
            }
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
            if (maxX - minX + 1 < 0.15 * W || maxY - minY + 1 < 0.15 * H) return inteiro;

            // Caixa da miniatura → coordenadas reais, com 1% de margem à volta.
            const ex = bruta.width / W, ey = bruta.height / H;
            const margem = Math.round(0.01 * bruta.width);
            const x0 = Math.max(0, Math.round(minX * ex) - margem);
            const y0 = Math.max(0, Math.round(minY * ey) - margem);
            const x1 = Math.min(bruta.width, Math.round((maxX + 1) * ex) + margem);
            const y1 = Math.min(bruta.height, Math.round((maxY + 1) * ey) + margem);

            return { x: x0, y: y0, w: Math.max(1, x1 - x0), h: Math.max(1, y1 - y0) };
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
                slotMinTime: '07:00:00',
                slotMaxTime: '20:00:00',
                allDaySlot: false,
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
