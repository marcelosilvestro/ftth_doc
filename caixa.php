<?php
/**
 * ftth_doc :: diagrama da caixa — o editor de conectividade.
 *
 * Aqui é onde o grafo (tab_ftth_ligacao) é preenchido. O SVG é uma VISÃO: pode ser
 * destruído e redesenhado a qualquer momento que a topologia continua a mesma (P1).
 *
 * TUDO é gravado na hora — ligação e posição dos nós. Houve um botão "Salvar" que valia
 * só para o layout, e ele ensinava o usuário a esperar o que não acontecia: que sair sem
 * salvar desfizesse as fusões. Quem conserta engano agora é o Desfazer.
 *
 * Tema escuro só nesta tela, escopado em .ftth-diagrama.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Caixa.php';
require_once __DIR__ . '/lib/Topologia.php';
require_once __DIR__ . '/lib/Potencia.php';

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        switch ($_GET['ajax']) {
            case 'diagrama':
                $d = Topologia::diagrama((int) ($_GET['caixa'] ?? 0));
                if (!$d) {
                    Resultado::erro('FTTH-TOP-001', ['caixa' => (int) ($_GET['caixa'] ?? 0)])->enviar(404);
                }
                // A potência é outra camada: a topologia não sabe de dBm. Quem compõe as
                // duas é a tela, e é isso que acende o pulso nas pontas com sinal.
                $d['com_sinal'] = Potencia::comSinal((int) ($_GET['caixa'] ?? 0));
                Resultado::ok($d)->enviar();

            case 'rota':
                Potencia::rota(
                    (int) ($_GET['caixa'] ?? 0),
                    (string) ($_GET['elemento'] ?? ''),
                    (int) ($_GET['elemento_id'] ?? 0),
                    (int) ($_GET['numero'] ?? 0)
                )->enviar();

            case 'conectar':
                ftth_exigir_csrf();
                Topologia::conectar(
                    (int) ($_POST['caixa'] ?? 0),
                    [
                        'elemento'    => (string) ($_POST['a_elemento'] ?? ''),
                        'elemento_id' => (int) ($_POST['a_id'] ?? 0),
                        'numero'      => (int) ($_POST['a_numero'] ?? 0),
                    ],
                    [
                        'elemento'    => (string) ($_POST['b_elemento'] ?? ''),
                        'elemento_id' => (int) ($_POST['b_id'] ?? 0),
                        'numero'      => (int) ($_POST['b_numero'] ?? 0),
                    ],
                    isset($_POST['tipo']) ? (string) $_POST['tipo'] : null,
                    $usuario_logado
                )->enviar();

            case 'desconectar':
                ftth_exigir_csrf();
                Topologia::desconectar((int) ($_POST['ligacao'] ?? 0), $usuario_logado)->enviar();

            case 'desconectar_lote':
                ftth_exigir_csrf();
                $ids = json_decode((string) ($_POST['ligacoes'] ?? '[]'), true);
                if (!is_array($ids)) {
                    Resultado::erro('FTTH-SYS-002', ['campo' => 'ligacoes'])->enviar(400);
                }
                Topologia::desconectarVarias($ids, $usuario_logado)->enviar();

            case 'ligar_cabos':
                $aplicar = ($_POST['aplicar'] ?? '') === '1';
                ftth_exigir_csrf();
                Topologia::ligarCabos(
                    (int) ($_POST['caixa'] ?? 0),
                    (int) ($_POST['vao_a'] ?? 0),
                    (int) ($_POST['vao_b'] ?? 0),
                    $aplicar,
                    $usuario_logado
                )->enviar();

            case 'tipo_ligacao':
                ftth_exigir_csrf();
                Topologia::alterarTipoLigacao(
                    (int) ($_POST['ligacao'] ?? 0),
                    (string) ($_POST['tipo'] ?? ''),
                    $usuario_logado
                )->enviar();

            case 'criar_splitter':
                ftth_exigir_csrf();
                Topologia::criarSplitter((int) ($_POST['caixa'] ?? 0), [
                    'funcao'     => (string) ($_POST['funcao'] ?? ''),
                    'modelo'     => (string) ($_POST['modelo'] ?? 'BAL'),
                    'razao'      => (string) ($_POST['razao'] ?? ''),
                    'saidas'     => (int) ($_POST['saidas'] ?? 0),
                    'nome'       => (string) ($_POST['nome'] ?? ''),
                    'orientacao' => (string) ($_POST['orientacao'] ?? 'V'),
                ], $usuario_logado)->enviar();

            case 'alterar_splitter':
                ftth_exigir_csrf();
                Topologia::alterarSplitter(
                    (int) ($_POST['splitter'] ?? 0),
                    [
                        'nome'  => (string) ($_POST['nome'] ?? ''),
                        'razao' => (string) ($_POST['razao'] ?? ''),
                        'saidas' => (int) ($_POST['saidas'] ?? 0),
                    ],
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            case 'excluir_splitter':
                ftth_exigir_csrf();
                Topologia::excluirSplitter(
                    (int) ($_POST['splitter'] ?? 0),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            case 'salvar_layout':
                ftth_exigir_csrf();
                $nos = json_decode((string) ($_POST['nos'] ?? '[]'), true);
                Topologia::salvarLayout((int) ($_POST['caixa'] ?? 0),
                    is_array($nos) ? $nos : [], $usuario_logado)->enviar();

            default:
                Resultado::erro('FTTH-SYS-002', ['ajax' => (string) $_GET['ajax']])->enviar(400);
        }
    } catch (Throwable $e) {
        Log::excecao('caixa.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/* ------------------------------------------------------------------ página */
