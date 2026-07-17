import './bootstrap';

import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptLocale from '@fullcalendar/core/locales/pt';
import Chart from 'chart.js/auto';

// Componente Alpine da Agenda (FullCalendar). O $wire vem do componente Livewire
// que envolve este DOM. Eventos e reagendamento passam pelo backend (fonte de verdade).
document.addEventListener('alpine:init', () => {
    // Gráfico Chart.js: recebe a configuração (type/data/options) já montada no servidor.
    // O canvas vive dentro de um wrapper com wire:ignore para o Livewire não o morfar.
    window.Alpine.data('grafico', (config) => ({
        instancia: null,
        init() {
            this.instancia = new Chart(this.$refs.canvas, config);
        },
        destroy() {
            this.instancia?.destroy();
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
                eventDrop: (info) => this.aoMover(info),
                eventResize: (info) => this.aoMover(info),
                select: (info) => {
                    // Selecionar um intervalo livre → criar evento próprio / ausência.
                    this.$wire.abrirCriacao(info.startStr, info.endStr);
                    this.calendar.unselect();
                },
                eventClick: (info) => {
                    if (info.event.extendedProps.kind === 'ausencia') {
                        this.$wire.selecionarAusencia(Number(info.event.extendedProps.ausencia_id));
                    } else {
                        this.$wire.selecionar(Number(info.event.id));
                    }
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
