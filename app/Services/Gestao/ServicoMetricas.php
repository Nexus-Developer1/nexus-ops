<?php

namespace App\Services\Gestao;

use App\Enums\EstadoContrato;
use App\Models\Contrato;
use App\Models\Equipamento;

// Métricas de gestão para o dashboard (CLAUDE.md §6): os KPIs de resumo. Os gráficos
// (tipo/estado/visitas por mês), o cumprimento de SLA, os equipamentos sem visitas e a
// lista de renovações próximas saíram do dashboard a pedido da equipa — as métricas
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
}
