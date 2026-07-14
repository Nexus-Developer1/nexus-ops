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

// Um relatório pode ter vários técnicos: o principal (quem cria, em tecnico_id) + colaboradores
// (pivot intervencao_tecnicos). A lista de técnicos disponíveis vem da BD a cada render.
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

    public function test_principal_e_quem_cria_e_colaboradores_vao_para_o_pivot(): void
    {
        [$t1, $t2, $t3, $equip] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('colaboradorIds', [$t2->id, $t3->id])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertSame($t1->id, $interv->tecnico_id); // principal = quem cria
        $this->assertEqualsCanonicalizing(
            [$t2->id, $t3->id],
            $interv->tecnicos()->pluck('utilizadores.id')->all(), // colaboradores
        );
    }

    public function test_principal_nao_se_duplica_nos_colaboradores(): void
    {
        [$t1, $t2, , $equip] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('colaboradorIds', [$t1->id, $t2->id]) // inclui o próprio principal
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        // O principal fica só em tecnico_id; o pivot tem apenas o colaborador.
        $this->assertSame([$t2->id], Intervencao::firstOrFail()->tecnicos()->pluck('utilizadores.id')->all());
    }

    public function test_reabrir_relatorio_carrega_os_colaboradores(): void
    {
        [$t1, $t2, $t3, $equip] = $this->cenario();

        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('colaboradorIds', [$t2->id, $t3->id])
            ->call('guardarRascunho');

        $relatorio = Intervencao::firstOrFail()->relatorio;

        Livewire::actingAs($t1)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet('tecnicoPrincipalId', $t1->id)
            ->assertViewHas('tecnicoPrincipal', fn ($u) => $u?->id === $t1->id)
            ->tap(function ($c) use ($t2, $t3) {
                $this->assertEqualsCanonicalizing([$t2->id, $t3->id], $c->get('colaboradorIds'));
            });
    }

    public function test_colaborador_tem_de_ser_tecnico_ativo(): void
    {
        [$t1, , , $equip] = $this->cenario();
        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $inativo = User::create(['nome' => 'Ex', 'email' => 'ex@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => false]);

        // Admin (não é técnico) → rejeitado.
        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('colaboradorIds', [$admin->id])
            ->call('guardarRascunho')
            ->assertHasErrors('colaboradorIds.0');

        // Técnico inativo → também rejeitado.
        Livewire::actingAs($t1)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('colaboradorIds', [$inativo->id])
            ->call('guardarRascunho')
            ->assertHasErrors('colaboradorIds.0');
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
