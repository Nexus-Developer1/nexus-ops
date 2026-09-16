<?php

namespace Database\Seeders;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public const EMAIL_ADMIN = 'admin@nexus.pt';

    public function run(): void
    {
        // Único registo criado: o utilizador administrador inicial, necessário
        // para o primeiro login. Todos os restantes dados (clientes, equipamentos,
        // contratos, intervenções, relatórios) nascem da operação real da aplicação.
        //
        // Revisão de segurança de 16/09: (1) NUNCA reescreve uma conta que já exista — o
        // updateOrCreate antigo repunha a password para «password» se o seed corresse por
        // engano em produção; (2) em produção a password tem de vir de SEED_ADMIN_PASSWORD
        // (variável de ambiente do comando), senão o seed recusa; (3) fora de produção
        // gera-se uma password aleatória e mostra-se uma vez.
        if (User::where('email', self::EMAIL_ADMIN)->exists()) {
            $this->command?->info('Administrador inicial já existe — nada alterado.');

            return;
        }

        $password = (string) env('SEED_ADMIN_PASSWORD', '');
        if ($password === '' && app()->isProduction()) {
            $this->command?->error('Em produção o seed exige SEED_ADMIN_PASSWORD (ex.: SEED_ADMIN_PASSWORD=... php artisan db:seed).');

            return;
        }
        if ($password === '') {
            $password = Str::password(16);
        }

        User::create([
            'nome' => 'Admin Nexus',
            'email' => self::EMAIL_ADMIN,
            'password' => $password,
            'papel' => PapelUtilizador::Admin,
            'ativo' => true,
        ]);
        $this->command?->info('Administrador inicial criado: '.self::EMAIL_ADMIN.' — password: '.$password);
    }
}
