<?php

namespace App\Services\Gestao;

use App\Enums\EstadoContrato;
use App\Models\Contrato;
use App\Models\Equipamento;
use Illuminate\Support\Collection;

// Métricas de gestão para o dashboard (CLAUDE.md §6): KPIs de resumo e renovações
// próximas. Os gráficos (tipo/estado/visitas por mês), o cumprimento de SLA e os
// equipamentos sem visitas saíram do dashboard a pedido da equipa — as métricas
// respetivas foram removidas com eles (recuperáveis no histórico do git).
class ServicoMetricas
{
    /** @return array<string, mixed> */
    public function resumo(): array
    {
        return [
            'contratos_ativos' => Contrato::where('estado', EstadoContrato::Ativo->value)->count(),
            'equipamentos' => Equipamento::count(),
            'renovacoes' => Contrato::aExpirar()->count(),
        ];
    }

    // Contratos ativos a expirar (lista, para o painel de renovações).
    public function renovacoesProximas(int $limite = 5): Collection
    {
        return Contrato::aExpirar()->with('cliente')->orderBy('data_fim')->limit($limite)->get();
    }
}
