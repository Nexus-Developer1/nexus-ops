<?php

namespace App\Services\Erp;

// Driver de teste — gera clientes fictícios pt_PT plausíveis, sem ligação ao ERP
// real e sem dependências externas (o Faker está só em require-dev, ausente em
// runtime). A geração é DETERMINÍSTICA por índice: a mesma posição produz sempre
// o mesmo cliente (mesmo id_erp), tornando o upsert por id_erp reproduzível e
// testável (2.ª corrida = atualizações, não duplicados).
class FakeErpDriver implements ErpSyncDriver
{
    // Quantos clientes gerar quando não é passado --limit.
    private const PADRAO = 10;

    // Semente base fixa — reprodutibilidade entre execuções.
    private const SEMENTE = 4242;

    public function obterClientes(?int $limite = null): iterable
    {
        $n = max(1, $limite ?? self::PADRAO);

        for ($i = 0; $i < $n; $i++) {
            yield $this->gerarCliente($i);
        }
    }

    public function obterLinhasFatura(?int $limite = null): iterable
    {
        $n = max(1, $limite ?? self::PADRAO);

        // Simula o WHERE series NOT LIKE '' do PHC: geramos candidatas (algumas sem série)
        // mas só devolvemos as que TÊM nº de série — tal como a query real.
        for ($i = 0; $i < $n; $i++) {
            $linha = $this->gerarLinhaFatura($i);

            if (blank($linha->series)) {
                continue; // sem série → filtrada (equipamentos físicos apenas)
            }

            yield $linha;
        }
    }

    public function obterEquipamentos(?int $limite = null): iterable
    {
        $n = max(1, $limite ?? self::PADRAO);

        // Simula o WHERE marca LIKE '%RIELLO%' do PHC: geramos candidatos (alguns de outra marca)
        // mas só devolvemos os RIELLO — tal como a query real filtra server-side.
        for ($i = 0; $i < $n; $i++) {
            $equip = $this->gerarEquipamento($i);

            if (! str_contains(mb_strtoupper((string) $equip->marca), 'RIELLO')) {
                continue; // outra marca → filtrada (só Riello entra)
            }

            yield $equip;
        }
    }

    private function gerarEquipamento(int $i): EquipamentoErp
    {
        // Determinístico por índice — o equipamento i é sempre igual (mesmo mastamp), tornando o
        // upsert por mastamp reproduzível (2.ª corrida = atualizações, não duplicados).
        mt_srand(self::SEMENTE + 8000 + $i);

        $modelos = ['UPS RIELLO NPW 2000VA', 'UPS RIELLO SDU 10000VA', 'UPS RIELLO VST 1100', 'UPS RIELLO MST 8000', 'UPS RIELLO SEP 3300'];

        // 1 em cada 4 candidatos é de outra marca (será filtrado pelo WHERE marca).
        $marca = ($i % 4) === 3 ? 'EATON' : 'RIELLO';
        $idx = mt_rand(0, count($modelos) - 1);

        // Famílias variadas (simula st.familia/st.faminome): a maioria são UPS, mas 1 em cada 5 é
        // "peças/reparação" — o cenário que motiva o filtro por família.
        $ehPeca = ($i % 5) === 4;
        [$familia, $faminome] = $ehPeca ? ['920', 'Peças / Reparação'] : ['100', 'UPS'];

        return new EquipamentoErp(
            idErp: sprintf('MA25%010d,%07d-%d', $i, mt_rand(1000000, 9999999), mt_rand(0, 9)), // ma.mastamp único por i
            numeroSerie: sprintf('MH%02dVNPW%07d', mt_rand(10, 30), mt_rand(1, 9999999)),
            modelo: $modelos[$idx],
            dataInstalacao: sprintf('20%02d-%02d-%02d', mt_rand(18, 24), mt_rand(1, 12), mt_rand(1, 28)),
            clienteNo: (string) (1000 + ($i % 10)), // liga aos clientes fake (id_erp 1000..1009)
            marca: $marca,
            familia: $familia,
            faminome: $faminome,
        );
    }

