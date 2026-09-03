<?php

namespace App\Console\Commands;

use App\Models\EventoAgenda;
use App\Services\Agenda\CalendarioGraph;
use App\Services\Graph\ClienteGraph;
use Illuminate\Console\Command;
use Throwable;

// Calendário partilhado da agenda no M365 (Graph): diagnóstico, carga inicial e partilha.
//
//   php artisan agenda:graph --verificar   permissões do token + acesso ao calendário (sem escrever)
//   php artisan agenda:graph               espelha os eventos da janela [-30, +90] dias (idempotente)
//   php artisan agenda:graph --partilhar   partilha (leitura) o calendário com a equipa ativa
//
// Depois do consentimento de admin a Calendars.ReadWrite e de MS_GRAPH_CALENDARIO_ATIVO=true, a
// sequência é: --verificar → (sem opções) → --partilhar. A partir daí o observer trata do resto.
class SincronizarAgendaGraph extends Command
{
    protected $signature = 'agenda:graph
        {--verificar : Só diagnostica: permissões e acesso ao calendário}
        {--partilhar : Partilha o calendário (leitura) com a equipa ativa}
        {--dias-atras=30 : Janela para trás (dias)}
        {--dias-frente=90 : Janela para a frente (dias)}';

    protected $description = 'Calendário partilhado da agenda no Microsoft 365: verificar, espelhar eventos e partilhar com a equipa.';

    public function handle(ClienteGraph $graph, CalendarioGraph $calendario): int
    {
        $perms = $graph->permissoes();
        $temCalendario = in_array('Calendars.ReadWrite', $perms, true);
        $this->line('Permissões da app: '.($perms ? implode(', ', $perms) : '(nenhuma)'));
        $this->line('Calendário ativo (config): '.($calendario->ativo() ? 'sim' : 'NÃO — MS_GRAPH_CALENDARIO_ATIVO=false'));

        if (! $temCalendario) {
            $this->error('Falta a permissão de APLICAÇÃO Calendars.ReadWrite (com consentimento de admin) no registo da app no Entra ID.');

            return self::FAILURE;
        }

        try {
            $id = $calendario->calendarioId();
            $this->info('Calendário "'.config('services.microsoft_graph.calendario_agenda').'" em '.config('services.microsoft_graph.sender').': OK ('.substr($id, 0, 12).'…)');
        } catch (Throwable $e) {
            $this->error('Sem acesso ao calendário: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('verificar')) {
            return self::SUCCESS;
        }

        if ($this->option('partilhar')) {
            $r = $calendario->partilharComEquipa();
            $this->info('Partilhado com: '.(implode(', ', $r['partilhado']) ?: '—'));
            $this->line('Já tinham: '.(implode(', ', $r['ja_tinha']) ?: '—'));
            if ($r['falhou']) {
                $this->warn('Falhou: '.implode(', ', $r['falhou']));
            }

            // Cor por técnico (categorias) — é o que destaca o evento na grelha do Outlook.
            $c = $calendario->garantirCategorias();
            $this->info('Categorias criadas: '.(implode(', ', $c['criadas']) ?: '—'));
            $this->line('Já existiam: '.(implode(', ', $c['ja_tinha']) ?: '—'));
            if ($c['falhou']) {
                $this->warn('Categorias falhadas: '.implode(', ', $c['falhou']));
            }

            return self::SUCCESS;
        }

        if (! $calendario->ativo()) {
            $this->error('Liga MS_GRAPH_CALENDARIO_ATIVO=true no .env (e optimize) antes da carga inicial.');

            return self::FAILURE;
        }

        $eventos = EventoAgenda::query()
            ->where('estado', '!=', 'cancelado')
            ->whereBetween('inicio', [now()->subDays((int) $this->option('dias-atras'))->startOfDay(), now()->addDays((int) $this->option('dias-frente'))->endOfDay()])
            ->with(['tecnico', 'tecnicosAdicionais', 'cliente', 'local', 'equipamento', 'contrato'])
            ->orderBy('inicio')
            ->get();

        $this->info("A espelhar {$eventos->count()} eventos…");
        $barra = $this->output->createProgressBar($eventos->count());
        $criados = $atualizados = $erros = 0;

        foreach ($eventos as $e) {
            try {
                $tinha = (bool) $e->graph_event_id;
                $calendario->espelhar($e);
                $tinha ? $atualizados++ : $criados++;
            } catch (Throwable $ex) {
                $erros++;
                $this->newLine();
                $this->warn("Evento #{$e->id}: ".$ex->getMessage());
            }
            $barra->advance();
        }
        $barra->finish();
        $this->newLine();
        $this->info("Concluído: {$criados} criados, {$atualizados} atualizados, {$erros} erros.");

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }
}
