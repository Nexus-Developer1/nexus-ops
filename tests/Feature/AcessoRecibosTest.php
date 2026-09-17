<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\Intervencao;
use App\Models\RegistoDespesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcessoRecibosTest extends TestCase
{
    use RefreshDatabase;

    private function recibo(): Anexo
    {
        Storage::fake();
        $registo = RegistoDespesa::create([]);
        $despesa = $registo->despesas()->create([
            'data' => '2026-09-17', 'descricao' => 'Almoço',
            'categoria' => 'Refeições', 'valor' => 12, 'faturavel' => false,
        ]);
        Storage::put('recibo.jpg', 'imagem de teste');

        return $despesa->anexos()->create([
            'nome_ficheiro' => 'recibo.jpg', 'storage_key' => 'recibo.jpg',
            'mime' => 'image/jpeg', 'tamanho' => 15,
        ]);
    }

    private function utilizador(PapelUtilizador $papel): User
    {
        return User::create([
            'nome' => $papel->value, 'email' => $papel->value.'@teste.pt',
            'password' => 'x', 'papel' => $papel, 'ativo' => true,
        ]);
    }

    public function test_equipa_e_financeiro_podem_abrir_recibos(): void
    {
        $recibo = $this->recibo();
        foreach ([PapelUtilizador::Admin, PapelUtilizador::Tecnico, PapelUtilizador::Financeiro] as $papel) {
            $this->actingAs($this->utilizador($papel))
                ->get(route('despesas.recibos.ver', $recibo))
                ->assertOk()->assertContent('imagem de teste')
                ->assertHeader('Content-Type', 'image/jpeg')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_financeiro_nao_acede_a_fotografias_por_nenhuma_das_rotas(): void
    {
        $anexo = $this->recibo();
        // Mesmo ID de entidade e ficheiro existente: o tipo polimórfico tem de ser verificado.
        $anexo->forceFill(['anexavel_type' => Intervencao::class])->save();
        $this->actingAs($this->utilizador(PapelUtilizador::Financeiro));
        $this->get(route('despesas.recibos.ver', $anexo))->assertNotFound();
        $this->get(route('anexos.ver', $anexo))->assertRedirect(route('despesas'));
    }

    public function test_visitantes_e_clientes_nao_acedem_a_recibos(): void
    {
        $recibo = $this->recibo();
        $this->get(route('despesas.recibos.ver', $recibo))->assertRedirect();
        $cliente = $this->utilizador(PapelUtilizador::Cliente);
        $this->actingAs($cliente)->get(route('despesas.recibos.ver', $recibo))
            ->assertRedirect(route($cliente->rotaInicial()));
    }

    public function test_recibos_de_despesas_ou_registos_eliminados_ficam_inacessiveis(): void
    {
        $recibo = $this->recibo();
        $despesa = Despesa::findOrFail($recibo->anexavel_id);
        $this->actingAs($this->utilizador(PapelUtilizador::Financeiro));
        $despesa->delete();
        $this->get(route('despesas.recibos.ver', $recibo))->assertNotFound();
        $despesa->restore();
        $despesa->registo->delete();
        $this->get(route('despesas.recibos.ver', $recibo))->assertNotFound();
    }

    public function test_ficha_e_editor_usam_a_rota_de_recibos(): void
    {
        $recibo = $this->recibo();
        $registo = Despesa::findOrFail($recibo->anexavel_id)->registo;
        $this->actingAs($this->utilizador(PapelUtilizador::Financeiro));
        foreach (['despesas.registo.ficha', 'despesas.registo.editar'] as $rota) {
            $this->get(route($rota, $registo))->assertOk()
                ->assertSee(route('despesas.recibos.ver', $recibo))
                ->assertDontSee(route('anexos.ver', $recibo));
        }
    }

    public function test_recibos_html_continuam_a_ser_download_opaco(): void
    {
        $recibo = $this->recibo();
        $recibo->update(['mime' => 'text/html', 'nome_ficheiro' => 'recibo.html']);
        $this->actingAs($this->utilizador(PapelUtilizador::Financeiro))
            ->get(route('despesas.recibos.ver', $recibo))->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Disposition', 'attachment; filename="recibo.html"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
