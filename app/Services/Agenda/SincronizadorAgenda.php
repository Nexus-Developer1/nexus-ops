<?php

namespace App\Services\Agenda;

use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Relatorio;

// Ponto ÚNICO da sincronização agenda ⇄ intervenções/relatórios (regra de ouro §6).
// As duas direções viviam espalhadas pelos componentes (camada 2 dentro de
// Calendario::criarEvento, camada 3 dentro de Relatorios\Novo::persistir) e o anti-loop
// assentava na convenção "a camada 3 cria o evento direto no model, nunca pelo método do
// componente". Aqui as guardas são EXPLÍCITAS e vivem com a regra: cada direção só atua se
// a ligação evento⇄intervenção ainda não existir — venha a chamada de onde vier, uma
// direção nunca põe a outra em marcha.
class SincronizadorAgenda
{
    public function __construct(
        private GeradorRascunhoDeEvento $geradorRascunho,
        private GeradorEventoDeRelatorio $geradorEvento,
    ) {}

    // Agenda → Relatórios (camada 2): evento gravado com equipamento OU contrato e início
    // futuro → garante intervenção planeada + relatório rascunho ligados ao evento.
    // Devolve o rascunho criado, ou null quando não há condições / já está convertido.
    public function eventoGravado(EventoAgenda $evento): ?Relatorio
    {
        // Anti-loop: evento já convertido (inclui os criados pela camada 3, que nascem
        // ligados à intervenção) nunca gera um segundo rascunho.
        if ($evento->intervencao_id !== null) {
            return null;
        }

        // Sem equipamento nem contrato não há âmbito para uma intervenção.
        if (! $evento->equipamento_id && ! $evento->contrato_id) {
            return null;
        }

        // Só visitas futuras — eventos passados são registo histórico, não trabalho a preparar.
        if (! $evento->inicio->isFuture()) {
            return null;
        }

        return $this->geradorRascunho->gerar($evento);
    }

    // Relatórios → Agenda (camada 3): intervenção gravada com data futura → garante o evento
    // de agenda ligado (cria ou move, nunca duplica). O evento nasce/fica já ligado à
    // intervenção, por isso a camada 2 nunca dispara de volta (guarda acima).
    public function intervencaoGravada(Intervencao $intervencao): ?EventoAgenda
    {
        return $this->geradorEvento->gerar($intervencao);
    }
}
