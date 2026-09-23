<?php
/**
 * ftth_doc :: POP / Data Center — gestão de inside plant (OSP/ISP).
 *
 * O POP é uma caixa do tipo DC no mapa; esta tela cuida do que mora dentro dele: a OLT com
 * suas placas PON e o DIO com a grade de portas.
 *
 * A porta do DIO é a ORIGEM do circuito óptico — dela sai a potência que o cálculo de
 * potência vai propagar. A ligação dela com a fibra da rua é escolhida no seletor "saída
 * para a rua", e grava uma ligação comum em `tab_ftth_ligacao`, pelo mesmo caminho do
 * diagrama. Por isso o POP não precisa de diagrama próprio: o grafo é o mesmo.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Caixa.php';
require_once __DIR__ . '/lib/InsidePlant.php';

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        switch ($_GET['ajax']) {
            case 'estado':
                $caixaId = (int) ($_GET['caixa'] ?? 0);
                if (!InsidePlant::pop($caixaId)) {
                    Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId],
                        'POP não encontrado.')->enviar(404);
                }
                Resultado::ok([
                    'olts'   => InsidePlant::olts($caixaId),
                    'dios'   => InsidePlant::dios($caixaId),
                    'saidas' => InsidePlant::saidasDisponiveis($caixaId),
                ])->enviar();

            case 'criar_olt':
                ftth_exigir_csrf();
                InsidePlant::criarOlt((int) ($_POST['caixa'] ?? 0),
                    ftth_dados_olt($_POST), $usuario_logado)->enviar();

            case 'alterar_olt':
                ftth_exigir_csrf();
                InsidePlant::alterarOlt((int) ($_POST['olt'] ?? 0), ftth_dados_olt($_POST),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado)->enviar();

            case 'excluir_olt':
                ftth_exigir_csrf();
                InsidePlant::excluirOlt((int) ($_POST['olt'] ?? 0),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado)->enviar();

            case 'criar_dio':
                ftth_exigir_csrf();
                InsidePlant::criarDio((int) ($_POST['caixa'] ?? 0),
                    (string) ($_POST['nome'] ?? ''), (int) ($_POST['portas'] ?? 24),
                    $usuario_logado)->enviar();

            case 'alterar_dio':
                ftth_exigir_csrf();
                InsidePlant::alterarDio((int) ($_POST['dio'] ?? 0), [
                    'nome'   => (string) ($_POST['nome'] ?? ''),
                    'portas' => (int) ($_POST['portas'] ?? 0),
                ], isset($_POST['versao']) ? (int) $_POST['versao'] : null, $usuario_logado)->enviar();

            case 'excluir_dio':
                ftth_exigir_csrf();
                InsidePlant::excluirDio((int) ($_POST['dio'] ?? 0),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado)->enviar();

            case 'alterar_porta':
                ftth_exigir_csrf();
                InsidePlant::alterarPorta((int) ($_POST['porta'] ?? 0), [
                    'operacao'    => (string) ($_POST['operacao'] ?? 'DISTRIBUICAO'),
                    'olt_ftth_id' => (int) ($_POST['olt_ftth_id'] ?? 0),
                    'pon'         => (string) ($_POST['pon'] ?? ''),
                    'servico'     => (string) ($_POST['servico'] ?? ''),
                    'ptx_dbm'     => $_POST['ptx_dbm'] ?? '',
                ], isset($_POST['versao']) ? (int) $_POST['versao'] : null, $usuario_logado)->enviar();

            case 'definir_saida':
                ftth_exigir_csrf();
                InsidePlant::definirSaida((int) ($_POST['porta'] ?? 0),
                    (int) ($_POST['vao'] ?? 0), (int) ($_POST['numero'] ?? 0),
                    $usuario_logado)->enviar();

            default:
                Resultado::erro('FTTH-SYS-002', ['ajax' => (string) $_GET['ajax']])->enviar(400);
        }
    } catch (Throwable $e) {
        Log::excecao('pop.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/** Monta o payload da OLT a partir do POST, inclusive as placas. */
