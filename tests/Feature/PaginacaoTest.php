<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Index as ClientesIndex;
use App\Livewire\Encomendas\Listagem as EncomendasListagem;
use App\Livewire\Equipamentos\Listagem as EquipamentosListagem;
use App\Models\Cliente;
use App\Models\Dossier;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Barra de páginas das listagens: é a do Livewire, tal como veio desde o primeiro commit.
//
// O que aconteceu em set. 2026 e não pode repetir-se: o Tailwind só gera as classes que
// encontra nos ficheiros do `content`, e as da barra do Livewire vivem em vendor/. Enquanto
// alguma vista nossa usou `sm:flex` a barra apareceu; no dia em que os filtros das despesas
// e dos dossiers passaram a cartão, a última ocorrência de `sm:flex` desapareceu e o bloco
// dos números (`hidden sm:flex …`) ficou escondido em TODAS as listagens. As vistas do
// paginador passaram a estar no `content` do tailwind.config.js, e o último ensaio daqui
// garante que lá ficam.
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

    public function test_a_barra_de_paginas_do_livewire_aparece_com_numeros_e_botoes(): void
    {
        $this->equipamentos(60); // 10 por página → 6 páginas

        $html = Livewire::actingAs($this->admin)->test(EquipamentosListagem::class)->html();

        $this->assertStringContainsString('aria-label="Pagination Navigation"', $html);
        $this->assertStringContainsString('gotoPage(2', $html);
        $this->assertStringContainsString('gotoPage(6', $html);
        $this->assertStringContainsString('nextPage(', $html);
    }

    public function test_com_uma_so_pagina_nao_se_mostra_barra_nenhuma(): void
    {
        $this->equipamentos(5);

        $html = Livewire::actingAs($this->admin)->test(EquipamentosListagem::class)->html();

        $this->assertStringNotContainsString('aria-label="Pagination Navigation"', $html);
    }

    // Nenhum registo pode ficar inalcançável: percorrendo as páginas vê-se tudo, incluindo o
    // último. Não se impõe a ordem da listagem — o que importa é que não falte nenhum.
    public function test_percorrendo_as_paginas_veem_se_todos_os_equipamentos(): void
    {
        $this->equipamentos(60);

        $componente = Livewire::actingAs($this->admin)->test(EquipamentosListagem::class);

        $vistos = [];
        for ($pagina = 1; $pagina <= 6; $pagina++) {
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

            $this->assertSame(10, $daPagina, "Página $pagina");
        }

        $this->assertCount(60, $vistos, 'Ficaram equipamentos por mostrar.');
    }

    public function test_os_clientes_tambem_paginam(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Cliente::create(['nome' => 'Cliente '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'ativo' => true]);
        }

        $componente = Livewire::actingAs($this->admin)->test(ClientesIndex::class);

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
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

    public function test_os_dossiers_tambem_paginam(): void
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
        $this->assertStringContainsString('aria-label="Pagination Navigation"', $componente->html());

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
            $componente->call('gotoPage', $pagina);
            $html = $componente->html();
            for ($i = 1; $i <= 30; $i++) {
                if (str_contains($html, 'Cliente do dossier '.$i.'<')) {
                    $vistos[$i] = true;
                }
            }
        }

        $this->assertCount(30, $vistos, 'Ficaram dossiers por mostrar.');
    }

    // O guarda: as vistas do paginador do Livewire têm de estar no `content` do Tailwind.
    // Se alguém as tirar, as classes da barra deixam de ser geradas e ela some outra vez —
    // e só se dá por isso quando alguém precisa da segunda página.
    public function test_o_tailwind_le_as_vistas_do_paginador_do_livewire(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        $this->assertStringContainsString(
            './vendor/livewire/livewire/src/Features/SupportPagination/views/*.blade.php',
            $config,
        );
        $this->assertFileExists(base_path('vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php'));
    }
}
