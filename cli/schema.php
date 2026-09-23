<?php
/**
 * ftth_doc :: schema pela linha de comando.
 *
 * Substitui a tela instalar.php: quem cria e atualiza as tabelas agora e o instalar.sh, atraves
 * deste arquivo. Tambem serve ao suporte, para ver o estado de um servidor sem abrir o painel.
 *
 *   php cli/schema.php estado                  o que existe no banco, sem escrever nada
 *   php cli/schema.php aplicar                 aplica sql/baseline.sql (idempotente)
 *   php cli/schema.php limpar [--forcar]       derruba as tabelas aposentadas
 *   php cli/schema.php conferir                monta o baseline num banco descartavel e compara
 *   php cli/schema.php versao                  versao do manifest
 *
 * Opcoes: --conf=<arquivo> --host= --port= --user= --pass= --db= --json --forcar
 *
 * Codigo de saida (o contrato com o instalar.sh):
 *   0 sucesso ou nada a fazer          3 banco inacessivel
 *   1 falha ao aplicar SQL             4 pre-condicao nao atendida (limpar com dados)
 *   2 uso invalido / banco suspeito    5 executado fora da CLI
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const SAIDA_OK        = 0;
const SAIDA_SQL       = 1;
const SAIDA_USO       = 2;
const SAIDA_BANCO     = 3;
const SAIDA_PRECOND   = 4;

$args    = ftth_cli_args($argv);
$comando = $argv[1] ?? '';
$json    = !empty($args['json']);
$versao  = ftth_versao();

if ($comando === '' || str_starts_with($comando, '--') || in_array($comando, ['ajuda', '--ajuda', '-h'], true)) {
    echo trim(preg_replace('/^\s*\*\s?/m', '', (string) strstr(file_get_contents(__FILE__), '/**'))), "\n";
    exit($comando === '' ? SAIDA_USO : SAIDA_OK);
}

if ($comando === 'versao') {
    echo $json ? json_encode(['versao' => $versao]) . "\n" : $versao . "\n";
    exit(SAIDA_OK);
}

if (!in_array($comando, ['estado', 'aplicar', 'limpar', 'conferir'], true)) {
    erro("Comando desconhecido: $comando", SAIDA_USO, $json);
}

// ------------------------------------------------------------------ conexao
$cfg = ftth_cli_config($args);
if ($cfg === null) {
    erro(Credenciais::comoResolver(), SAIDA_USO, $json);
}

// Rede de seguranca: escrever fora do banco do MK-AUTH so com --forcar. Um --db= errado num
// servidor de provedor e o tipo de engano que ninguem quer descobrir depois.
if (in_array($comando, ['aplicar', 'limpar'], true)
    && !str_contains($cfg['name'], 'mkradius') && empty($args['forcar'])) {
    erro("Banco '{$cfg['name']}' nao parece ser o do MK-AUTH. Use --forcar se for mesmo ele.",
        SAIDA_USO, $json);
}

try {
    Db::conectar($cfg);
    Db::valor('SELECT 1');
} catch (Throwable $e) {
    erro('Nao foi possivel conectar em ' . $cfg['name'] . '@' . $cfg['host'] . ': ' . $e->getMessage(),
        SAIDA_BANCO, $json);
}

Auditoria::configurar(ftth_usuario_cli());
$schema = new Schema(__DIR__ . '/../sql');

// ------------------------------------------------------------------ comandos
try {
    switch ($comando) {
        case 'estado':
            saidaEstado($schema, $cfg, $versao, $json);
            exit(SAIDA_OK);

        case 'aplicar':
            $r = $schema->aplicar(ftth_usuario_cli(), $versao);
            $estado = $schema->estado();
            if ($json) {
                echo json_encode([
                    'ok' => true, 'acao' => 'aplicar', 'versao' => $versao,
                    'comandos' => $r['comandos'], 'ms' => $r['ms'],
                    'tabelas' => count($estado['tabelas']),
                    'aposentadas' => array_keys($estado['aposentadas']),
                    'contagens' => $estado['contagens'],
                ]) . "\n";
            } else {
                cabecalho($cfg, $versao);
                echo "  ok   baseline.sql        {$r['comandos']} comandos   {$r['ms']} ms\n";
                echo "  ok   diario atualizado   baseline.sql / $versao\n";
                if ($estado['aposentadas']) {
                    $comDados = array_filter($estado['aposentadas']);
                    echo "  ..   limpeza pendente: " . count($estado['aposentadas']) . " tabela(s) aposentada(s)"
                       . ($comDados ? ', ' . count($comDados) . ' com dados' : '') . "\n";
                }
                rodape($estado);
            }
            exit(SAIDA_OK);

        case 'limpar':
            try {
                $r = $schema->limpar(ftth_usuario_cli(), !empty($args['forcar']), $versao);
            } catch (RuntimeException $e) {
                erro($e->getMessage(), SAIDA_PRECOND, $json);
            }
            $estado = $schema->estado();
            if ($json) {
                echo json_encode(['ok' => true, 'acao' => 'limpar', 'comandos' => $r['comandos'],
                                  'ms' => $r['ms'], 'restantes' => array_keys($estado['aposentadas'])]) . "\n";
            } else {
                cabecalho($cfg, $versao);
                echo "  ok   limpeza.sql         {$r['comandos']} comandos   {$r['ms']} ms\n";
                echo "  ok   tabelas aposentadas removidas\n";
                rodape($estado);
            }
            exit(SAIDA_OK);

        case 'conferir':
            exit(conferir($schema, $cfg, $json));
    }
} catch (Throwable $e) {
    erro($e->getMessage(), SAIDA_SQL, $json);
}

exit(SAIDA_OK);

// ------------------------------------------------------------------ apoio

function erro(string $msg, int $codigo, bool $json): void
{
    if ($json) {
        echo json_encode(['ok' => false, 'erro' => $msg, 'codigo' => $codigo]) . "\n";
    } else {
        fwrite(STDERR, "  XX   $msg\n");
    }
    exit($codigo);
}

function cabecalho(array $cfg, string $versao): void
{
    echo "ftth_doc :: schema\n";
    echo "  banco     {$cfg['name']} @ {$cfg['host']}\n";
    echo "  pacote    $versao\n";
    echo '  ' . str_repeat('-', 56) . "\n";
}

function rodape(array $estado): void
{
    $c = $estado['contagens'];
    echo '  ' . str_repeat('-', 56) . "\n";
    echo '  tabelas ' . count($estado['tabelas']) . '/' . count(Schema::TABELAS);
    if ($c) {
        echo "   regioes {$c['regioes']}   caixas {$c['caixas']}   vaos {$c['vaos']}";
    }
    echo "\n";
}

function saidaEstado(Schema $schema, array $cfg, string $versao, bool $json): void
{
    $estado = $schema->estado();
    $ledger = $estado['ledger'];

    if ($json) {
        echo json_encode([
            'ok' => true, 'acao' => 'estado', 'versao' => $versao,
            'instalado' => $estado['instalado'],
            'tabelas' => count($estado['tabelas']), 'faltando' => $estado['faltando'],
            'aposentadas' => $estado['aposentadas'],
            'contagens' => $estado['contagens'],
            'baseline_aplicado' => $ledger['baseline']['executed_at'] ?? null,
            'versao_aplicada' => $ledger['baseline']['versao'] ?? null,
            'origem_config' => $cfg['origem'],
        ]) . "\n";
        return;
    }

    cabecalho($cfg, $versao);
    echo '  config    ' . $cfg['origem'] . "\n";
    if ($ledger['baseline']) {
        echo "  aplicado  versao {$ledger['baseline']['versao']} em {$ledger['baseline']['executed_at']}"
           . " por {$ledger['baseline']['executed_by']}\n";
    } else {
        echo "  aplicado  nunca (o baseline ainda nao rodou neste banco)\n";
    }
    if ($ledger['legados'] > 0) {
        echo "  historico {$ledger['legados']} migrations numeradas, da epoca anterior ao baseline\n";
    }
    echo '  ' . str_repeat('-', 56) . "\n";

    if ($estado['instalado']) {
        echo '  ok   as ' . count(Schema::TABELAS) . " tabelas do addon existem\n";
    } else {
        echo '  XX   faltam ' . count($estado['faltando']) . ' tabela(s): '
           . implode(', ', array_slice($estado['faltando'], 0, 5))
           . (count($estado['faltando']) > 5 ? '...' : '') . "\n";
    }
    foreach ($estado['aposentadas'] as $t => $linhas) {
        echo "  ..   aposentada ainda no banco: $t ($linhas linha(s))\n";
    }
    rodape($estado);
}

/**
 * Monta o baseline num banco descartavel e compara a assinatura com a do banco real. E o que
 * se roda ANTES de aplicar num servidor com dados: leitura pura, resposta em segundos.
 */