function ftth_dados_olt(array $post): array
{
    $placas = json_decode((string) ($post['placas'] ?? '[]'), true);
    return [
        'apelido'       => (string) ($post['apelido'] ?? ''),
        'fabricante'    => (string) ($post['fabricante'] ?? ''),
        'olt_id'        => (int) ($post['olt_id'] ?? 0),
        'formato_porta' => (string) ($post['formato_porta'] ?? '0/1/1'),
        'acesso'        => (string) ($post['acesso'] ?? 'simulado'),
        'classe_optica' => (string) ($post['classe_optica'] ?? 'B+'),
        'ptx_dbm'       => (float) ($post['ptx_dbm'] ?? 3.00),
        'placas'        => is_array($placas) ? $placas : [],
    ];
}

/* ------------------------------------------------------------------ página */
$caixaId = (int) ($_GET['caixa'] ?? 0);
$pop     = null;
$pops    = [];
$falha   = null;
try {
    $pops = InsidePlant::pops();
    if ($caixaId > 0) {
        $pop = InsidePlant::pop($caixaId);
        if (!$pop) {
            $falha = 'Este POP não foi encontrado, ou a caixa não é do tipo DC.';
        }
    }
} catch (Throwable $e) {
    Log::excecao('pop.carregar', $e, ['caixa' => $caixaId]);
    $falha = 'Não foi possível abrir o POP. FTTH-SYS-001 · ' . Resultado::requestId();
}

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="ftth-wrap">
    <h1 class="ftth-titulo">
        <i class="bi-hdd-rack-fill"></i>
        <?= $pop ? htmlspecialchars((string) $pop['nome']) : 'POP / Data Center' ?>
    </h1>
    <p class="ftth-sub">
        Gestão de inside plant · OSP/ISP<?= $pop ? ' · ' . htmlspecialchars((string) $pop['regiao']) : '' ?>
    </p>

<?php if ($falha): ?>
    <div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div>
<?php endif; ?>

<?php if (!$pop): ?>
    <div class="ftth-card">
        <h2>Escolha o POP</h2>
        <?php if (!$pops): ?>
            <div class="ftth-aviso">
                Nenhum POP cadastrado ainda. O POP é uma <strong>caixa do tipo DC</strong> —
                crie-a no mapa, no lugar onde fica o data center, e volte aqui.
                <p style="margin:10px 0 0"><a class="ftth-btn ftth-btn--pri" href="mapa.php">Abrir o mapa</a></p>
            </div>
        <?php else: ?>
            <table class="ftth-tabela">
                <tr><th>POP</th><th>Região</th><th>OLTs</th><th>DIOs</th><th></th></tr>
                <?php foreach ($pops as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $p['nome']) ?></strong></td>
                        <td><?= htmlspecialchars((string) $p['regiao']) ?></td>
                        <td><?= (int) $p['olts'] ?></td>
                        <td><?= (int) $p['dios'] ?></td>
                        <td><a class="ftth-btn ftth-btn--pri" href="pop.php?caixa=<?= (int) $p['id'] ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="ftth-card">
        <div class="ftth-pop-acoes">
            <button class="ftth-btn ftth-btn--pri" id="btn-nova-olt"><i class="bi-plus-lg"></i> Nova OLT</button>
            <button class="ftth-btn ftth-btn--pri" id="btn-novo-dio"><i class="bi-plus-lg"></i> Novo DIO</button>
            <a class="ftth-btn ftth-btn--sec" href="mapa.php"><i class="bi-arrow-left"></i> Mapa</a>
        </div>
        <div id="saida"></div>
    </div>

    <div id="lista-olts"></div>
    <div id="lista-dios"></div>


<?php endif; ?>
</div>

