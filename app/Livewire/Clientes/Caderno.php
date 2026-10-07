<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Anexo;
use App\Models\CadernoPagina;
use App\Models\CadernoSeparador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Services\Auditor;
use App\Services\Caderno\LimpezaHtmlCaderno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

// CADERNO do cliente (out. 2026) — o OneNote da equipa dentro da aplicação: SEPARADORES
// (normalmente um por cliente final) e, em cada um, PÁGINAS (e subpáginas) com texto rico —
// títulos, tabelas, listas de tarefas, cores —, imagens coladas e ficheiros anexados (PDF,
// manuais…) — "Equipamento 1", "Dados CCTV"… —, criados à medida que fazem falta, ordenados
// por arrastar (ou ↑/↓ no telemóvel), movidos entre separadores e ligados a um equipamento. Só a equipa (o grupo de
// rotas já barra o portal; ApenasEquipa reforça em cada pedido).
//
// Os ids de separador/página vêm do browser: cada ação confirma que pertencem a ESTE cliente
// (separadorDoCliente / paginaDoCliente) antes de mexer em nada.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Caderno do cliente'])]
class Caderno extends Component
{
    use ApenasEquipa;
    use WithFileUploads;

    public Cliente $cliente;

    #[Url(as: 's')]
    public ?int $separadorId = null;

    #[Url(as: 'p')]
    public ?int $paginaId = null;

    // Título da página aberta (grava ao sair do campo).
    public string $titulo = '';

    public string $pesquisa = '';

    public string $novoSeparador = '';

    // Imagem ou ficheiro colado/arrastado no editor (sobe por $wire.upload e grava em guardarAnexo).
    public $ficheiro = null;

    // Imagens e documentos que se podem anexar a uma página (o resto fica de fora; só imagens
    // e PDF abrem no browser — os outros são sempre download).
    public const TIPOS_ANEXO = 'jpeg,jpg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

    public function mount(Cliente $cliente): void
    {
        $this->cliente = $cliente;

        $separador = $this->separadorId ? $this->separadorDoCliente($this->separadorId) : null;
        $separador ??= $this->cliente->cadernoSeparadores()->first();
        $this->separadorId = $separador?->id;

        $pagina = $this->paginaId ? $this->paginaDoCliente($this->paginaId) : null;
        if ($pagina && $pagina->separador_id !== $this->separadorId) {
            $pagina = null; // a página tem de ser do separador aberto
        }
        $pagina ??= self::primeiraPagina($separador);
        $this->abrirPagina($pagina);
    }

    // ---- separadores -----------------------------------------------------------------

    public function criarSeparador(?string $nome = null): void
    {
        $nome = trim((string) ($nome ?? $this->novoSeparador));
        $this->resetErrorBag('novoSeparador');
        if ($nome === '') {
            $this->addError('novoSeparador', 'Escreva o nome do separador.');

            return;
        }
        $nome = mb_substr($nome, 0, 120);

        // Cores em rotação, como os separadores do OneNote.
        $cores = array_keys(CadernoSeparador::CORES);
        $total = $this->cliente->cadernoSeparadores()->count();

        $separador = $this->cliente->cadernoSeparadores()->create([
            'nome' => $nome,
            'cor' => $cores[$total % count($cores)],
            'ordem' => $total,
            'criado_por' => auth()->id(),
        ]);

        $this->novoSeparador = '';
        $this->separadorId = $separador->id;
        $this->abrirPagina(null);
    }

    public function selecionarSeparador(int $id): void
    {
        $separador = $this->separadorDoCliente($id);
        if (! $separador) {
            return;
        }
        $this->separadorId = $separador->id;
        $this->abrirPagina(self::primeiraPagina($separador));
    }

    public function renomearSeparador(int $id, string $nome): void
    {
        $nome = mb_substr(trim($nome), 0, 120);
        $separador = $this->separadorDoCliente($id);
        if ($separador && $nome !== '') {
            $separador->update(['nome' => $nome]);
        }
    }

    public function mudarCor(int $id, string $cor): void
    {
        $separador = $this->separadorDoCliente($id);
        if ($separador && array_key_exists($cor, CadernoSeparador::CORES)) {
            $separador->update(['cor' => $cor]);
        }
    }

    /**
     * Nova ordem dos separadores (arrastados nas abas). Só conta os ids deste cliente; os que
     * faltarem na lista ficam no fim, pela ordem que tinham.
     *
     * @param  list<int|string>  $ids
     */
    public function reordenarSeparadores(array $ids): void
    {
        $this->reordenar($this->cliente->cadernoSeparadores()->get(['id', 'ordem']), $ids);
    }

