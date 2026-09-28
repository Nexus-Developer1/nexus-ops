<?php

namespace App\Livewire\Encomendas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Dossier;
use App\Services\Erp\LeituraErpAoVivo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;

// Ficha de um dossiê (encomenda/proposta): o cabeçalho vem da nossa BD (dossiers), mas as
// LINHAS são lidas AO VIVO do PHC (tabela bi) no momento de abrir — não são sincronizadas.
// O ERP nunca deve rebentar o pedido do utilizador (CLAUDE.md §5): se estiver em baixo ou
// sem driver, a ficha abre na mesma e avisa que não conseguiu obter as linhas.
#[Layout('components.layouts.app', ['ativo' => 'encomendas', 'titulo' => 'Dossier PHC'])]
class Ficha extends Component
{
    use ApenasEquipa;

    public Dossier $dossier;

    // Colunas das LINHAS (chave => rótulo). A ordem aqui é a de fábrica.
    public const COLUNAS = [
        'ref' => 'Referência',
        'pn' => 'PN',
        'marca' => 'Marca',
        'descricao' => 'Descrição',
        'faltas' => 'Faltas',
        'qtt' => 'Qtd',
        'movimentado' => 'Movim.',
        'series' => 'Série(s)',
        'unitario' => 'Unitário',
        'total' => 'Total',
    ];

    // Colunas alinhadas à direita (números/valores).
    public const NUMERICAS = ['faltas', 'qtt', 'movimentado', 'unitario', 'total'];

    // Ordem escolhida pelo utilizador (arrastar os títulos), guardada na sessão. As chaves
    // são uma whitelist — nada vindo do browser entra em cru.
    #[Session(key: 'encomendas.colunas-linhas')]
    public array $ordemColunas = [];

    // Colunas ESCONDIDAS pelo utilizador (botões de ligar/desligar). Guardam-se as ocultas
    // (e não as visíveis) para que uma coluna nova no código apareça por defeito.
    #[Session(key: 'encomendas.colunas-ocultas')]
    public array $colunasOcultas = [];

    public function mount(Dossier $dossier): void
    {
        $this->dossier = $dossier;
        $this->normalizarColunas();
    }

    /** Colunas realmente mostradas: a ordem escolhida, menos as escondidas. */
    public function colunasVisiveis(): array
    {
        return array_values(array_diff($this->ordemColunas, $this->colunasOcultas));
    }

    // Liga/desliga uma coluna. Nunca deixa esconder a última — uma tabela sem colunas
    // nenhumas não tem como voltar atrás pela própria tabela.
    public function alternarColuna(string $chave): void
    {
        if (! isset(self::COLUNAS[$chave])) {
            return; // chave desconhecida (payload forjado) — ignorada
        }

        if (in_array($chave, $this->colunasOcultas, true)) {
            $this->colunasOcultas = array_values(array_diff($this->colunasOcultas, [$chave]));

            return;
        }

        if (count($this->colunasVisiveis()) <= 1) {
            return; // já só resta uma coluna visível
        }

        $this->colunasOcultas[] = $chave;
    }

    // Garante que a ordem guardada é sempre uma permutação válida das colunas conhecidas
    // (apanha uma sessão antiga ou uma coluna nova/removida no código).
    private function normalizarColunas(): void
    {
        $validas = array_values(array_unique(array_filter(
            $this->ordemColunas,
            fn ($c) => is_string($c) && isset(self::COLUNAS[$c]),
        )));
        foreach (array_keys(self::COLUNAS) as $chave) {
            if (! in_array($chave, $validas, true)) {
                $validas[] = $chave; // acrescenta as que faltem, no fim
            }
        }
        $this->ordemColunas = $validas;
    }

    // Aplica a nova ordem vinda do arrastar (revalidada no servidor).
    public function reordenarColunas(array $ordem): void
    {
        $this->ordemColunas = $ordem;
        $this->normalizarColunas();
    }

    // Repõe a ordem de fábrica E volta a mostrar todas as colunas.
    public function reporColunas(): void
    {
        $this->ordemColunas = array_keys(self::COLUNAS);
        $this->colunasOcultas = [];
    }

    public function render(LeituraErpAoVivo $phc)
    {
        // Linhas e total AO VIVO do PHC (timeout curto, guardados 90 s — mostrar/esconder e
        // reordenar colunas não voltam a perguntar —, pausa se o PHC falhar). As duas leituras
        // são independentes: se só o total falhar, as linhas aparecem na mesma. O total guardado
        // é o da última sincronização (8h/13h/19h) — um dossiê alterado depois dela mostrava em
        // cima um total que não batia com as linhas (set. 2026 — proposta 7431).
        $linhas = $phc->linhas($this->dossier->id_erp);
        $erroLinhas = $linhas === null;
        $linhas ??= [];
        $totalAoVivo = $phc->total($this->dossier->id_erp);

        return view('livewire.encomendas.ficha', [
            'linhas' => $linhas,
            'erroLinhas' => $erroLinhas,
            'totalLinhas' => array_sum(array_map(fn ($l) => (float) ($l->total ?? 0), $linhas)),
            // PHC em baixo ou dossiê não encontrado → o da última sincronização.
            'totalDebito' => $totalAoVivo ?? $this->dossier->total_debito,
            'colunas' => self::COLUNAS,
            'numericas' => self::NUMERICAS,
            'visiveis' => $this->colunasVisiveis(),
            // Intervenções ligadas a esta encomenda de peças (a ligação faz-se no relatório).
            'intervencoes' => (int) $this->dossier->ndos === Dossier::TIPO_ENCOMENDA_PECAS
                ? $this->dossier->intervencoes()->with(['relatorio', 'equipamento', 'tecnico', 'tecnicos'])->orderByDesc('data_inicio')->get()
                : collect(),
        ]);
    }
}
