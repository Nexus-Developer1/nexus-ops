import './bootstrap';

import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptLocale from '@fullcalendar/core/locales/pt';
import Sortable from 'sortablejs';

// Componente Alpine da Agenda (FullCalendar). O $wire vem do componente Livewire
// que envolve este DOM. Eventos e reagendamento passam pelo backend (fonte de verdade).
document.addEventListener('alpine:init', () => {
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

    // Checklist em etapas com drag-and-drop (SortableJS). Reordena etapas e itens
    // (incluindo mover itens entre etapas) e persiste a nova ordem via Livewire.
    window.Alpine.data('checklist', () => ({
        instancias: [],

        init() {
            this.montar();

            // Reconstrói os Sortables após cada atualização do Livewire (novos itens/etapas).
            window.Livewire.hook('commit', ({ succeed }) => {
                succeed(() => queueMicrotask(() => this.montar()));
            });
        },

        montar() {
            if (!this.$el || !this.$el.isConnected) {
                return;
            }

            // Destrói instâncias anteriores para não duplicar handlers.
            this.instancias.forEach((s) => { try { s.destroy(); } catch (e) {} });
            this.instancias = [];

            const raiz = this.$el.querySelector('[data-etapas-root]');
            if (!raiz) {
                return;
            }

            // Sortable das ETAPAS.
            this.instancias.push(Sortable.create(raiz, {
                handle: '.pega-etapa',
                draggable: '[data-etapa]',
                animation: 150,
                onEnd: () => this.enviar(),
            }));

            // Sortable dos ITENS de cada etapa (grupo partilhado → mover entre etapas).
            raiz.querySelectorAll('[data-itens]').forEach((cont) => {
                this.instancias.push(Sortable.create(cont, {
                    group: 'checklist-itens',
                    handle: '.pega-item',
                    draggable: '[data-item]',
                    animation: 150,
                    onEnd: () => this.enviar(),
                }));
            });
        },

        enviar() {
            const raiz = this.$el.querySelector('[data-etapas-root]');
            if (!raiz) {
                return;
            }

            const estrutura = [];
            raiz.querySelectorAll(':scope > [data-etapa]').forEach((et) => {
                const itens = [];
                et.querySelectorAll('[data-item]').forEach((it) => itens.push(it.getAttribute('data-item')));
                estrutura.push({ etapa: et.getAttribute('data-etapa'), itens });
            });

            this.$wire.reordenar(estrutura);
        },
    }));
});
