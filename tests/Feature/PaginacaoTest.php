<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Index as ClientesIndex;
use App\Livewire\Concerns\Paginacao;
use App\Livewire\Encomendas\Listagem as EncomendasListagem;
use App\Livewire\Equipamentos\Listagem as EquipamentosListagem;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\WithPagination;
use Tests\TestCase;

// Barra de páginas das listagens (set. 2026).
//
// A que vinha com o Livewire saía sem forma nenhuma nesta aplicação: o Tailwind só gera as
// classes que encontra em resources/ e app/ (ver `content` no tailwind.config.js) e as dela
// vivem em vendor/ — ficava um rasto de texto sem botões, sem caixas e sem cor, no fundo das
// listagens grandes (equipamentos, clientes, dossiers).
class PaginacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function equipamentos(int $quantos): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);

        for ($i = 1; $i <= $quantos; $i++) {
            Equipamento::create([
                'local_id' => $local->id,
                'tipo' => 'ups',
                'estado' => 'operacional',
                'numero_serie' => 'SN-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
        }
    }

    // O essencial: com mais equipamentos do que cabem numa página, a barra aparece MESMO
    // (com números e botões), e não uma caixa vazia como acontecia.
    public function test_a_barra_de_paginas_aparece_com_numeros_e_botoes(): void
    {
        $this->equipamentos(60); // 25 por página → 3 páginas

        $html = Livewire::actingAs($this->admin)->test(EquipamentosListagem::class)->html();

        $this->assertStringContainsString('Navegação por páginas', $html);
        $this->assertStringContainsString('Seguinte', $html);
        $this->assertStringContainsString('gotoPage(2', $html);
        $this->assertStringContainsString('gotoPage(3', $html);
        $this->assertStringContainsString('1–25', $html);
        $this->assertStringContainsString('de 60', $html);

        // Poucas páginas: os números à vista, sem a caixa de escrever o número.
        $this->assertStringNotContainsString('id="pagina-page"', $html);
    }

    // Nenhum registo pode ficar inalcançável: percorrendo as páginas vê-se tudo, incluindo o
    // último. É isto que o pedido da equipa exigia ("não pode ficar nada de fora").
    public function test_percorrendo_as_paginas_veem_se_todos_os_equipamentos(): void
    {
        $this->equipamentos(60);

        $componente = Livewire::actingAs($this->admin)->test(EquipamentosListagem::class);

        // Percorre as três páginas e junta o que viu. Não se impõe a ordem da listagem: o
        // que importa é que, no fim, não falte nenhum equipamento.
        $vistos = [];
        for ($pagina = 1; $pagina <= 3; $pagina++) {
            $componente->call('gotoPage', $pagina);
            $html = $componente->html();
            $daPagina = 0;

            for ($i = 1; $i <= 60; $i++) {
                $serie = 'SN-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                if (str_contains($html, $serie)) {
                    $vistos[$serie] = true;
                    $daPagina++;
                }
            }

            $this->assertSame($pagina === 3 ? 10 : 25, $daPagina, "Página $pagina");
        }

        $this->assertCount(60, $vistos, 'Ficaram equipamentos por mostrar.');
    }

    public function test_com_uma_so_pagina_nao_se_mostra_barra_nenhuma(): void
    {
        $this->equipamentos(5);

        $html = Livewire::actingAs($this->admin)->test(EquipamentosListagem::class)->html();

        $this->assertStringNotContainsString('Navegação por páginas', $html);
    }

    public function test_os_clientes_tambem_paginam(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Cliente::create(['nome' => 'Cliente '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'ativo' => true]);
        }

        $componente = Livewire::actingAs($this->admin)->test(ClientesIndex::class);

        $vistos = [];
        foreach ([1, 2] as $pagina) {
            $componente->call('gotoPage', $pagina);
            $html = $componente->html();
            for ($i = 1; $i <= 30; $i++) {
                $nome = 'Cliente '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                if (str_contains($html, $nome)) {
                    $vistos[$nome] = true;
                }
            }
        }

        $this->assertCount(30, $vistos, 'Ficaram clientes por mostrar.');
    }

    // Os dossiers são a listagem maior de todas (mais de 200 000 no servidor): é onde a
    // caixa de salto faz falta, porque de «seguinte» em «seguinte» não se lá chega.
    public function test_os_dossiers_tambem_paginam_e_oferecem_salto_de_pagina(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Dossier::create([
                'id_erp' => 'D'.$i,
                'ndos' => 3,
                'obrano' => 1000 + $i,
                'nome' => 'Cliente do dossier '.$i,
                'ano' => 2026,
                'fechada' => false,
            ]);
        }

        $componente = Livewire::actingAs($this->admin)->test(EncomendasListagem::class);
        $html = $componente->html();

        $this->assertStringContainsString('Navegação por páginas', $html);
        $this->assertStringContainsString('1–25', $html);

        $vistos = [];
        foreach ([1, 2] as $pagina) {
            $componente->call('gotoPage', $pagina);
            $pagina = $componente->html();
            for ($i = 1; $i <= 30; $i++) {
                if (str_contains($pagina, 'Cliente do dossier '.$i.'<')) {
                    $vistos[$i] = true;
                }
            }
        }

        $this->assertCount(30, $vistos, 'Ficaram dossiers por mostrar.');
    }

    // Com muitas páginas a barra muda de feição: em vez de alinhar 700 números, fica com
    // primeira/anterior, a página actual numa caixa que se escreve, e seguinte/última.
    public function test_com_muitas_paginas_a_barra_fica_compacta(): void
    {
        for ($i = 1; $i <= 200; $i++) {
            Cliente::create(['nome' => 'Cliente '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'ativo' => true]);
        }

        $componente = Livewire::actingAs($this->admin)->test(ClientesIndex::class);
        $html = $componente->html();

        $this->assertStringContainsString('id="pagina-page"', $html);   // caixa da página
        $this->assertStringContainsString('de 8', $html);               // 200 / 25
        $this->assertStringContainsString('Última página', $html);
        $this->assertStringNotContainsString('gotoPage(5,', $html);     // sem parede de números

        // A caixa e os saltos levam mesmo à página pedida.
        $componente->call('gotoPage', 8)->assertSee('Cliente 200');
    }

    // Uma listagem nova que se esqueça do trait volta a ficar sem barra de páginas — e isso
    // só se nota quando alguém precisa da segunda página. Falha já aqui.
    public function test_todas_as_listagens_que_paginam_usam_a_barra_da_casa(): void
    {
        $semTrait = [];

        foreach (glob(app_path('Livewire/*/*.php')) as $ficheiro) {
            $classe = 'App\\Livewire\\'.Str::of($ficheiro)
                ->after(app_path('Livewire').DIRECTORY_SEPARATOR)
                ->replace(['/', DIRECTORY_SEPARATOR], '\\')
                ->replace('.php', '')
                ->toString();

            if (! class_exists($classe)) {
                continue;
            }

            $usa = class_uses_recursive($classe);
            if (in_array(WithPagination::class, $usa, true) && ! in_array(Paginacao::class, $usa, true)) {
                $semTrait[] = $classe;
            }
        }

        $this->assertSame([], $semTrait, 'Listagens sem a barra de páginas da casa: '.implode(', ', $semTrait));
    }
}
