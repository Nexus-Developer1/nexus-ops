<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Anexo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Ampliação de fotografias (set. 2026, pedido da equipa): no editor de relatórios as fotos
// aparecem recortadas em quadrado e não se via o que lá estava. Clicar numa abre-a grande
// por cima da página — a camada de ampliação vive no layout, para servir qualquer ecrã.
class FotosAmpliarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_camada_de_ampliacao_esta_em_todas_as_paginas(): void
    {
        // Vem do layout: qualquer página a tem, e fica à espera do evento `ver-foto`.
        $this->actingAs($this->admin())
            ->get(route('ativos'))
            ->assertOk()
            ->assertSee('ver-foto.window', false);
    }

    public function test_fotos_do_relatorio_sao_clicaveis_para_ampliar(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'por_definir',
            'fabricante' => 'SALICRU', 'modelo' => 'SLC-1000', 'numero_serie' => 'SN-FOTO']);

        $intervencao = Intervencao::create(['equipamento_id' => $equipamento->id, 'tipo' => 'preventiva',
            'estado' => 'em_curso', 'tecnico_id' => $admin->id, 'data_inicio' => now()->toDateString()]);

        $anexo = $intervencao->anexos()->create([
            'nome_ficheiro' => 'quadro-eletrico.jpg',
            'storage_key' => 'anexos/quadro-eletrico.jpg',
            'mime' => 'image/jpeg',
            'tamanho' => 1024,
            'equipamento_id' => $equipamento->id,
        ]);

        $relatorio = $intervencao->garantirRascunho();

        $html = Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertOk()
            ->html();

        // A miniatura convida ao clique e dispara o evento com o URL da própria foto.
        $this->assertStringContainsString('cursor-zoom-in', $html);
        $this->assertStringContainsString('ver-foto', $html);
        $this->assertStringContainsString($anexo->nome_ficheiro, $html); // legenda = nome do ficheiro
        $this->assertInstanceOf(Anexo::class, $anexo);
    }
}