    // Muda o separador uma posição para a esquerda (-1) ou para a direita (+1).
    public function moverSeparador(int $id, int $direcao): void
    {
        $irmaos = $this->cliente->cadernoSeparadores()->get(['id', 'ordem']);
        $this->reordenar($irmaos, self::trocar($irmaos->pluck('id')->all(), $id, $direcao));
    }

    // Apaga (soft delete) o separador e as páginas dele — recuperável na BD.
    public function apagarSeparador(int $id): void
    {
        $separador = $this->separadorDoCliente($id);
        if (! $separador) {
            return;
        }
        $paginas = $separador->paginas()->count();
        $separador->paginas()->delete();
        $separador->delete();

        Auditor::registar('caderno_separador_apagado', $this->cliente, ['separador' => $separador->nome, 'paginas' => $paginas]);

        if ($this->separadorId === $id) {
            $this->selecionarPrimeiro();
        }
    }

    // ---- páginas ---------------------------------------------------------------------

    // Página nova no fim do separador aberto — ou, com $paiId, subpágina no fim das dessa página.
    public function criarPagina(?int $paiId = null): void
    {
        $separador = $this->separadorId ? $this->separadorDoCliente($this->separadorId) : null;
        if (! $separador) {
            return;
        }
        $pai = $paiId ? $this->paginaDoCliente($paiId) : null;
        if ($pai && ($pai->separador_id !== $separador->id || $pai->pai_id !== null)) {
            $pai = null; // só um nível, e no mesmo separador
        }

        $pagina = $separador->paginas()->create([
            'pai_id' => $pai?->id,
            'titulo' => 'Sem título',
            'ordem' => $separador->paginas()->where('pai_id', $pai?->id)->count(),
            'criado_por' => auth()->id(),
            'atualizado_por' => auth()->id(),
        ]);

        $this->abrirPagina($pagina);
    }

    public function selecionarPagina(int $id): void
    {
        $pagina = $this->paginaDoCliente($id);
        if (! $pagina) {
            return;
        }
        $this->separadorId = $pagina->separador_id;
        $this->abrirPagina($pagina);
    }

    /**
     * Nova ordem das páginas do separador aberto (arrastadas na lista).
     *
     * @param  list<int|string>  $ids
     */
    public function reordenarPaginas(array $ids): void
    {
        $separador = $this->separadorId ? $this->separadorDoCliente($this->separadorId) : null;
        $primeira = $separador ? $separador->paginas()->find((int) ($ids[0] ?? 0)) : null;
        if ($primeira) {
            // As subpáginas só se ordenam entre si; as de cima também.
            $this->reordenar($separador->paginas()->where('pai_id', $primeira->pai_id)->get(['id', 'ordem']), $ids);
        }
    }

    // Sobe (-1) ou desce (+1) a página entre as irmãs — a alternativa a arrastar no telemóvel.
    public function moverPagina(int $id, int $direcao): void
    {
        $pagina = $this->paginaDoCliente($id);
        if (! $pagina) {
            return;
        }
        $irmas = $this->irmas($pagina);
        $this->reordenar($irmas, self::trocar($irmas->pluck('id')->all(), $pagina->id, $direcao));
    }

    // Passa a página (e as subpáginas dela) para outro separador DESTE cliente, no fim.
    public function moverParaSeparador(int $paginaId, int $separadorId): void
    {
        $pagina = $this->paginaDoCliente($paginaId);
        $destino = $this->separadorDoCliente($separadorId);
        if (! $pagina || ! $destino || $pagina->separador_id === $destino->id) {
            return;
        }

        DB::transaction(function () use ($pagina, $destino) {
            $pagina->update([
                'separador_id' => $destino->id,
                'pai_id' => null, // uma subpágina chega lá como página
                'ordem' => $destino->paginas()->whereNull('pai_id')->count(),
                'atualizado_por' => auth()->id(),
            ]);
            CadernoPagina::where('pai_id', $pagina->id)->update(['separador_id' => $destino->id]);
        });

        $this->separadorId = $destino->id;
        $this->abrirPagina($pagina->fresh());
    }

    // Torna a página subpágina da que está logo acima (como «Tornar subpágina» no OneNote).
    // Um só nível: não serve a quem já tem subpáginas.
    public function tornarSubpagina(int $id): void
    {
        $pagina = $this->paginaDoCliente($id);
        if (! $pagina || $pagina->pai_id !== null || $pagina->subpaginas()->exists()) {
            return;
        }
        $irmas = $this->irmas($pagina)->pluck('id')->all();
        $posicao = array_search($pagina->id, $irmas, true);
        if ($posicao === false || $posicao === 0) {
            return; // não há página acima
        }
        $pai = $irmas[$posicao - 1];
        $pagina->update(['pai_id' => $pai, 'ordem' => CadernoPagina::where('pai_id', $pai)->count()]);
    }

