<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Um visitante não autenticado é encaminhado para o login.
     */
    public function test_visitante_anonimo_e_encaminhado_para_o_portal(): void
    {
        // '/' → dashboard (protegido) → login → PORTAL da suite (a única entrada;
        // o ecrã de login desta app foi substituído por um redirect externo).
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $destino = $this->get(route('login'))->headers->get('Location');
        $this->assertStringStartsWith(rtrim(config('app.portal_url'), '/'), (string) $destino);
    }
}
