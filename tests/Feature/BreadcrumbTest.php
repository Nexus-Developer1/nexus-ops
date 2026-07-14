<?php

namespace Tests\Feature;

use Tests\TestCase;

// O breadcrumb do topbar torna os segmentos-secção anteriores clicáveis (navegar para trás),
// mantendo o último (página atual) e os rótulos sem rota como texto simples.
class BreadcrumbTest extends TestCase
{
    public function test_segmento_anterior_mapeado_vira_link(): void
    {
        $view = $this->blade('<x-topbar :breadcrumb="$b" />', ['b' => ['Relatórios', 'Novo']]);

        // "Relatórios" (não-último, mapeado) → link para a listagem.
        $view->assertSee('href="'.route('relatorios').'"', false);
        $view->assertSee('wire:navigate', false);
        $view->assertSee('>Relatórios</a>', false);

        // "Novo" (último = página atual) → texto, não link.
        $view->assertDontSee('>Novo</a>', false);
    }

    public function test_rotulo_sem_rota_fica_texto(): void
    {
        $view = $this->blade('<x-topbar :breadcrumb="$b" />', ['b' => ['Manutenção', 'Contratos', 'Novo']]);

        // "Manutenção" é um grupo de menu sem página própria → texto.
        $view->assertDontSee('>Manutenção</a>', false);
        // "Contratos" (não-último, mapeado) → link.
        $view->assertSee('href="'.route('contratos').'"', false);
    }

    public function test_item_com_url_explicito_vira_link(): void
    {
        $view = $this->blade('<x-topbar :breadcrumb="$b" />', ['b' => [
            ['label' => 'Secção X', 'url' => 'https://exemplo.test/x'],
            'Atual',
        ]]);

        $view->assertSee('href="https://exemplo.test/x"', false);
        $view->assertSee('>Secção X</a>', false);
    }
}