    private function gerarLinhaFatura(int $i): LinhaFaturaErp
    {
        // Determinístico por índice — a linha i é sempre igual (mesmo fistamp), tornando o
        // upsert por id_erp reproduzível (2.ª corrida = atualizações, não duplicados).
        mt_srand(self::SEMENTE + 5000 + $i);

        $docs = ['Factura', 'Fatura-Recibo', 'V/Factura', 'Nota de Crédito'];
        $refs = ['UPS-RIELLO-NPW', 'GER-CAT-9KVA', 'PDU-APC-16A', 'UPS-EATON-9PX', 'BAT-12V-9AH'];
        $designs = ['UPS RIELLO NPW 2000VA INTERATIVA - TOWER', 'Gerador CAT 9kVA', 'PDU APC 16A', 'UPS Eaton 9PX 6kVA', 'Bateria 12V 9Ah'];

        // 1 em cada 4 linhas sem número de série (será filtrada pelo WHERE series).
        $temSerie = ($i % 4) !== 3;
        $idx = mt_rand(0, count($refs) - 1);

        return new LinhaFaturaErp(
            idErp: sprintf('NV25%010d,%07d-%d', $i, mt_rand(1000000, 9999999), mt_rand(0, 9)), // fi.fistamp único por i
            clienteNo: (string) (1000 + ($i % 10)), // liga aos clientes fake (id_erp 1000..1009)
            nmdoc: $docs[mt_rand(0, count($docs) - 1)],
            fno: 1000 + $i,
            data: sprintf('2025-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)),
            ref: $refs[$idx],
            design: $designs[$idx],
            series: $temSerie ? sprintf('MH%02dVNPW%07d', mt_rand(10, 30), mt_rand(1, 9999999)) : '',
            qtt: (float) mt_rand(1, 5),
        );
    }

    private function gerarCliente(int $i): ClienteErp
    {
        // Determinístico por índice — o cliente i é sempre igual, independente do total.
        mt_srand(self::SEMENTE + $i);

        $tipos = ['Datacenter', 'Hospital', 'Clínica', 'Fábrica', 'Hipermercado', 'Banco', 'Universidade', 'Hotel', 'Centro Logístico', 'Câmara Municipal', 'Operadora Telecom', 'Centro Comercial'];
        $cidades = ['Lisboa', 'Porto', 'Braga', 'Coimbra', 'Faro', 'Aveiro', 'Évora', 'Setúbal', 'Leiria', 'Viseu', 'Funchal', 'Guimarães', 'Cascais', 'Sintra'];
        $primeiros = ['João', 'Maria', 'Pedro', 'Ana', 'Rui', 'Sofia', 'Miguel', 'Inês', 'Tiago', 'Beatriz', 'Nuno', 'Catarina', 'André', 'Marta', 'Luís', 'Carla'];
        $apelidos = ['Silva', 'Santos', 'Ferreira', 'Pereira', 'Oliveira', 'Costa', 'Rodrigues', 'Martins', 'Sousa', 'Fernandes', 'Gonçalves', 'Gomes', 'Lopes', 'Marques', 'Almeida'];
        $tiposRua = ['Rua', 'Avenida', 'Praça', 'Travessa', 'Largo', 'Estrada'];
        $nomesRua = ['da Liberdade', 'das Indústrias', 'do Comércio', 'de Santo António', 'da República', '25 de Abril', 'dos Combatentes', 'do Infante', 'das Flores', 'da Estação'];
        $sufixos = ['Lda.', 'S.A.', ''];

        $tipo = $tipos[mt_rand(0, count($tipos) - 1)];
        $cidade = $cidades[mt_rand(0, count($cidades) - 1)];
        $sufixo = $sufixos[mt_rand(0, count($sufixos) - 1)];
        $moveis = [1, 2, 3, 6]; // prefixos de telemóvel pt (91/92/93/96)

        return new ClienteErp(
            idErp: (string) (1000 + $i),                                    // PHC cl.no
            nome: trim("{$tipo} {$cidade} {$sufixo}"),
            nif: '5' . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
            email: 'geral@' . $this->slug($tipo . $cidade) . '.pt',
            telefone: sprintf('+351 2%d %03d %03d', mt_rand(1, 9), mt_rand(100, 999), mt_rand(100, 999)),
            morada: $tiposRua[mt_rand(0, count($tiposRua) - 1)] . ' ' . $nomesRua[mt_rand(0, count($nomesRua) - 1)] . ', ' . mt_rand(1, 250) . ', ' . $cidade,
            codpost: sprintf('%04d-%03d', mt_rand(1000, 9499), mt_rand(1, 999)),
            tlmvl: sprintf('+351 9%d %03d %03d', $moveis[mt_rand(0, 3)], mt_rand(100, 999), mt_rand(100, 999)),
            vendedor: mt_rand(1, 15),
            vendnm: $primeiros[mt_rand(0, count($primeiros) - 1)] . ' ' . $apelidos[mt_rand(0, count($apelidos) - 1)],
        );
    }

    // Slug ASCII (remove acentos e não-alfanuméricos) para domínios de email.
    private function slug(string $texto): string
    {
        $de = ['á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç'];
        $para = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c'];

        $t = mb_strtolower(str_replace($de, $para, $texto));

        return preg_replace('/[^a-z0-9]/', '', $t);
    }
}
