<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Um relatório pode cobrir vários equipamentos: principal (equipamento_id) + cobertos (pivot).
class RelatorioEquipamentosTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Equipamento, 2: Equipamento, 3: Equipamento} */
    private function cenario(): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $mk = fn (string $sn) => Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $sn]);

        return [$admin, $mk('SN-PRINC'), $mk('SN-EX1'), $mk('SN-EX2')];
    }

    public function test_relatorio_cobre_varios_equipamentos_sem_duplicar_o_principal(): void
    {
        [$admin, $principal, $extra1, $extra2] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('equipamento_id', $principal->id)
            ->set('data', now()->toDateString())
            ->call('adicionarEquipamentoCoberto', $extra1->id)
            ->call('adicionarEquipamentoCoberto', $extra2->id)
            ->call('adicionarEquipamentoCoberto', $principal->id) // o principal é ignorado
            ->call('adicionarEquipamentoCoberto', $extra1->id)    // repetido é ignorado
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::where('equipamento_id', $principal->id)->firstOrFail();
        $cobertos = $interv->equipamentosCobertos()->pluck('equipamentos.id')->sort()->values()->all();

        $this->assertSame([$extra1->id, $extra2->id], $cobertos);
    }

    public function test_reabrir_rascunho_carrega_os_equipamentos_cobertos(): void
    {
        [$admin, $principal, $extra1, $extra2] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('equipamento_id', $principal->id)
            ->set('data', now()->toDateString())
            ->call('adicionarEquipamentoCoberto', $extra1->id)
            ->call('adicionarEquipamentoCoberto', $extra2->id)
            ->call('guardarRascunho');

        $relatorio = Intervencao::where('equipamento_id', $principal->id)->firstOrFail()->relatorio;

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet('equipamentosCobertos', [$extra1->id, $extra2->id]);
    }

    public function test_ficha_inclui_cobertos_sem_duplicar_e_mantem_ordenacao(): void
    {
        [$admin, $a, $b] = $this->cenario(); // $a = equipamento da ficha

        // Interv 1: A é o principal (mais antiga).
        $i1 = Intervencao::create(['equipamento_id' => $a->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()->subDays(3)]);
        // Interv 2: B é principal, A é coberto (mais recente).
        $i2 = Intervencao::create(['equipamento_id' => $b->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()->subDay()]);
        $i2->equipamentosCobertos()->attach($a->id);
        // Interv 3: A é principal E coberto (não pode duplicar) — data intermédia.
        $i3 = Intervencao::create(['equipamento_id' => $a->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()->subDays(2)]);
        $i3->equipamentosCobertos()->attach($a->id);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $a])
            ->assertViewHas('intervencoes', function ($lista) use ($i1, $i2, $i3) {
                $ids = $lista->pluck('id')->all();

                return count($ids) === 3                               // os 3, sem duplicar (i3 é principal E coberto)
                    && $ids === array_values(array_unique($ids))      // sem duplicados
                    && $ids === [$i2->id, $i3->id, $i1->id];           // ordenado por data_inicio desc
            });
    }

    public function test_modo_contrato_carrega_equipamentos_do_contrato_e_liga_contrato(): void
    {
        [$admin, $e1, $e2] = $this->cenario();
        $cliente = Cliente::firstOrFail();
        $contrato = Contrato::create([
            'numero' => '2026/7001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$e1->id, $e2->id]);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();
        $this->assertSame($contrato->id, $interv->contrato_id);

        // Os 2 equipamentos do contrato ficam no relatório (principal + coberto), sem assumir ordem.
        $todos = collect([$interv->equipamento_id])
            ->merge($interv->equipamentosCobertos()->pluck('equipamentos.id'))
            ->sort()->values()->all();
        $this->assertSame([$e1->id, $e2->id], $todos);
    }

    public function test_modo_individual_nao_liga_contrato(): void
    {
        [$admin, $e1] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'individual')
            ->set('equipamento_id', $e1->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertNull(Intervencao::where('equipamento_id', $e1->id)->firstOrFail()->contrato_id);
    }

    public function test_modo_contrato_exige_contrato(): void
    {
        [$admin, $e1] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->set('equipamento_id', $e1->id) // tem equipamento mas falta o contrato
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasErrors('contrato_id');
    }
}
