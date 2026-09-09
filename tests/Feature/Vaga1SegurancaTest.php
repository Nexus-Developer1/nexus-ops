<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Auditoria\Listagem;
use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 1 (segurança): mudar a palavra-passe invalida as sessões antigas, e a auditoria
// é pesquisável pelo id da entidade. A política de passwords e o convite mudaram-se
// para o portal da suite (set. 2026), com eles os testes respetivos.
class Vaga1SegurancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mudar_password_invalida_sessoes_antigas(): void
    {
        $user = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'antiga12345', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Sessão autenticada ANTES da mudança de password (marca antiga).
        $this->actingAs($user);
        session(['autenticado_em' => now()->subHour()->timestamp]);
        $this->get('/equipamentos')->assertOk(); // sessão válida

        // A password muda (noutro dispositivo) → esta sessão é expulsa no pedido seguinte.
        $user->forceFill(['password_alterada_em' => now()])->save();
        $this->get('/equipamentos')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sessao_autenticada_depois_da_mudanca_sobrevive(): void
    {
        $user = User::create(['nome' => 'Téc', 'email' => 't2@nexus.pt', 'password' => 'antiga12345', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $user->forceFill(['password_alterada_em' => now()->subHour()])->save();

        $this->actingAs($user);
        session(['autenticado_em' => now()->timestamp]); // login DEPOIS da mudança

        $this->get('/equipamentos')->assertOk();
    }

    public function test_auditoria_pesquisa_por_id_de_entidade(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        Auditoria::create(['acao' => 'contrato_mudou_estado', 'entidade_tipo' => 'Contrato', 'entidade_id' => 42, 'detalhe' => ['numero' => 'C-42']]);
        Auditoria::create(['acao' => 'contrato_mudou_estado', 'entidade_tipo' => 'Contrato', 'entidade_id' => 99, 'detalhe' => ['numero' => 'C-99']]);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->set('pesquisa', '#42')
            ->assertSee('Contrato #42')
            ->assertDontSee('Contrato #99');
    }
}
