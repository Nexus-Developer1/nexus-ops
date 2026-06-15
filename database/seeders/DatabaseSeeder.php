<?php

namespace Database\Seeders;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Único registo criado: o utilizador administrador inicial, necessário
        // para o primeiro login. Todos os restantes dados (clientes, equipamentos,
        // contratos, intervenções, relatórios) nascem da operação real da aplicação.
        User::updateOrCreate(
            ['email' => 'admin@nexus.pt'],
            [
                'nome' => 'Admin Nexus',
                'password' => 'password',
                'papel' => PapelUtilizador::Admin,
                'ativo' => true,
            ],
        );
    }
}