$caixaId   = (int) ($_GET['id'] ?? 0);
$caixa     = null;
$catalogo  = ['BAL' => [], 'DESBAL' => []];
$falha     = null;
try {
    $caixa    = Db::um('SELECT c.id, c.nome, c.tipo, c.regiao_id, r.nome AS regiao
                          FROM tab_ftth_caixa c
                          JOIN tab_ftth_regiao r ON r.id = c.regiao_id
                         WHERE c.id = ? AND c.excluido_em IS NULL', [$caixaId]);
    $catalogo = Topologia::catalogoSplitters();
} catch (Throwable $e) {
    Log::excecao('caixa.carregar', $e, ['caixa' => $caixaId]);
    $falha = 'Não foi possível abrir o diagrama. FTTH-SYS-001 · ' . Resultado::requestId();
}
if (!$falha && !$caixa) {
    $falha = 'Caixa não encontrada.';
}

include('nav/header.php');
?>
<!-- Mesma regra do mapa: o diagrama ocupa a tela, o menu do topo continua intacto. -->
<body class="ftth-mapa-full ftth-dg-body">
<?php include('../../topo.php'); ?>

<div class="ftth-wrap ftth-wrap--mapa ftth-diagrama">
<?php if ($falha): ?>
    <div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div>
    <p><a class="ftth-btn ftth-btn--sec" href="mapa.php">Voltar ao mapa</a></p>
<?php else: ?>

    <div class="ftth-dg-barra">
        <div class="ftth-dg-titulo">
            <?= Caixa::silhueta((string) $caixa["tipo"], "#2E5FA3", 20)
                 ?: "<i class=\"" . htmlspecialchars(Caixa::icone((string) $caixa["tipo"])) . "\"></i>" ?>
            <strong><?= htmlspecialchars((string) $caixa['nome']) ?></strong>
            <span class="ftth-dg-sub"><?= htmlspecialchars((string) $caixa['regiao']) ?></span>
        </div>
        <div class="ftth-dg-acoes">
            <button class="ftth-btn ftth-btn--sec" id="btn-desfazer" disabled
                    title="Nada para desfazer">
                <i class="bi-arrow-counterclockwise"></i> Desfazer<span id="desfazer-conta"></span></button>
            <button class="ftth-btn ftth-btn--sec" id="btn-ferramentas">
                <i class="bi-tools"></i> Ferramentas</button>
            <button class="ftth-btn ftth-btn--sec" id="btn-tela-cheia" title="Tela cheia (F11 do diagrama)">
                <i class="bi-arrows-fullscreen"></i> Tela cheia</button>
            <a class="ftth-btn ftth-btn--sec" href="mapa.php" id="btn-mapa"><i class="bi-arrow-left"></i> Mapa</a>
        </div>
    </div>

    <!-- Resultado de cada ação sai como aviso flutuante (ver .ftth-dg-toasts). Esta faixa
         ficou só para o que precisa continuar na tela: a ligação selecionada e o botão de
         desconectar. Warnings do envelope vão para o toast — nenhuma tela do addon os lia. -->
    <div class="ftth-dg-faixa" id="faixa" style="display:none"></div>
    <div class="ftth-dg-toasts" id="toasts"></div>

    <div class="ftth-dg-canvas" id="canvas">
        <svg id="svg" xmlns="http://www.w3.org/2000/svg"></svg>
        <div class="ftth-dg-carregando" id="carregando">Carregando o diagrama…</div>
        <!-- Ação da ligação selecionada: aparece colada na própria linha, em vez de
             ocupar uma faixa na largura da tela. -->
        <div class="ftth-dg-flutua" id="acao-ligacao" style="display:none">
            <!-- Só aparece quando a ligação admite sangria: fibra com fibra, mesma bitola
                 e mesmo número. O texto muda conforme o que a ligação é hoje. -->
            <button class="ftth-dg-flutua-btn" id="btn-tipo-ligacao" style="display:none">
                <i class="bi-arrow-left-right"></i> <span id="btn-tipo-texto">Marcar fusão</span></button>
            <button class="ftth-dg-flutua-btn" id="btn-desconectar" title="Desconectar esta fibra">
                <i class="bi-scissors"></i> Desconectar</button>
        </div>
    </div>

    <!-- Drawer Ferramentas, no padrão do UpperX: abas Adicionar e Editar.
         Anotações, Relatório e Histórico entram nas fases seguintes.

         Não existe "adicionar cabo" aqui de propósito: um vão é definido por duas caixas
         e uma rota no mapa (vértices, comprimento geométrico e óptico). Criado pelo
         diagrama, nasceria sem geometria — e sem comprimento não há estimativa de
         potência, além de mapa e diagrama passarem a discordar sobre a planta. -->
    <aside class="ftth-dg-drawer" id="drawer">
        <div class="ftth-dg-drawer-topo">
            <strong><i class="bi-tools"></i> Ferramentas</strong>
            <button class="ftth-painel-fechar" id="btn-fechar-drawer">&times;</button>
        </div>

        <div class="ftth-dg-abas">
            <button class="ftth-dg-aba ativa" data-aba="adicionar">
                <i class="bi-plus-lg"></i> Adicionar</button>
            <button class="ftth-dg-aba" data-aba="editar">
                <i class="bi-pencil"></i> Editar</button>
            <button class="ftth-dg-aba" data-aba="fusionar">
                <i class="bi-link-45deg"></i> Fusionar</button>
        </div>

        <div class="ftth-dg-drawer-corpo" id="aba-adicionar">
            <div class="ftth-dg-cartoes">
                <?php foreach ($catalogo['BAL'] as $b): ?>
                    <button class="ftth-dg-cartao" data-modelo="BAL"
                            data-razao="<?= htmlspecialchars($b['razao']) ?>"
                            data-saidas="<?= (int) $b['saidas'] ?>">
                        <strong>SPL <?= htmlspecialchars($b['razao']) ?></strong>
                        <span><?= (int) $b['saidas'] ?> saídas · <?= number_format($b['perda_db'], 2, ',', '') ?> dB</span>
                    </button>
                <?php endforeach; ?>
                <button class="ftth-dg-cartao ftth-dg-cartao--outro" data-modelo="DESBAL">
                    <strong>SPL Desbalanceado</strong><span>escolher a razão</span>
                </button>
                <button class="ftth-dg-cartao ftth-dg-cartao--outro" data-modelo="PERSONALIZADO">
                    <strong>SPL Personalizado</strong><span>saídas e perdas à mão</span>
                </button>
            </div>
        </div>

        <div class="ftth-dg-drawer-corpo" id="aba-editar" style="display:none">
            <div class="ftth-dg-grupo">Itens do diagrama</div>
            <div id="lista-itens"></div>
        </div>

        <!-- Fusão em lote: o serviço de um cabo passante numa CEO, que à mão são 24
             cliques. Liga Fo01 com Fo01, Fo02 com Fo02, até o menor dos dois acabar. -->
        <div class="ftth-dg-drawer-corpo" id="aba-fusionar" style="display:none">
            <div class="ftth-dg-grupo">Interligar dois cabos</div>
            <p class="ftth-sub">
                As fibras são ligadas na ordem — Fo01 com Fo01, Fo02 com Fo02 — até onde o
                cabo menor alcança. Fibra já conectada é pulada.
            </p>

            <label class="ftth-rotulo-campo">Primeiro cabo</label>
            <select class="ftth-campo" id="fus-a" style="width:100%"></select>

            <label class="ftth-rotulo-campo">Segundo cabo</label>
            <select class="ftth-campo" id="fus-b" style="width:100%"></select>

            <button class="ftth-btn ftth-btn--sec" id="btn-fus-simular" style="width:100%;margin-top:10px">
                <i class="bi-eye-fill"></i> Ver o que será ligado</button>

            <div id="fus-previa"></div>
        </div>
    </aside>

    <!-- Ficha da fibra: abre ao clicar numa bolinha conectada. Mesma mecânica do drawer
         (desliza da direita), mas conteúdo montado pelo JS a partir de Potencia::rota. -->
    <aside class="ftth-dg-drawer ftth-dg-drawer--ficha" id="ficha-fibra">
        <div class="ftth-dg-drawer-topo">
            <strong><i class="bi-reception-4"></i> Análise de potência</strong>
            <button class="ftth-painel-fechar" id="btn-fechar-ficha">&times;</button>
        </div>
        <div class="ftth-dg-drawer-corpo" id="ficha-corpo"></div>
    </aside>

    <!-- Alterar splitter existente -->
    <div class="ftth-modal" id="modal-editar" style="display:none">
        <div class="ftth-modal-caixa">
            <div class="ftth-modal-topo">
                <strong><i class="bi-pencil-square"></i> <span id="editar-titulo">Editar splitter</span></strong>
                <button class="ftth-painel-fechar" id="btn-editar-fechar">&times;</button>
            </div>
            <div class="ftth-modal-corpo">
                <div class="ftth-rotulo-campo">Nome</div>
                <input class="ftth-campo" id="editar-nome" maxlength="60">

                <div class="ftth-rotulo-campo">Razão</div>
                <select class="ftth-campo" id="editar-razao"></select>
                <p class="ftth-sub">
                    Reduzir as saídas é recusado se houver cliente numa porta acima do novo
                    limite — desvincule antes.
                </p>

                <div id="editar-saida"></div>
            </div>
            <div class="ftth-modal-rodape">
                <button class="ftth-btn ftth-btn--sec" id="btn-editar-cancelar">Cancelar</button>
                <button class="ftth-btn ftth-btn--pri" id="btn-editar-salvar">Salvar alteração</button>
            </div>
        </div>
    </div>

    <div class="ftth-modal" id="modal-splitter" style="display:none">
        <div class="ftth-modal-caixa">
            <div class="ftth-modal-topo">
                <strong><i class="bi-diagram-2-fill"></i> <span id="splitter-titulo">Configurar splitter</span></strong>
                <button class="ftth-painel-fechar" id="btn-fechar-splitter">&times;</button>
            </div>
            <div class="ftth-modal-corpo">
                <div class="ftth-rotulo-campo">Função do splitter</div>
                <div class="ftth-tipos ftth-dg-funcoes">
                    <button class="ftth-tipo ativo" data-funcao="ATENDIMENTO">Atendimento</button>
                    <button class="ftth-tipo" data-funcao="DERIVACAO">Derivação</button>
                </div>
                <p class="ftth-sub">Atendimento recebe cliente na saída. Derivação só liga em fibra.</p>

                <div id="linha-razao" style="display:none">
                    <div class="ftth-rotulo-campo">Razão</div>
                    <select class="ftth-campo" id="splitter-razao">
                        <?php foreach ($catalogo['DESBAL'] as $d): ?>
                            <option value="<?= htmlspecialchars($d['razao']) ?>"><?= htmlspecialchars($d['razao']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="linha-saidas" style="display:none">
                    <div class="ftth-rotulo-campo">Número de saídas</div>
                    <input class="ftth-campo" id="splitter-saidas" type="number" min="2" max="128" value="8">
                </div>

                <div class="ftth-rotulo-campo">Nome (opcional)</div>
                <input class="ftth-campo" id="splitter-nome" maxlength="60" placeholder="Ex.: SPL.01">

                <!-- A orientação deixou de ser escolha: segue a função do splitter
                     (atendimento na horizontal, derivação na vertical). Menos uma decisão
                     para quem está documentando a rede. -->
                <div id="splitter-saida"></div>
            </div>
            <div class="ftth-modal-rodape">
                <button class="ftth-btn ftth-btn--sec" id="btn-cancelar-splitter">Cancelar</button>
                <button class="ftth-btn ftth-btn--pri" id="btn-add-splitter">Adicionar ao diagrama</button>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<?php if (!$falha): ?>
<script>
window.FTTH_DIAGRAMA = {
    csrf:   <?= json_encode(ftth_csrf_token()) ?>,
    caixa:  <?= (int) $caixa['id'] ?>,
    nome:   <?= json_encode((string) $caixa['nome']) ?>,
    // Catálogo de razões para o select da edição — o mesmo que monta os cartões.
    catalogo: <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="js/diagrama.js?v=<?= time() ?>"></script>
<?php endif; ?>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
