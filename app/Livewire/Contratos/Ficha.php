<?php

namespace App\Livewire\Contratos;

use App\Enums\EstadoContrato;
use App\Enums\EstadoEvento;
use App\Jobs\GerarVisitasPreventivas;
use App\Models\Contrato;
use App\Services\Agenda\GeradorVisitasPreventivas;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Ficha extends Component
{
    // Acima deste nº de visitas, a geração vai para a fila (não prende o request).
    private const LIMITE_SINCRONO = 300;

    public Contrato $contrato;

    public function mount(Contrato $contrato): void
    {
        $this->contrato = $contrato;
    }

    // Ativa o contrato → passa a governar a operação. A geração automática das
    // visitas preventivas (CLAUDE.md §6) liga-se aqui quando o módulo Agenda existir.
    public function ativar(GeradorVisitasPreventivas $gerador): void
    {
        if ($this->contrato->estado !== EstadoContrato::Rascunho) {
            session()->flash('erro', 'Só é possível ativar contratos em rascunho.');

            return;
        }

        if ($this->contrato->planosVisita()->count() === 0 || $this->contrato->equipamentos()->count() === 0) {
            session()->flash('erro', 'Defina pelo menos um equipamento e um plano de visita antes de ativar.');

            return;
        }

        $this->contrato->update(['estado' => EstadoContrato::Ativo]);

        // Estima o volume antes de gerar: pequeno/médio corre na hora (feedback imediato),
        // grande vai para a fila para não prender o request.
        if ($gerador->estimar($this->contrato) > self::LIMITE_SINCRONO) {
            GerarVisitasPreventivas::dispatch($this->contrato);
            session()->flash('sucesso', 'Contrato ativado. Como são muitas visitas, estão a ser geradas em segundo plano — o resumo aparece nesta ficha assim que concluir.');

            return;
        }

        // Síncrono: corre o mesmo job na hora (gera + deteta sobreposições + grava resumo).
        GerarVisitasPreventivas::dispatchSync($this->contrato);

        $resumo = $this->contrato->refresh()->resumo_geracao ?? ['criadas' => 0, 'sobreposicoes' => 0];
        $msg = "Contrato ativado. Geradas {$resumo['criadas']} visitas preventivas";
        $msg .= ($resumo['sobreposicoes'] ?? 0) > 0
            ? " · {$resumo['sobreposicoes']} com sobreposição de equipamento (ver detalhe abaixo)."
            : ', sem sobreposições.';

        session()->flash('sucesso', $msg);
    }

    public function suspender(): void
    {
        if ($this->contrato->estado === EstadoContrato::Ativo) {
            $this->contrato->update(['estado' => EstadoContrato::Suspenso]);
            session()->flash('sucesso', 'Contrato suspenso.');
        }
    }

    public function reativar(): void
    {
        if ($this->contrato->estado === EstadoContrato::Suspenso) {
            $this->contrato->update(['estado' => EstadoContrato::Ativo]);
            session()->flash('sucesso', 'Contrato reativado.');
        }
    }

    public function render()
    {
        $this->contrato->load([
            'cliente',
            'equipamentos.local',
            'planosVisita',
            'slas',
        ]);

        return view('livewire.contratos.ficha', [
            'contrato' => $this->contrato,
            'saldo' => $this->saldoVisitas(),
        ]);
    }

    // Saldo de visitas incluídas (modelo manual). Conta por COBERTURA, sem filtrar tipo
    // (apanha visitas manuais; ignora as auto-geradas, que têm cobertura null). Null se o
    // contrato não tem cláusula de visitas (visitas_incluidas vazio) → não se mostra saldo.
    /** @return array{incluidas:int, usadas:int, extras:int, restantes:int, excedido:int}|null */
    private function saldoVisitas(): ?array
    {
        $incluidas = $this->contrato->visitas_incluidas;
        if ($incluidas === null) {
            return null;
        }

        $usadas = $this->contrato->eventos()
            ->where('cobertura', 'incluida')
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->count();
        $extras = $this->contrato->eventos()
            ->where('cobertura', 'extra')
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->count();

        return [
            'incluidas' => $incluidas,
            'usadas' => $usadas,
            'extras' => $extras,
            'restantes' => max(0, $incluidas - $usadas), // nunca negativo
            'excedido' => max(0, $usadas - $incluidas),
        ];
    }
}
