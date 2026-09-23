<?php
/**
 * Suite 02 :: invariantes de topologia (3b.1) impostas pelo BANCO.
 *
 * Aqui nao se testa o lib/: testa-se que, mesmo que alguem escreva SQL direto,
 * o banco RECUSA os estados invalidos conhecidos. E a rede de seguranca de baixo.
 */

T::suite('Invariantes no banco');

// ---------------------------------------------------------------- cenario minimo
Db::exec('INSERT INTO tab_ftth_regiao (nome, lat, lng, criado_por, criado_em) VALUES (?,?,?,?,NOW())',
    ['Palmital', -24.8847, -52.2093, 'teste']);
$regiao = Db::ultimoId();

$criarCaixa = function (string $tipo, string $nome, float $lat, float $lng) use ($regiao): int {
    Db::exec('INSERT INTO tab_ftth_caixa (regiao_id, tipo, nome, lat, lng, criado_por, criado_em)
              VALUES (?,?,?,?,?,?,NOW())', [$regiao, $tipo, $nome, $lat, $lng, 'teste']);
    return Db::ultimoId();
};

$pop  = $criarCaixa('DC',  'Pop1',       -24.8800, -52.2100);
$ceo  = $criarCaixa('CEO', 'CEO.01/02',  -24.8820, -52.2110);
$cto  = $criarCaixa('CTO', 'CTO.02.05',  -24.8816, -52.2145);

$tipo6 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');
Db::exec('INSERT INTO tab_ftth_cabo (regiao_id, cabo_tipo_id, nome, criado_por, criado_em)
          VALUES (?,?,?,?,NOW())', [$regiao, $tipo6, 'Rota 02', 'teste']);
$cabo = Db::ultimoId();

$criarVao = function (int $ini, int $fim, array $verts) use ($cabo, $regiao): int {
    $geo = Geo::comprimento($verts);
    Db::exec('INSERT INTO tab_ftth_cabo_vao
                (cabo_id, regiao_id, ordem, caixa_ini_id, caixa_fim_id, vertices,
                 comprimento_geo, comprimento_optico, criado_por, criado_em)
              VALUES (?,?,?,?,?,?,?,?,?,NOW())',
        [$cabo, $regiao, 1, $ini, $fim, json_encode($verts), $geo,
         Geo::comprimentoOptico($geo, 1.03, 0), 'teste']);
    return Db::ultimoId();
};

$vaoA = $criarVao($pop, $ceo, [[-24.8800, -52.2100], [-24.8810, -52.2105], [-24.8820, -52.2110]]);
$vaoB = $criarVao($ceo, $cto, [[-24.8820, -52.2110], [-24.8816, -52.2145]]);

T::certo('cenario minimo criado', $vaoA > 0 && $vaoB > 0);

// ---------------------------------------------------------------- I4: ponta unica
$ligar = function (int $caixa, array $a, array $b, string $tipo = 'FUSAO'): int {
    return Db::transacao(function () use ($caixa, $a, $b, $tipo) {
        Db::exec('INSERT INTO tab_ftth_ligacao (caixa_id, tipo, criado_por, criado_em) VALUES (?,?,?,NOW())',
            [$caixa, $tipo, 'teste']);
        $lig = Db::ultimoId();
        foreach ([['A', $a], ['B', $b]] as [$lado, $p]) {
            Db::exec('INSERT INTO tab_ftth_ligacao_ponta (ligacao_id, lado, caixa_id, elemento, elemento_id, numero)
                      VALUES (?,?,?,?,?,?)', [$lig, $lado, $caixa, $p[0], $p[1], $p[2]]);
        }
        return $lig;
    });
};

$lig1 = $ligar($ceo, ['VAO_FIBRA', $vaoA, 2], ['VAO_FIBRA', $vaoB, 2]);
T::certo('liga fibra 2 do vao A na fibra 2 do vao B', $lig1 > 0);

T::recusa('I4: recusa conectar a MESMA fibra de novo',
    fn() => $ligar($ceo, ['VAO_FIBRA', $vaoA, 2], ['VAO_FIBRA', $vaoB, 3]),
    'uq_ponta_unica');

// ---------------------------------------------------------------- I3: exatamente 2 pontas
T::recusa('I3: recusa terceira ponta na mesma ligacao', function () use ($lig1, $ceo, $vaoB) {
    Db::exec('INSERT INTO tab_ftth_ligacao_ponta (ligacao_id, lado, caixa_id, elemento, elemento_id, numero)
              VALUES (?,?,?,?,?,?)', [$lig1, 'A', $ceo, 'VAO_FIBRA', $vaoB, 5]);
}, 'uq_lado');

// ---------------------------------------------------------------- splitter e clientes
Db::exec('INSERT INTO tab_ftth_splitter (caixa_id, nome, funcao, modelo, razao, saidas, perdas_json, criado_por, criado_em)
          VALUES (?,?,?,?,?,?,?,?,NOW())',
    [$cto, 'SPT.02.05', 'ATENDIMENTO', 'BAL', '1:8', 8,
     json_encode(Config::perdasSplitter('BAL', '1:8')), 'teste']);
$spt = Db::ultimoId();

Db::exec('INSERT INTO tab_ftth_splitter (caixa_id, nome, funcao, modelo, razao, saidas, perdas_json, criado_por, criado_em)
          VALUES (?,?,?,?,?,?,?,?,NOW())',
    [$ceo, 'SPT.DER.01', 'DERIVACAO', 'DESBAL', '10/90', 2,
     json_encode(Config::perdasSplitter('DESBAL', '10/90')), 'teste']);
$sptDer = Db::ultimoId();

T::certo('perdas do 1:8 vieram do catalogo com 8 saidas',
    count(json_decode((string) Db::valor('SELECT perdas_json FROM tab_ftth_splitter WHERE id = ?', [$spt]), true)) === 8);

Db::exec('INSERT INTO tab_ftth_porta (splitter_id, numero, cliente_id, login, criado_por, criado_em)
          VALUES (?,?,?,?,?,NOW())', [$spt, 3, 1001, 'marcelo', 'teste']);
T::certo('vincula cliente na saida 3', (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta') === 1);

T::recusa('I6: recusa segundo cliente na MESMA saida', function () use ($spt) {
    Db::exec('INSERT INTO tab_ftth_porta (splitter_id, numero, cliente_id, login, criado_por, criado_em)
              VALUES (?,?,?,?,?,NOW())', [$spt, 3, 1002, 'josi', 'teste']);
}, 'uq_splitter_porta');

T::recusa('recusa o MESMO cliente em duas portas', function () use ($spt) {
    Db::exec('INSERT INTO tab_ftth_porta (splitter_id, numero, cliente_id, login, criado_por, criado_em)
              VALUES (?,?,?,?,?,NOW())', [$spt, 4, 1001, 'marcelo', 'teste']);
}, 'uq_cliente');

// ---------------------------------------------------------------- I7: porta de DIO
Db::exec('INSERT INTO tab_ftth_dio (caixa_id, nome, portas, criado_por, criado_em) VALUES (?,?,?,?,NOW())',
    [$pop, 'DIO 01', 72, 'teste']);
$dio = Db::ultimoId();
Db::exec('INSERT INTO tab_ftth_dio_porta (dio_id, numero, pon, servico, criado_por, criado_em)
          VALUES (?,?,?,?,?,NOW())', [$dio, 1, '1/1/5', 'CEO.01', 'teste']);
$dioPorta = Db::ultimoId();

T::recusa('I7: recusa duas portas com o mesmo numero no DIO', function () use ($dio) {
    Db::exec('INSERT INTO tab_ftth_dio_porta (dio_id, numero, criado_por, criado_em) VALUES (?,?,?,NOW())',
        [$dio, 1, 'teste']);
}, 'uq_dio_porta');

$ligDio = $ligar($pop, ['DIO_PORTA', $dioPorta, 0], ['VAO_FIBRA', $vaoA, 1]);
T::certo('liga porta do DIO na fibra 1', $ligDio > 0);

// A fibra tem DUAS pontas, uma em cada caixa. A fibra 2 ja esta ligada na CEO;
// ligar a outra ponta dela no POP e legitimo e nao pode ser bloqueado.
Db::exec('INSERT INTO tab_ftth_dio_porta (dio_id, numero, pon, servico, criado_por, criado_em)
          VALUES (?,?,?,?,?,NOW())', [$dio, 2, '1/1/6', 'CEO.02', 'teste']);
$dioPorta2 = Db::ultimoId();
$ligOutraPonta = $ligar($pop, ['DIO_PORTA', $dioPorta2, 0], ['VAO_FIBRA', $vaoA, 2]);
T::certo('mesma fibra pode ser ligada tambem na caixa da outra ponta', $ligOutraPonta > 0);

T::recusa('I4: recusa usar a porta do DIO em outra ligacao',
    fn() => $ligar($pop, ['DIO_PORTA', $dioPorta, 0], ['VAO_FIBRA', $vaoA, 4]),
    'uq_ponta_unica');

// ---------------------------------------------------------------- nome unico por regiao
T::recusa('recusa duas caixas com o mesmo nome na regiao',
    fn() => $criarCaixa('CTO', 'CTO.02.05', -24.90, -52.21),
    'uq_caixa_nome');

// Nome nao volta a ser usado nem depois de excluido logicamente (decisao 3b.20 #2).
Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW() WHERE id = ?', [$cto]);
T::recusa('nome de caixa excluida NAO e reaproveitado',
    fn() => $criarCaixa('CTO', 'CTO.02.05', -24.90, -52.21),
    'uq_caixa_nome');
Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NULL WHERE id = ?', [$cto]);

// ---------------------------------------------------------------- desconectar libera a ponta
Db::exec('DELETE FROM tab_ftth_ligacao WHERE id = ?', [$lig1]);
T::igual('apagar ligacao apaga as pontas (CASCADE)', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao_ponta WHERE ligacao_id = ?', [$lig1]));
$lig3 = $ligar($ceo, ['VAO_FIBRA', $vaoA, 2], ['SPLITTER_IN', $sptDer, 0]);
T::certo('fibra liberada pode ser religada em outro destino', $lig3 > 0);

// ---------------------------------------------------------------- FK
T::recusa('recusa vao apontando para caixa inexistente', function () use ($cabo, $regiao) {
    Db::exec('INSERT INTO tab_ftth_cabo_vao (cabo_id, regiao_id, caixa_ini_id, caixa_fim_id, vertices, criado_por, criado_em)
              VALUES (?,?,?,?,?,?,NOW())', [$cabo, $regiao, 999999, 999998, '[]', 'teste']);
}, 'foreign key');
