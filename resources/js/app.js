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

    window.Alpine.data('agendaCalendario', () => ({
        calendar: null,
        erro: '',

        init() {
            this.calendar = new Calendar(this.$refs.cal, {
                plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
                initialView: 'timeGridWeek',
                locale: ptLocale,
                timeZone: 'local',
                firstDay: 1,
                slotMinTime: '07:00:00',
                slotMaxTime: '20:00:00',
                allDaySlot: false,
                nowIndicator: true,
                expandRows: true,
                height: 'auto',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridDay,timeGridWeek,dayGridMonth',
                },
                editable: true,
                selectable: true,
                selectMirror: true,
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