function conferir(Schema $schema, array $cfg, bool $json): int
{
    $temporario = 'mkradius_ftth_conferencia';
    $atual = $schema->assinatura();

    $admin = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['user'], $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("DROP DATABASE IF EXISTS `$temporario`");
    $admin->exec("CREATE DATABASE `$temporario` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        Db::conectar(['host' => $cfg['host'], 'port' => $cfg['port'], 'user' => $cfg['user'],
                      'pass' => $cfg['pass'], 'name' => $temporario]);
        $schema->aplicar('conferencia', ftth_versao());
        $schema->limpar('conferencia', true, ftth_versao());
        $esperado = $schema->assinatura();
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `$temporario`");
        Db::conectar($cfg);
    }

    $faltando = array_values(array_diff($esperado, $atual));
    $sobrando = array_values(array_diff($atual, $esperado));

    if ($json) {
        echo json_encode(['ok' => !$faltando && !$sobrando, 'acao' => 'conferir',
                          'faltando' => $faltando, 'sobrando' => $sobrando]) . "\n";
        return ($faltando || $sobrando) ? SAIDA_PRECOND : SAIDA_OK;
    }

    cabecalho($cfg, ftth_versao());
    if (!$faltando && !$sobrando) {
        echo '  ok   o banco ja tem o schema desta versao (' . count($esperado) . " itens conferidos)\n";
        return SAIDA_OK;
    }
    foreach ($faltando as $l) {
        echo "  ..   o baseline vai acrescentar: $l\n";
    }
    foreach ($sobrando as $l) {
        echo "  ..   existe no banco e nao no baseline: $l\n";
    }
    echo "  ..   rode 'aplicar' para acertar o que falta\n";
    return SAIDA_OK;
}