<?php if ($pop): ?>
<script>
var CSRF   = <?= json_encode(ftth_csrf_token()) ?>;
var CAIXA  = <?= (int) $pop['id'] ?>;
var NATIVAS     = <?= json_encode(InsidePlant::oltsNativas(), JSON_UNESCAPED_UNICODE) ?>;
var FABRICANTES = <?= json_encode(InsidePlant::FABRICANTES, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/pop.js?v=<?= time() ?>"></script>
<?php endif; ?>

<?php include('../../baixo.php'); ?>

<?php if ($pop): ?>
<?php /* ------------------------------------------------------------------------------
   Modais e painel lateral ficam FORA da .ftth-wrap, depois do baixo.php -- ou seja,
   filhos diretos do <body>.

   Motivo: .ftth-wrap tem "position: relative; z-index: 0", o que cria um stacking
   context. Um modal "position: fixed; z-index: 4000" dentro dele fica preso ao nivel 0
   desse contexto, e o #sistema-rodape do painel -- que vem depois no DOM -- era pintado
   POR CIMA do rodape do modal: o botao Salvar aparecia na tela mas o clique ia para o
   div.column do rodape do MK-AUTH.

   O z-index: 0 da .ftth-wrap nao pode sair: e ele que segura o Leaflet do mapa
   (z-index ate 1000) abaixo do menu do painel. Entao quem sai do wrap e o modal.
   ---------------------------------------------------------------------------------- */ ?>
    <!-- Modal: OLT (criar e configurar usam o mesmo, como no cadastro de região) -->
    <div class="ftth-modal" id="modal-olt" style="display:none">
        <div class="ftth-modal-caixa ftth-modal-caixa--larga">
            <div class="ftth-modal-topo">
                <strong><i class="bi-hdd-network-fill"></i> <span id="olt-titulo">Vincular nova OLT</span></strong>
                <button class="ftth-painel-fechar" id="btn-olt-fechar">&times;</button>
            </div>
            <div class="ftth-modal-corpo">
                <div class="ftth-linha-campos">
                    <div>
                        <div class="ftth-rotulo-campo">Apelido (identificação)</div>
                        <input class="ftth-campo" id="olt-apelido" maxlength="60" placeholder="Ex.: OLT Centro">
                    </div>
                    <div>
                        <div class="ftth-rotulo-campo">Equipamento no cadastro do MK-AUTH</div>
                        <select class="ftth-campo" id="olt-nativa"></select>
                    </div>
                </div>
                <p class="ftth-sub">
                    Vincular traz host e credencial do cadastro do MK-AUTH, que continuam
                    somente leitura aqui.
                </p>

                <div class="ftth-linha-campos">
                    <div>
                        <div class="ftth-rotulo-campo">Fabricante</div>
                        <select class="ftth-campo" id="olt-fabricante"></select>
                    </div>
                    <div>
                        <div class="ftth-rotulo-campo">Potência de saída (dBm)</div>
                        <input class="ftth-campo" id="olt-ptx" type="number" step="0.01" value="3.00">
                    </div>
                </div>
                <p class="ftth-sub">É daqui que parte o cálculo de potência do circuito.</p>

                <div class="ftth-rotulo-campo">Placas PON (line cards)</div>
                <p class="ftth-sub">
                    Cada placa vira uma faixa de PONs: prefixo <code>0/1</code> com 16 portas
                    a partir da 1 gera <code>0/1/1</code> … <code>0/1/16</code>.
                </p>
                <div id="olt-placas"></div>
                <button class="ftth-btn ftth-btn--sec" id="btn-add-placa"><i class="bi-plus-lg"></i> Adicionar placa</button>

                <div class="ftth-aviso" style="margin-top:10px">
                    <strong>Modo simulado.</strong> A conexão real com a OLT (SSH/telnet) entra
                    numa fase seguinte. Por ora o cadastro serve à documentação e ao cálculo
                    de potência.
                </div>
                <div id="olt-saida"></div>
            </div>
            <div class="ftth-modal-rodape">
                <button class="ftth-btn ftth-btn--sec" id="btn-olt-cancelar">Cancelar</button>
                <button class="ftth-btn ftth-btn--pri" id="btn-olt-salvar">Salvar OLT</button>
            </div>
        </div>
    </div>

    <!-- Modal: DIO -->
    <div class="ftth-modal" id="modal-dio" style="display:none">
        <div class="ftth-modal-caixa">
            <div class="ftth-modal-topo">
                <strong><i class="bi-grid-3x3-gap-fill"></i> <span id="dio-titulo">Instalar novo DIO</span></strong>
                <button class="ftth-painel-fechar" id="btn-dio-fechar">&times;</button>
            </div>
            <div class="ftth-modal-corpo">
                <div class="ftth-rotulo-campo">Identificação do painel</div>
                <input class="ftth-campo" id="dio-nome" maxlength="60" placeholder="Ex.: DIO PRINCIPAL 01">

                <div class="ftth-rotulo-campo">Capacidade de fibras</div>
                <select class="ftth-campo" id="dio-portas">
                    <?php foreach (InsidePlant::CAPACIDADES_DIO as $c): ?>
                        <option value="<?= $c ?>"<?= $c === 24 ? ' selected' : '' ?>><?= $c ?> portas</option>
                    <?php endforeach; ?>
                </select>
                <p class="ftth-sub">
                    As portas nascem junto com o painel, vazias. Ampliar depois é seguro;
                    reduzir só passa se as portas que somem estiverem livres.
                </p>
                <div id="dio-saida"></div>
            </div>
            <div class="ftth-modal-rodape">
                <button class="ftth-btn ftth-btn--sec" id="btn-dio-cancelar">Cancelar</button>
                <button class="ftth-btn ftth-btn--pri" id="btn-dio-salvar">Salvar DIO</button>
            </div>
        </div>
    </div>

    <!-- Painel lateral da porta -->
    <aside class="ftth-pop-painel" id="painel-porta" style="display:none">
        <div class="ftth-pop-painel-topo">
            <strong><i class="bi-ethernet"></i> <span id="porta-titulo">Porta</span></strong>
            <button class="ftth-painel-fechar" id="btn-porta-fechar">&times;</button>
        </div>
        <div class="ftth-pop-painel-corpo">
            <div class="ftth-rotulo-campo">Operação da porta</div>
            <select class="ftth-campo" id="porta-operacao">
                <option value="DISTRIBUICAO">Distribuição</option>
                <option value="PTP_TX">PTP TX</option>
                <option value="PTP_RX">PTP RX</option>
                <option value="PTP_TXRX">PTP TX+RX</option>
            </select>

            <div class="ftth-rotulo-campo">Equipamento interno</div>
            <select class="ftth-campo" id="porta-equipamento"></select>

            <div class="ftth-rotulo-campo">Serviço</div>
            <input class="ftth-campo" id="porta-servico" maxlength="60" placeholder="Ex.: CEO.03">

            <div class="ftth-rotulo-campo">TX (dBm)</div>
            <input class="ftth-campo" id="porta-ptx" type="number" step="0.01" placeholder="usa o da OLT">

            <div class="ftth-rotulo-campo">Saída para a rua (OSP)</div>
            <select class="ftth-campo" id="porta-saida"></select>
            <p class="ftth-sub">
                Escolher aqui liga a porta à fibra de verdade — é a mesma ligação que o
                diagrama cria, no mesmo grafo.
            </p>

            <div id="porta-saida-msg"></div>
        </div>
        <div class="ftth-pop-painel-rodape">
            <button class="ftth-btn ftth-btn--pri" id="btn-porta-salvar">Salvar porta</button>
        </div>
    </aside>
<?php endif; ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