    // Subpágina → página, logo a seguir à que estava por cima.
    public function promoverPagina(int $id): void
    {
        $pagina = $this->paginaDoCliente($id);
        if (! $pagina || $pagina->pai_id === null) {
            return;
        }
        $pai = $pagina->pai_id;
        $pagina->update(['pai_id' => null]);

        $topo = $this->irmas($pagina)->pluck('id')->reject(fn ($i) => $i === $pagina->id)->values()->all();
        $posicao = array_search($pai, $topo, true);
        array_splice($topo, $posicao === false ? count($topo) : $posicao + 1, 0, [$pagina->id]);
        $this->reordenar($this->irmas($pagina), $topo);
    }

    // Liga a página a um equipamento DESTE cliente (ou desliga, com null).
    public function ligarEquipamento(int $paginaId, ?int $equipamentoId): void
    {
        $pagina = $this->paginaDoCliente($paginaId);
        if (! $pagina) {
            return;
        }
        if ($equipamentoId && ! $this->equipamentosDoCliente()->whereKey($equipamentoId)->exists()) {
            return;
        }
        $pagina->update(['equipamento_id' => $equipamentoId ?: null, 'atualizado_por' => auth()->id()]);
    }

    public function updatedTitulo(): void
    {
        $pagina = $this->paginaId ? $this->paginaDoCliente($this->paginaId) : null;
        if (! $pagina) {
            return;
        }
        $titulo = mb_substr(trim($this->titulo), 0, 200);
        $this->titulo = $titulo === '' ? 'Sem título' : $titulo;
        $pagina->update(['titulo' => $this->titulo, 'atualizado_por' => auth()->id()]);
    }

    /**
     * Autosave do editor. O HTML é limpo antes de gravar; se outra pessoa gravou entretanto
     * (a versão mudou), NÃO se escreve por cima — o editor avisa para recarregar a página.
     *
     * @return array{ok: bool, versao?: int, motivo?: string}
     */
    public function guardarConteudo(int $paginaId, string $html, int $versao): array
    {
        $pagina = $this->paginaDoCliente($paginaId);
        if (! $pagina) {
            return ['ok' => false, 'motivo' => 'Esta página já não existe.'];
        }
        if (strlen($html) > LimpezaHtmlCaderno::MAX_BYTES) {
            return ['ok' => false, 'motivo' => 'A página tem texto demais — divida-a em duas.'];
        }
        // A versão confere-se NA gravação (UPDATE … WHERE versao = ?), não antes: duas gravações
        // ao mesmo tempo sobre a mesma versão passavam as duas na verificação e a segunda
        // escrevia por cima da primeira (28.ª revisão de segurança). Só uma acerta.
        $gravou = $pagina->versao === $versao && CadernoPagina::whereKey($pagina->id)->where('versao', $versao)->update([
            'conteudo' => app(LimpezaHtmlCaderno::class)->limpar($html),
            'versao' => $versao + 1,
            'atualizado_por' => auth()->id(),
            'updated_at' => now(),
        ]) === 1;

        if (! $gravou) {
            $quem = $pagina->fresh()?->autorAlteracao?->nome ?? 'outra pessoa';

            return ['ok' => false, 'motivo' => "Esta página foi alterada por {$quem} entretanto. Recarregue para ver a versão mais recente — o que escreveu agora não foi gravado."];
        }

        return ['ok' => true, 'versao' => $versao + 1];
    }

    /**
     * Imagem ou ficheiro (PDF, manual…) colado/arrastado no editor: vai para o object storage
     * como ANEXO da página e devolve o URL que o editor põe na página (/anexos/{id}, servido
     * só à equipa).
     *
     * @return array{ok: bool, url?: string, motivo?: string}
     */
    public function guardarAnexo(int $paginaId): array
    {
        $pagina = $this->paginaDoCliente($paginaId);
        if (! $pagina || ! $this->ficheiro) {
            return ['ok' => false, 'motivo' => 'Não foi possível guardar o ficheiro.'];
        }

        $this->validate(['ficheiro' => ['file', 'mimes:'.self::TIPOS_ANEXO, 'max:20480']]);

        $ficheiro = $this->ficheiro;
        $key = $ficheiro->store('anexos/caderno/'.$pagina->id);
        $anexo = $pagina->anexos()->create([
            'nome_ficheiro' => mb_substr($ficheiro->getClientOriginalName() ?: 'ficheiro', 0, 200),
            'storage_key' => $key,
            'mime' => $ficheiro->getMimeType(),
            'tamanho' => $ficheiro->getSize(),
            'criado_por' => auth()->id(),
        ]);
        $this->ficheiro = null;

        return ['ok' => true, 'url' => route('anexos.ver', $anexo, false)];
    }

