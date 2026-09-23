<?php
/**
 * ftth_doc :: diagnostico da instalacao.
 *
 * Roda depois de instalar e sempre que alguem disser "o addon nao abre". Nao escreve nada em
 * lugar nenhum: le o ambiente, o banco e as permissoes, e imprime uma linha por verificacao.
 *
 *   php cli/diagnostico.php [--json] [--conf=<arquivo>]
 *
 * Saida 0 quando nao ha falha (avisos nao derrubam), 1 quando alguma verificacao falhou.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$args = ftth_cli_args($argv);
$json = !empty($args['json']);

$itens = [];   // [estado, titulo, detalhe] — estado: ok | aviso | falha

function verificar(array &$itens, string $estado, string $titulo, string $detalhe = ''): void
{
    $itens[] = ['estado' => $estado, 'titulo' => $titulo, 'detalhe' => $detalhe];
}

// ------------------------------------------------------------------ 1. arquivos do addon
$raiz = dirname(__DIR__);
$versao = ftth_versao();
verificar($itens, is_file("$raiz/manifest.json") ? 'ok' : 'falha',
    'manifest.json presente', 'versao ' . $versao);

$classe = "$raiz/addons.class.php";
verificar($itens, (is_file($classe) && filesize($classe) > 1000) ? 'ok' : 'falha',
    'addons.class.php presente',
    is_file($classe) ? filesize($classe) . ' bytes' : 'ausente — o painel nao autentica o addon');

// A suite apaga e recria um banco inteiro: ela nunca pode chegar ao servidor de um provedor.
// So cobramos isso de uma instalacao de verdade; rodando do repositorio, tests/ e esperada.
$instalado = str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $raiz), '/opt/mk-auth/admin/addons');
if ($instalado) {
    verificar($itens, is_dir("$raiz/tests") ? 'falha' : 'ok', 'suite de testes fora do servidor',
        is_dir("$raiz/tests") ? 'a pasta tests/ NAO deveria estar aqui' : '');
} else {
    verificar($itens, 'ok', 'origem', 'rodando fora de /opt/mk-auth (area de desenvolvimento)');
}

// ------------------------------------------------------------------ 2. ambiente PHP
verificar($itens, PHP_VERSION_ID >= 80000 ? 'ok' : 'falha', 'PHP 8.0 ou superior', PHP_VERSION);
$faltando = [];
foreach (['pdo_mysql', 'zip', 'dom', 'simplexml', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        $faltando[] = $ext;
    }
}
verificar($itens, $faltando ? 'falha' : 'ok', 'extensoes do PHP',
    $faltando ? 'faltam: ' . implode(', ', $faltando) : 'pdo_mysql, zip, dom, simplexml, mbstring');

// ------------------------------------------------------------------ 3. configuracao e banco
$cfg = ftth_cli_config($args);
if ($cfg === null) {
    verificar($itens, 'falha', 'configuracao de banco', Credenciais::comoResolver());
} else {
    verificar($itens, 'ok', 'configuracao de banco', $cfg['origem']);

    if ($cfg['origem'] === Credenciais::ARQUIVO_ADDON && is_file(Credenciais::ARQUIVO_ADDON)) {
        $modo = substr(sprintf('%o', fileperms(Credenciais::ARQUIVO_ADDON)), -4);
        verificar($itens, $modo === '0640' ? 'ok' : 'aviso',
            'permissao do arquivo de configuracao', $modo . ' (esperado 0640)');
    }

    try {
        Db::conectar($cfg);
        Db::valor('SELECT 1');
        verificar($itens, 'ok', 'conexao com o banco', $cfg['name'] . '@' . $cfg['host']);

        // Senha de fabrica do MK-AUTH: funciona, mas e a primeira que alguem de fora tenta.
        if ($cfg['pass'] === 'vertrigo') {
            verificar($itens, 'aviso', 'senha do MySQL',
                'o banco ainda usa a senha padrao de fabrica — vale trocar');
        }

        $schema = new Schema($raiz . '/sql');
        $estado = $schema->estado();
        verificar($itens, $estado['instalado'] ? 'ok' : 'falha', 'tabelas do addon',
            $estado['instalado']
                ? count($estado['tabelas']) . ' tabelas'
                : 'faltam: ' . implode(', ', $estado['faltando']));

        if ($estado['aposentadas']) {
            verificar($itens, 'aviso', 'tabelas aposentadas ainda no banco',
                implode(', ', array_keys($estado['aposentadas'])) . ' — rode o instalador com --limpar');
        }

        $ledger = $estado['ledger'];
        verificar($itens, $ledger['baseline'] ? 'ok' : 'aviso', 'schema registrado no diario',
            $ledger['baseline']
                ? 'versao ' . $ledger['baseline']['versao'] . ' em ' . $ledger['baseline']['executed_at']
                : 'o baseline ainda nao foi aplicado por aqui');

        if ($estado['instalado']) {
            $c = $estado['contagens'];
            verificar($itens, 'ok', 'planta documentada',
                "{$c['regioes']} regioes, {$c['caixas']} caixas, {$c['vaos']} vaos, {$c['clientes']} clientes em porta");

            $chave = (string) Config::get('google_maps_key', '');
            verificar($itens, $chave !== '' ? 'ok' : 'aviso', 'chave do Google Maps',
                $chave !== ''
                    ? 'cadastrada (' . substr($chave, 0, 6) . '...)'
                    : 'vazia — o mapa nao abre ate cadastrar em Configuracoes');

            foreach (['sync_sis_cliente', 'sync_cto_nativa'] as $flag) {
                if (Config::ligado($flag)) {
                    verificar($itens, 'aviso', "escrita em tabela nativa ligada ($flag)",
                        'o addon grava no cadastro do MK-AUTH — confirme que e o desejado');
                }
            }
        }
    } catch (Throwable $e) {
        verificar($itens, 'falha', 'conexao com o banco', $e->getMessage());
    }
}

// ------------------------------------------------------------------ 4. pastas de escrita
// O AppArmor do MK-AUTH proibe escrita dentro da pasta do addon; so estes caminhos valem.
foreach (['/opt/mk-auth/dados/ftth_doc' => 'uploads e dados',
          '/opt/mk-auth/log/ftth_doc'   => 'log do addon'] as $dir => $para) {
    if (!is_dir($dir)) {
        verificar($itens, 'falha', "pasta $para", "$dir nao existe");
        continue;
    }
    $teste = $dir . '/.diagnostico-' . getmypid();
    $escreveu = @file_put_contents($teste, 'ok') !== false;
    @unlink($teste);
    $dono = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner($dir))['name'] ?? '?') : '?';
    verificar($itens, $escreveu ? 'ok' : 'falha', "pasta $para",
        $escreveu ? "$dir (dono $dono)" : "$dir existe mas nao aceita escrita — veja o AppArmor");
}

// ------------------------------------------------------------------ 5. menu do painel
$addonjs = '/opt/mk-auth/admin/addons/addon.js';
if (is_file($addonjs)) {
    $linhas = substr_count((string) file_get_contents($addonjs), 'addons/ftth_doc/');
    verificar($itens, $linhas === 1 ? 'ok' : 'aviso', 'link no menu do painel',
        $linhas === 0 ? 'nenhuma linha em addon.js — o addon nao aparece no menu'
                      : "$linhas linhas em addon.js");
} else {
    verificar($itens, 'aviso', 'link no menu do painel', 'addon.js nao encontrado');
}

// ------------------------------------------------------------------ saida
$falhas = count(array_filter($itens, fn($i) => $i['estado'] === 'falha'));
$avisos = count(array_filter($itens, fn($i) => $i['estado'] === 'aviso'));

if ($json) {
    echo json_encode(['ok' => $falhas === 0, 'falhas' => $falhas, 'avisos' => $avisos,
                      'itens' => $itens], JSON_UNESCAPED_UNICODE) . "\n";
    exit($falhas === 0 ? 0 : 1);
}

$cor = ['ok' => "\033[32mok   \033[0m", 'aviso' => "\033[33m!!   \033[0m", 'falha' => "\033[31mXX   \033[0m"];
echo "ftth_doc :: diagnostico\n";
echo '  ' . str_repeat('-', 62) . "\n";
foreach ($itens as $i) {
    echo '  ' . $cor[$i['estado']] . str_pad($i['titulo'], 36) . ($i['detalhe'] !== '' ? $i['detalhe'] : '') . "\n";
}
echo '  ' . str_repeat('-', 62) . "\n";
if ($falhas === 0 && $avisos === 0) {
    echo "  \033[32mtudo certo\033[0m\n";
} elseif ($falhas === 0) {
    echo "  \033[33m$avisos aviso(s)\033[0m, nada impede o addon de funcionar\n";
} else {
    echo "  \033[31m$falhas falha(s)\033[0m e $avisos aviso(s)\n";
}
exit($falhas === 0 ? 0 : 1);
