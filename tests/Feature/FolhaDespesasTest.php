<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Folha;
use App\Livewire\Despesas\Listagem;
use App\Models\Despesa;
use App\Models\FolhaDespesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Folha MENSAL de despesas por colaborador (espelho da folha Excel da empresa): grelha
// dia × coluna no ecrã, cada célula vira uma Despesa ligada à folha; PDF no mesmo formato.
class FolhaDespesasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function folhaDe(User $user, int $ano = 2026, int $mes = 7): FolhaDespesa
    {
        return FolhaDespesa::create(['user_id' => $user->id, 'ano' => $ano, 'mes' => $mes]);
    }

    public function test_abrir_folha_cria_e_reutiliza(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Listagem::class)
            ->set('novaFolhaMes', '2026-07')
            ->set('novaFolhaUserId', $admin->id)
            ->call('abrirFolha')
            ->assertHasNoErrors();

        $folha = FolhaDespesa::where('user_id', $admin->id)->where('ano', 2026)->where('mes', 7)->firstOrFail();

        // 2.ª vez: reutiliza (o unique não deixa duplicar).
        Livewire::actingAs($admin)->test(Listagem::class)
            ->set('novaFolhaMes', '2026-07')
            ->set('novaFolhaUserId', $admin->id)
            ->call('abrirFolha')
            ->assertRedirect(route('despesas.folha', $folha));

        $this->assertSame(1, FolhaDespesa::count());
    }

    public function test_grelha_grava_edita_e_apaga_celulas_como_despesas(): void
    {
        $admin = $this->admin();
        $folha = $this->folhaDe($admin);

        // Dia 5: combustível (coluna 0) + refeição (coluna 3), com descrição do dia.
        $c = Livewire::actingAs($admin)->test(Folha::class, ['folha' => $folha])
            ->set('matricula', 'BD-71-VI')
            ->set('departamento', 'Infraestruturas')
            ->set('adiantado', '50')
            ->set('linhas.5.descricao', 'ACME - Porto')
            ->set('linhas.5.valores.0', '20.50')
            ->set('linhas.5.valores.3', '12')
            ->call('guardar')
            ->assertHasNoErrors();

        $folha->refresh();
        $this->assertSame('BD-71-VI', $folha->matricula);
        $this->assertSame(2, $folha->despesas()->count());
        $this->assertDatabaseHas('despesas', [
            'folha_despesa_id' => $folha->id, 'categoria' => 'Combustíveis',
            'valor' => 20.50, 'descricao' => 'ACME - Porto', 'data' => '2026-07-05 00:00:00',
        ]);

        // Editar a célula atualiza a MESMA despesa (não duplica); esvaziar apaga.
        $c->set('linhas.5.valores.0', '25')
            ->set('linhas.5.valores.3', '')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(1, $folha->despesas()->count());
        $this->assertDatabaseHas('despesas', ['folha_despesa_id' => $folha->id, 'categoria' => 'Combustíveis', 'valor' => 25.00]);

        // Totais e saldo: 25 gastos vs 50 adiantados → devolve 25.
        $this->assertSame(25.0, $folha->total());
        $this->assertSame(25.0, $folha->aDevolver());
        $this->assertSame(0.0, $folha->aReceber());
    }

    public function test_editar_folha_pre_carrega_as_celulas_gravadas(): void
    {
        $admin = $this->admin();
        $folha = $this->folhaDe($admin);
        $folha->despesas()->create(['data' => '2026-07-10', 'categoria' => 'Hotel', 'descricao' => 'Beta - Lisboa', 'valor' => 80, 'faturavel' => false]);

        Livewire::actingAs($admin)->test(Folha::class, ['folha' => $folha])
            ->assertSet('linhas.10.valores.2', '80.00')       // Hotel = coluna 2
            ->assertSet('linhas.10.descricao', 'Beta - Lisboa');
    }

    public function test_pdf_da_folha_sai_com_logotipo_e_valores(): void
    {
        $admin = $this->admin();
        $folha = $this->folhaDe($admin);
        $folha->update(['matricula' => 'BD-71-VI', 'adiantado' => 10]);
        $folha->despesas()->create(['data' => '2026-07-05', 'categoria' => 'Refeições', 'descricao' => 'ACME - Porto', 'valor' => 12.5, 'faturavel' => false]);

        // HTML do PDF: identificação, valores e resumo.
        $html = view('pdf.folha-despesas', ['folha' => $folha])->render();
        $this->assertStringContainsString('Admin', $html);
        $this->assertStringContainsString('BD-71-VI', $html);
        $this->assertStringContainsString('ACME - Porto', $html);
        $this->assertStringContainsString('12,50', $html);
        $this->assertStringContainsString('data:image/png;base64', $html); // logótipo embebido
        $this->assertStringContainsString('a receber', $html);
        $this->assertStringContainsString('2,50', $html); // 12,50 gastos − 10 adiantados

        // Rota serve um PDF real.
        $resp = $this->actingAs($admin)->get(route('despesas.folha.pdf', $folha));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    // "Nova despesa" abre a folha do PRÓPRIO colaborador para o mês atual (cria se preciso).
    public function test_nova_despesa_abre_a_folha_do_proprio_mes(): void
    {
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->get(route('despesas.nova'));

        $folha = FolhaDespesa::where('user_id', $admin->id)
            ->where('ano', now()->year)->where('mes', now()->month)->firstOrFail();
        $resp->assertRedirect(route('despesas.folha', $folha));
    }

    // Recibos digitalizados (câmara/galeria): upload imediato + remoção. As asserções são
    // sobre os METADADOS (BD): neste ambiente o upload fake do Livewire não aterra no disco
    // que o Storage lê (mesma limitação conhecida dos testes de fotos dos relatórios); o
    // fluxo físico é o mesmo padrão das fotos, comprovado em produção.
    public function test_recibos_registam_se_e_removem_se(): void
    {
        $admin = $this->admin();
        $folha = $this->folhaDe($admin);

        $c = Livewire::actingAs($admin)->test(Folha::class, ['folha' => $folha])
            ->set('recibosNovos', [\Illuminate\Http\UploadedFile::fake()->image('recibo-almoco.jpg', 800, 600)])
            ->assertHasNoErrors();

        $recibo = $folha->anexos()->firstOrFail();
        $this->assertSame('recibo-almoco.jpg', $recibo->nome_ficheiro);
        $this->assertNotSame('', (string) $recibo->storage_key);
        $this->assertSame($admin->id, $recibo->criado_por);

        $c->call('removerRecibo', $recibo->id);
        $this->assertSame(0, $folha->anexos()->count());

        // Um ficheiro que tenha ficado no disco real é limpo (best-effort).
        \Illuminate\Support\Facades\Storage::disk()->delete($recibo->storage_key);
    }

    public function test_despesas_da_folha_entram_nos_kpis_da_listagem(): void
    {
        $admin = $this->admin();
        $folha = FolhaDespesa::create(['user_id' => $admin->id, 'ano' => now()->year, 'mes' => now()->month]);
        $folha->despesas()->create(['data' => now()->startOfMonth()->addDays(4), 'categoria' => 'Combustíveis', 'descricao' => 'X', 'valor' => 30, 'faturavel' => false]);
        Despesa::create(['data' => now(), 'categoria' => 'Outras despesas', 'descricao' => 'Avulsa', 'valor' => 20, 'faturavel' => true]);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertViewHas('kpis', fn ($k) => $k['total'] === 50.0 && $k['numero'] === 2)
            ->assertViewHas('folhas', fn ($f) => $f->contains('id', $folha->id));
    }
}
