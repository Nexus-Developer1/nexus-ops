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
