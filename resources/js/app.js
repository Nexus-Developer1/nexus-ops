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
            tela.width = video.videoWidth;
            tela.height = video.videoHeight;
            const ctx = tela.getContext('2d');
            ctx.drawImage(video, 0, 0);

            // Filtro de documento: luminância + curva de contraste com deslocamento para o
            // branco (papel fica claro, tinta fica escura — aspeto de digitalização).
            const img = ctx.getImageData(0, 0, tela.width, tela.height);
            const d = img.data;
            for (let i = 0; i < d.length; i += 4) {
                const cinza = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
                const valor = Math.max(0, Math.min(255, (cinza - 128) * 1.8 + 150));
                d[i] = d[i + 1] = d[i + 2] = valor;
            }
            ctx.putImageData(img, 0, 0);

            this.capturado = true;
            this.pararCamara(); // congela a captura; "Repetir" reabre
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
