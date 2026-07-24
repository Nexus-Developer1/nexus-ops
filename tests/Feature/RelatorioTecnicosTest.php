<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Um relatório pode ter vários técnicos, SEM hierarquia (não existe "técnico principal"), e
// quem o REDIGE não é necessariamente quem fez a intervenção — nada vem pré-selecionado.
// Armazenamento (detalhe interno): o 1.º por ordem alfabética fica em tecnico_id e os
// restantes no pivot intervencao_tecnicos; a UI e o PDF mostram a lista completa sem distinção.
class RelatorioTecnicosTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: User, 3: Equipamento} */
    private function cenario(): array
    {
        $tecnico = fn (string $email) => User::create(['nome' => strtoupper($email[0]).$email, 'email' => $email, 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $t1 = $tecnico('t1@nexus.pt');
        $t2 = $tecnico('t2@nexus.pt');
        $t3 = $tecnico('t3@nexus.pt');

        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        return [$t1, $t2, $t3, $equip];
    }

    public function test_novo_relatorio_nao_pre_seleciona_quem_cria(): void
    {
        [$t1] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->assertSet('tecnicoIds', []);
    }

    public function test_quem_redige_pode_nao_estar_entre_os_tecnicos(): void
    {
        [$t1, $t2, $t3, $equip] = $this->cenario();

        // t1 redige, mas a intervenção foi feita por t2 e t3.
        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$t3->id, $t2->id])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertSame($t2->id, $interv->tecnico_id); // 1.º por ordem alfabética (armazenamento)
        $this->assertSame([$t3->id], $interv->tecnicos()->pluck('utilizadores.id')->all());
        $this->assertNotContains($t1->id, [$interv->tecnico_id, ...$interv->tecnicos()->pluck('utilizadores.id')]);
    }

    public function test_tecnico_nao_se_duplica_entre_coluna_e_pivot(): void
    {
        [$t1, $t2, , $equip] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$t1->id, $t2->id])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        // O 1.º alfabético (t1) fica só em tecnico_id; o pivot tem apenas o outro.
        $interv = Intervencao::firstOrFail();
        $this->assertSame($t1->id, $interv->tecnico_id);
        $this->assertSame([$t2->id], $interv->tecnicos()->pluck('utilizadores.id')->all());
    }

    public function test_editar_relatorio_atualiza_os_tecnicos_pela_selecao(): void
    {
        [$t1, $t2, $t3, $equip] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$t2->id])
            ->call('guardarRascunho');

        $relatorio = Intervencao::firstOrFail()->relatorio;

        // Reabrir carrega a seleção gravada; mudar a seleção substitui os técnicos gravados.
        Livewire::actingAs($t1)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet('tecnicoIds', [$t2->id])
            ->set('tecnicoIds', [$t3->id])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertSame($t3->id, $interv->tecnico_id);
        $this->assertSame([], $interv->tecnicos()->pluck('utilizadores.id')->all());
    }

    public function test_finalizar_exige_pelo_menos_um_tecnico(): void
    {
        [$t1, , , $equip] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->call('finalizar')
            ->assertHasErrors('tecnicoIds');

        // Em rascunho não é obrigatório.
        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();
        $this->assertNull(Intervencao::firstOrFail()->tecnico_id);
    }

    public function test_tecnico_selecionado_tem_de_ser_tecnico_ativo(): void
    {
        [$t1, , , $equip] = $this->cenario();
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $inativo = User::create(['nome' => 'Ex', 'email' => 'ex@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => false]);

        // Admin (não é técnico) → rejeitado.
        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$admin->id])
            ->call('guardarRascunho')
            ->assertHasErrors('tecnicoIds.0');

        // Técnico inativo → também rejeitado.
        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$inativo->id])
            ->call('guardarRascunho')
            ->assertHasErrors('tecnicoIds.0');
    }

    public function test_lista_de_tecnicos_disponiveis_e_dinamica(): void
    {
        [$t1, $t2, $t3, $equip] = $this->cenario();

        // A view recebe todos os técnicos ativos (reflete quem for entrando).
        Livewire::actingAs($t1)->test(Novo::class)
            ->assertViewHas('tecnicos', fn ($lista) => $lista->pluck('id')->contains($t2->id) && $lista->pluck('id')->contains($t3->id));

        // Entra um novo técnico → aparece na lista sem qualquer alteração de código.
        $t4 = User::create(['nome' => 'T4', 'email' => 't4@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        Livewire::actingAs($t1)->test(Novo::class)
            ->assertViewHas('tecnicos', fn ($lista) => $lista->pluck('id')->contains($t4->id));
    }
}
