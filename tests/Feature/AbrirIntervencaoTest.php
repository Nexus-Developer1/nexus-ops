<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Abrir intervenção" abre o editor de relatório novo (fonte única). Garante um rascunho
// e redireciona — para qualquer ponto de entrada (agenda, painel, ficha do ativo, alertas).
class AbrirIntervencaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function intervencao(): Intervencao
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);

        return Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada', 'data_inicio' => now()]);
    }

    public function test_intervencao_sem_relatorio_cria_rascunho_e_redireciona(): void
    {
        $interv = $this->intervencao();
        $this->assertNull($interv->relatorio); // ainda não tem relatório

        $resp = $this->actingAs($this->admin())->get(route('intervencoes.formulario', $interv));

        // Cria o rascunho e redireciona para o editor desse relatório.
        $relatorio = $interv->fresh()->relatorio;
        $this->assertNotNull($relatorio);
        $this->assertSame('rascunho', $relatorio->estado->value);
        $this->assertNull($relatorio->numero); // rascunho sem número
        $resp->assertRedirect(route('relatorios.editar', $relatorio));
    }

    public function test_intervencao_com_relatorio_redireciona_sem_duplicar(): void
    {
        $interv = $this->intervencao();
        $existente = $interv->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);

        $resp = $this->actingAs($this->admin())->get(route('intervencoes.formulario', $interv));

        $resp->assertRedirect(route('relatorios.editar', $existente));
        $this->assertSame(1, Relatorio::where('intervencao_id', $interv->id)->count()); // não duplicou
    }

    public function test_intervencao_com_relatorio_eliminado_nao_ressuscita(): void
    {
        $interv = $this->intervencao();
        $relatorio = $interv->relatorio()->create(['estado' => 'rascunho', 'data' => now()]);
        $relatorio->delete(); // soft delete

        $resp = $this->actingAs($this->admin())->get(route('intervencoes.formulario', $interv));

        // NÃO cria um novo relatório (continua 1 no total, incluindo o eliminado — não passa a 2).
        $this->assertSame(1, Relatorio::withTrashed()->where('intervencao_id', $interv->id)->count());
        $this->assertSame(0, Relatorio::where('intervencao_id', $interv->id)->count()); // nenhum vivo

        // Redireciona para a listagem com a mensagem, sem abrir o editor.
        $resp->assertRedirect(route('relatorios'));
        $resp->assertSessionHas('erro', 'O relatório desta intervenção foi eliminado.');
    }
}
