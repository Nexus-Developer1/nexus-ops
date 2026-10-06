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
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

// CADERNO do cliente (out. 2026) — o OneNote da equipa dentro da aplicação: SEPARADORES
// (normalmente um por cliente final) e, em cada um, PÁGINAS com texto rico e imagens coladas
// ("Equipamento 1", "Dados CCTV"…), criados à medida que fazem falta. Só a equipa (o grupo de
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

    // Imagem colada/arrastada no editor (sobe por $wire.upload e grava em guardarImagem).
    public $imagem = null;

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
        $pagina ??= $separador?->paginas()->first();
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
        $this->abrirPagina($separador->paginas()->first());
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

    public function criarPagina(): void
    {
        $separador = $this->separadorId ? $this->separadorDoCliente($this->separadorId) : null;
        if (! $separador) {
            return;
        }

        $pagina = $separador->paginas()->create([
            'titulo' => 'Sem título',
            'ordem' => $separador->paginas()->count(),
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
        if ($pagina->versao !== $versao) {
            $quem = $pagina->autorAlteracao?->nome ?? 'outra pessoa';

            return ['ok' => false, 'motivo' => "Esta página foi alterada por {$quem} entretanto. Recarregue para ver a versão mais recente — o que escreveu agora não foi gravado."];
        }

        $pagina->update([
            'conteudo' => app(LimpezaHtmlCaderno::class)->limpar($html),
            'versao' => $pagina->versao + 1,
            'atualizado_por' => auth()->id(),
        ]);

        return ['ok' => true, 'versao' => $pagina->versao];
    }

    /**
     * Imagem colada/arrastada no editor: vai para o object storage como ANEXO da página e
     * devolve o URL que o editor põe no <img> (/anexos/{id}, servido só à equipa).
     *
     * @return array{ok: bool, url?: string, motivo?: string}
     */
    public function guardarImagem(int $paginaId): array
    {
        $pagina = $this->paginaDoCliente($paginaId);
        if (! $pagina || ! $this->imagem) {
            return ['ok' => false, 'motivo' => 'Não foi possível guardar a imagem.'];
        }

        $this->validate(['imagem' => ['image', 'mimes:jpeg,png,gif,webp', 'max:20480']]);

        $ficheiro = $this->imagem;
        $key = $ficheiro->store('anexos/caderno/'.$pagina->id);
        $anexo = $pagina->anexos()->create([
            'nome_ficheiro' => $ficheiro->getClientOriginalName() ?: 'imagem.jpg',
            'storage_key' => $key,
            'mime' => $ficheiro->getMimeType(),
            'tamanho' => $ficheiro->getSize(),
            'criado_por' => auth()->id(),
        ]);
        $this->imagem = null;

        return ['ok' => true, 'url' => route('anexos.ver', $anexo, false)];
    }

    public function apagarPagina(int $id): void
    {
        $pagina = $this->paginaDoCliente($id);
        if (! $pagina) {
            return;
        }
        $pagina->delete();
        Auditor::registar('caderno_pagina_apagada', $this->cliente, ['pagina' => $pagina->titulo, 'separador' => $pagina->separador?->nome]);

        if ($this->paginaId === $id) {
            $this->abrirPagina($pagina->separador?->paginas()->first());
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

    private function abrirPagina(?CadernoPagina $pagina): void
    {
        $this->paginaId = $pagina?->id;
        $this->titulo = (string) ($pagina?->titulo ?? '');
    }

    private function selecionarPrimeiro(): void
    {
        $separador = $this->cliente->cadernoSeparadores()->first();
        $this->separadorId = $separador?->id;
        $this->abrirPagina($separador?->paginas()->first());
    }

    // Clientes finais dos equipamentos deste cliente que ainda não têm separador — atalhos
    // para criar os separadores com um clique.
    private function sugestoes(Collection $separadores): array
    {
        $existentes = $separadores->pluck('nome')->map(fn ($n) => mb_strtolower(trim($n)))->all();

        return Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente->id))
            ->whereNotNull('cliente_final')->where('cliente_final', '!=', '')
            ->distinct()->orderBy('cliente_final')->limit(30)->pluck('cliente_final')
            ->map(fn ($n) => trim($n))
            ->reject(fn ($n) => in_array(mb_strtolower($n), $existentes, true))
            ->unique()->values()->all();
    }

    public function render()
    {
        $separadores = $this->cliente->cadernoSeparadores()->withCount('paginas')->get();
        $separador = $separadores->firstWhere('id', $this->separadorId);
        $pagina = $this->paginaId ? CadernoPagina::with('autorAlteracao')->find($this->paginaId) : null;

        $resultados = collect();
        $termo = trim($this->pesquisa);
        if (mb_strlen($termo) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $termo).'%';
            $resultados = CadernoPagina::with('separador')
                ->whereHas('separador', fn ($q) => $q->where('cliente_id', $this->cliente->id))
                ->where(fn ($q) => $q->where('titulo', 'ilike', $like)->orWhere('conteudo', 'ilike', $like))
                ->orderByDesc('updated_at')->limit(20)->get();
        }

        return view('livewire.clientes.caderno', [
            'separadores' => $separadores,
            'separador' => $separador,
            'paginas' => $separador ? $separador->paginas()->get(['id', 'titulo', 'updated_at']) : collect(),
            'pagina' => $pagina,
            'resultados' => $resultados,
            'sugestoes' => $this->sugestoes($separadores),
            'cores' => CadernoSeparador::CORES,
        ]);
    }
}
