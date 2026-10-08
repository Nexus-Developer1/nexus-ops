<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Caderno;
use App\Models\CadernoSeparador;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// 29.ª revisão de segurança (out. 2026) — caderno novo:
//  · a página aberta (paginaId, público — vem do endereço e o browser pode mudá-lo) só se mostra
//    se for do cliente do caderno, como já acontecia em todas as ações;
//  · uma «subpágina de subpágina» (só por corrida) não desaparece da lista.
class Revisao29SegurancaTest extends TestCase
{
    use RefreshDatabase;

    private User $rui;

    private Cliente $bbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rui = User::create(['nome' => 'Rui Pereira', 'email' => 'rpereira@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->bbs = Cliente::create(['nome' => 'BBS - Cabling', 'ativo' => true]);
    }

    public function test_pagina_de_outro_cliente_nao_abre_mudando_o_id(): void
    {
        CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI'])->paginas()->create(['titulo' => 'CCTV']);
        $outro = Cliente::create(['nome' => 'Outro', 'ativo' => true]);
        $alheia = CadernoSeparador::create(['cliente_id' => $outro->id, 'nome' => 'Segredo'])
            ->paginas()->create(['titulo' => 'Passwords do outro', 'conteudo' => '<p>admin/1234</p>']);

        Livewire::actingAs($this->rui)->test(Caderno::class, ['cliente' => $this->bbs])
            ->set('paginaId', $alheia->id)
            ->assertViewHas('pagina', null)
            ->assertDontSee('Passwords do outro')
            ->assertDontSee('admin/1234');
    }

    public function test_subpagina_de_subpagina_continua_na_lista(): void
    {
        $s = CadernoSeparador::create(['cliente_id' => $this->bbs->id, 'nome' => 'SPI']);
        $a = $s->paginas()->create(['titulo' => 'A', 'ordem' => 0]);
        $b = $s->paginas()->create(['titulo' => 'B', 'pai_id' => $a->id, 'ordem' => 0]);
        $c = $s->paginas()->create(['titulo' => 'C', 'pai_id' => $b->id, 'ordem' => 0]); // dois níveis (corrida)

        Livewire::actingAs($this->rui)->test(Caderno::class, ['cliente' => $this->bbs])
            ->assertViewHas('arvore', function ($arvore) use ($a, $b, $c) {
                $vistas = collect($arvore)->flatMap(fn ($no) => [$no['pagina']->id, ...array_map(fn ($f) => $f->id, $no['filhas'])])->all();

                return in_array($c->id, $vistas, true) && in_array($b->id, $vistas, true) && in_array($a->id, $vistas, true);
            });
    }
}
