<?php

namespace App\Services\Erp;

// Driver de teste — devolve clientes fictícios (sem ligação ao ERP real).
// Usado em desenvolvimento e nos testes do sync.
class FakeErpDriver implements ErpSyncDriver
{
    public function obterClientes(): iterable
    {
        return [
            new ClienteErp('ERP-001', 'Central Elétrica Norte', '501234567', 'geral@cen.pt', '+351 22 000 0001', 'Rua da Energia 1, Porto'),
            new ClienteErp('ERP-002', 'Datacenter Lisboa', '502345678', 'noc@dclisboa.pt', '+351 21 000 0002', 'Av. das Telecom 200, Lisboa'),
            new ClienteErp('ERP-003', 'Hospital Sul', '503456789', 'tecnica@hsul.pt', '+351 28 000 0003', 'Estrada da Saúde 7, Faro'),
            new ClienteErp('ERP-004', 'Fábrica Aveiro', '504567890', 'manutencao@fabaveiro.pt', '+351 23 000 0004', 'Zona Industrial, Aveiro'),
            new ClienteErp('ERP-005', 'Banco Central', '505678901', 'instalacoes@bcentral.pt', '+351 21 000 0005', 'Praça da Banca 1, Lisboa'),
        ];
    }
}