    public function apagarPagina(int $id): void
    {
        $pagina = $this->paginaDoCliente($id);
        if (! $pagina) {
            return;
        }
        // As subpáginas não vão com ela: sobem para o lugar da página apagada.
        $filhas = $pagina->subpaginas()->pluck('id')->all();
        $topo = $this->irmas($pagina)->pluck('id')->all();
        $pagina->delete();
        if ($filhas !== [] && $pagina->pai_id === null) {
            CadernoPagina::whereIn('id', $filhas)->update(['pai_id' => null]);
            $posicao = array_search($pagina->id, $topo, true);
            array_splice($topo, (int) $posicao, 1, $filhas);
            $this->reordenar(CadernoPagina::whereIn('id', $topo)->get(['id', 'ordem']), $topo);
        }
        Auditor::registar('caderno_pagina_apagada', $this->cliente, ['pagina' => $pagina->titulo, 'separador' => $pagina->separador?->nome, 'subpaginas_promovidas' => count($filhas)]);

        if ($this->paginaId === $id) {
            $this->abrirPagina(self::primeiraPagina($pagina->separador));
        }
    }

    // ---- auxiliares --------------------------------------------------------------------

    private function separadorDoCliente(int $id): ?CadernoSeparador
    {
        return CadernoSeparador::where('cliente_id', $this->cliente->id)->find($id);
    }

    private function paginaDoCliente(int $id): ?CadernoPagina
    {
        return CadernoPagina::whereHas('separador', fn ($q) => $q->where('cliente_id', $this->cliente->id))->find($id);
    }

    // Grava a nova ordem: primeiro os ids recebidos (só os que existem na coleção), depois os
    // restantes. Só escreve nas linhas cuja ordem mudou.
    private function reordenar(Collection $itens, array $ids): void
    {
        $porId = $itens->keyBy('id');
        $ordenados = collect($ids)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $porId->has($id))->values();
        $ordenados = $ordenados->merge($itens->pluck('id')->diff($ordenados))->values();

        foreach ($ordenados as $posicao => $id) {
            if ((int) $porId[$id]->ordem !== $posicao) {
                $porId[$id]->update(['ordem' => $posicao]);
            }
        }
    }

    // A primeira página (de cima) do separador — a que abre ao escolher o separador.
    private static function primeiraPagina(?CadernoSeparador $separador): ?CadernoPagina
    {
        return $separador?->paginas()->whereNull('pai_id')->first() ?? $separador?->paginas()->first();
    }

    // Páginas ao mesmo nível (mesmo separador e mesma página de cima), pela ordem.
    private function irmas(CadernoPagina $pagina): Collection
    {
        return CadernoPagina::where('separador_id', $pagina->separador_id)
            ->where('pai_id', $pagina->pai_id)
            ->orderBy('ordem')->orderBy('id')->get(['id', 'ordem']);
    }

    // A lista de ids com $id trocado com o vizinho ($direcao -1 = antes, +1 = depois).
    private static function trocar(array $ids, int $id, int $direcao): array
    {
        $de = array_search($id, $ids, true);
        $para = $de === false ? false : $de + ($direcao < 0 ? -1 : 1);
        if ($de === false || $para < 0 || $para >= count($ids)) {
            return $ids;
        }
        [$ids[$de], $ids[$para]] = [$ids[$para], $ids[$de]];

        return $ids;
    }

    private function equipamentosDoCliente(): Builder
    {
        return Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente->id));
    }

    private function abrirPagina(?CadernoPagina $pagina): void
    {
        $this->paginaId = $pagina?->id;
        $this->titulo = (string) ($pagina?->titulo ?? '');
    }

    private function selecionarPrimeiro(): void
    {
        $separador = $this->cliente->cadernoSeparadores()->first();
        $this->separadorId = $separador?->id;
        $this->abrirPagina(self::primeiraPagina($separador));
    }

    // Clientes finais dos equipamentos deste cliente que ainda não têm separador — atalhos
    // para criar os separadores com um clique.
    private function sugestoes(Collection $separadores): array
    {
        $existentes = $separadores->pluck('nome')->map(fn ($n) => mb_strtolower(trim($n)))->all();

        return $this->equipamentosDoCliente()
            ->whereNotNull('cliente_final')->where('cliente_final', '!=', '')
            ->distinct()->orderBy('cliente_final')->limit(30)->pluck('cliente_final')
            ->map(fn ($n) => trim($n))
            ->reject(fn ($n) => in_array(mb_strtolower($n), $existentes, true))
            ->unique()->values()->all();
    }

    /**
     * Páginas do separador em árvore (cada uma com as subpáginas), com o início do texto e se tem
     * imagens/ficheiros — para a lista à esquerda. Só se lê o começo do conteúdo.
     *
     * @return list<array{pagina: CadernoPagina, filhas: list<CadernoPagina>}>
     */
    private function arvore(CadernoSeparador $separador): array
    {
        $paginas = $separador->paginas()
            ->with('equipamento:id,fabricante,modelo,numero_serie')
            ->select(['id', 'separador_id', 'pai_id', 'equipamento_id', 'titulo', 'ordem', 'updated_at'])
            ->selectRaw("left(coalesce(conteudo, ''), 2000) as inicio")
            ->selectRaw("coalesce(conteudo, '') like '%<img%' as tem_imagens")
            ->selectRaw("(coalesce(conteudo, '') like '%data-ficheiro%' or coalesce(conteudo, '') like '%application/%') as tem_ficheiros")
            ->get()
            ->each(fn ($p) => $p->resumo = mb_strimwidth(LimpezaHtmlCaderno::texto($p->inicio), 0, 90, '…'));

        $ids = $paginas->pluck('id')->all();
        $filhas = $paginas->filter(fn ($p) => $p->pai_id && in_array($p->pai_id, $ids, true))->groupBy('pai_id');

        return $paginas->reject(fn ($p) => $p->pai_id && in_array($p->pai_id, $ids, true))
            ->map(fn ($p) => ['pagina' => $p, 'filhas' => ($filhas[$p->id] ?? collect())->values()->all()])
            ->values()->all();
    }

    public function render()
    {
        $separadores = $this->cliente->cadernoSeparadores()->withCount('paginas')->get();
        $separador = $separadores->firstWhere('id', $this->separadorId);
        $pagina = $this->paginaId ? CadernoPagina::with(['autorAlteracao', 'equipamento'])->find($this->paginaId) : null;

        $resultados = collect();
        $termo = trim($this->pesquisa);
        if (mb_strlen($termo) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $termo).'%';
            $resultados = CadernoPagina::with('separador')
                ->whereHas('separador', fn ($q) => $q->where('cliente_id', $this->cliente->id))
                ->where(fn ($q) => $q->where('titulo', 'ilike', $like)->orWhere('conteudo', 'ilike', $like))
                ->orderByDesc('updated_at')->limit(20)->get();
        }

        // Onde está a página aberta na árvore — decide o que o menu dela oferece.
        $arvore = $separador ? $this->arvore($separador) : [];
        $posicao = ['subpagina' => false, 'temFilhas' => false, 'primeira' => true, 'ultima' => true];
        foreach ($arvore as $i => $no) {
            if ($pagina && $no['pagina']->id === $pagina->id) {
                $posicao = ['subpagina' => false, 'temFilhas' => $no['filhas'] !== [], 'primeira' => $i === 0, 'ultima' => $i === count($arvore) - 1];
            }
            foreach ($no['filhas'] as $j => $filha) {
                if ($pagina && $filha->id === $pagina->id) {
                    $posicao = ['subpagina' => true, 'temFilhas' => false, 'primeira' => $j === 0, 'ultima' => $j === count($no['filhas']) - 1];
                }
            }
        }

        return view('livewire.clientes.caderno', [
            'separadores' => $separadores,
            'separador' => $separador,
            'arvore' => $arvore,
            'posicao' => $posicao,
            'pagina' => $pagina,
            'resultados' => $resultados,
            'sugestoes' => $this->sugestoes($separadores),
            'cores' => CadernoSeparador::CORES,
            // Para ligar a página a um equipamento (só os deste cliente).
            'equipamentos' => $pagina
                ? $this->equipamentosDoCliente()->orderBy('cliente_final')->orderBy('fabricante')->orderBy('modelo')
                    ->get(['id', 'fabricante', 'modelo', 'numero_serie', 'cliente_final'])
                : collect(),
            'tiposAnexo' => self::TIPOS_ANEXO,
        ]);
    }
}
