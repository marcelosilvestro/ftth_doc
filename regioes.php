<?php
/**
 * ftth_doc :: regiões (cidade/bairro). Toda caixa e todo cabo pertencem a uma região.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Regiao.php';

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        switch ($_GET['ajax']) {
            case 'listar':
                Resultado::ok(['regioes' => Regiao::listar()])->enviar();

            case 'criar':
                ftth_exigir_csrf();
                Regiao::criar(
                    (string) ($_POST['nome'] ?? ''),
                    $_POST['lat'] !== '' ? (float) $_POST['lat'] : null,
                    $_POST['lng'] !== '' ? (float) $_POST['lng'] : null,
                    (int) ($_POST['zoom'] ?? 15),
                    $usuario_logado
                )->enviar();

            case 'alterar':
                ftth_exigir_csrf();
                Regiao::alterar(
                    (int) ($_POST['id'] ?? 0),
                    (string) ($_POST['nome'] ?? ''),
                    $_POST['lat'] !== '' ? (float) $_POST['lat'] : null,
                    $_POST['lng'] !== '' ? (float) $_POST['lng'] : null,
                    (int) ($_POST['zoom'] ?? 15),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            case 'excluir':
                ftth_exigir_csrf();
                Regiao::excluir(
                    (int) ($_POST['id'] ?? 0),
                    isset($_POST['versao']) ? (int) $_POST['versao'] : null,
                    $usuario_logado
                )->enviar();

            default:
                Resultado::erro('FTTH-SYS-002')->enviar(400);
        }
    } catch (Throwable $e) {
        Log::excecao('regioes.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/* ------------------------------------------------------------------ página */
$regioes = [];
$falha   = null;
try {
    $regioes = Regiao::listar();
} catch (Throwable $e) {
    Log::excecao('regioes.carregar', $e);
    $falha = 'Não foi possível carregar as regiões. FTTH-SYS-001 · ' . Resultado::requestId();
}

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="ftth-wrap">
    <h1 class="ftth-titulo">Regiões</h1>
    <p class="ftth-sub">
        Separam a rede por cidade ou bairro. O mapa, a busca e os relatórios trabalham sempre
        dentro da região ativa. Uma região só pode ser excluída quando estiver vazia.
    </p>

    <div class="ftth-acoes-topo">
        <a class="ftth-btn ftth-btn--sec" href="index.php"><i class="bi-arrow-left"></i> Configurações</a>
        <a class="ftth-btn ftth-btn--sec" href="mapa.php"><i class="bi-geo-fill"></i> Mapa</a>
    </div>

    <?php if ($falha): ?><div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div><?php endif; ?>

    <!-- Cadastro numa linha só: o espaço vertical é da lista, não do formulário.
         O título vive num span próprio porque o JS troca o texto ao editar. -->
    <div class="ftth-card">
        <div class="ftth-card-topo">
            <h2><i class="bi-geo-fill"></i> <span id="titulo-form">Nova região</span></h2>
            <span class="ftth-sub" style="margin:0">Latitude e longitude são o centro onde o mapa abre.</span>
        </div>
        <input type="hidden" id="f-id"><input type="hidden" id="f-versao">
        <div class="ftth-form-linha">
            <div class="ftth-form-campo">
                <label for="f-nome">Nome</label>
                <input id="f-nome" class="ftth-campo" maxlength="80">
            </div>
            <div class="ftth-form-campo ftth-form-campo--md">
                <label for="f-lat">Latitude</label>
                <input id="f-lat" class="ftth-campo">
            </div>
            <div class="ftth-form-campo ftth-form-campo--md">
                <label for="f-lng">Longitude</label>
                <input id="f-lng" class="ftth-campo">
            </div>
            <div class="ftth-form-campo ftth-form-campo--sm">
                <label for="f-zoom">Zoom</label>
                <input id="f-zoom" class="ftth-campo" type="number" min="3" max="21" value="15">
            </div>
            <div class="ftth-form-acoes">
                <button class="ftth-btn ftth-btn--pri" id="btn-salvar">Salvar</button>
                <button class="ftth-btn ftth-btn--sec" id="btn-cancelar" style="display:none">Cancelar</button>
            </div>
        </div>
        <div id="saida" style="margin-top:10px"></div>
    </div>

    <div class="ftth-card">
        <div class="ftth-card-topo"><h2>Cadastradas</h2></div>
        <table class="ftth-tabela" id="tabela">
            <thead><tr>
                <th>Nome</th><th>Caixas</th><th>Vãos</th><th>Quarentena</th>
                <th>Centro</th><th style="width:170px"></th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode(ftth_csrf_token()) ?>;
    var regioes = <?= json_encode($regioes) ?>;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
    }
    function aviso(texto, tipo) {
        $('#saida').html('<div class="ftth-aviso ' + (tipo || '') + '">' + esc(texto) + '</div>');
    }

    function desenhar() {
        var html = '';
        regioes.forEach(function (r) {
            var centro = (r.lat && r.lng) ? (parseFloat(r.lat).toFixed(5) + ', ' + parseFloat(r.lng).toFixed(5)) : '—';
            html += '<tr>'
                 +  '<td><strong>' + esc(r.nome) + '</strong></td>'
                 +  '<td class="mono">' + r.caixas + '</td>'
                 +  '<td class="mono">' + r.vaos + '</td>'
                 +  '<td>' + (parseInt(r.quarentena, 10)
                        ? '<span class="ftth-selo ftth-selo--novo">' + r.quarentena + ' a revisar</span>'
                        : '—') + '</td>'
                 +  '<td class="mono">' + centro + '</td>'
                 +  '<td><button class="ftth-btn ftth-btn--sec btn-editar" data-id="' + r.id + '">Editar</button> '
                 +      '<button class="ftth-btn ftth-btn--sec btn-excluir" data-id="' + r.id + '">Excluir</button>'
                 +  '</td></tr>';
        });
        $('#tabela tbody').html(html || '<tr><td colspan="6" class="ftth-sub">Nenhuma região ainda.</td></tr>');
    }

    function recarregar() {
        FTTH.chamar({
            url: 'regioes.php?ajax=listar',
            onOk: function (d) { regioes = d.regioes; desenhar(); },
            onErro: function (m) { aviso(m, 'ftth-aviso--erro'); }
        });
    }

    function limpar() {
        $('#f-id, #f-versao, #f-nome, #f-lat, #f-lng').val('');
        $('#f-zoom').val(15);
        $('#titulo-form').text('Nova região');
        $('#btn-cancelar').hide();
    }

    $('#btn-salvar').on('click', function () {
        var id = $('#f-id').val();
        FTTH.chamar({
            url: 'regioes.php?ajax=' + (id ? 'alterar' : 'criar'),
            method: 'POST',
            data: {
                csrf: CSRF, id: id, versao: $('#f-versao').val(),
                nome: $('#f-nome').val(), lat: $('#f-lat').val(),
                lng: $('#f-lng').val(), zoom: $('#f-zoom').val()
            },
            onOk: function () { aviso('Região salva.', 'ftth-aviso--ok'); limpar(); recarregar(); },
            onErro: function (m) { aviso(m, 'ftth-aviso--erro'); }
        });
    });

    $('#btn-cancelar').on('click', limpar);

    $(document).on('click', '.btn-editar', function () {
        var id = parseInt($(this).data('id'), 10);
        var r = regioes.filter(function (x) { return parseInt(x.id, 10) === id; })[0];
        if (!r) return;
        $('#f-id').val(r.id); $('#f-versao').val(r.versao);
        $('#f-nome').val(r.nome); $('#f-lat').val(r.lat || ''); $('#f-lng').val(r.lng || '');
        $('#f-zoom').val(r.zoom);
        $('#titulo-form').text('Editando: ' + r.nome);
        $('#btn-cancelar').show();
        window.scrollTo(0, 0);
    });

    $(document).on('click', '.btn-excluir', function () {
        var id = parseInt($(this).data('id'), 10);
        var r = regioes.filter(function (x) { return parseInt(x.id, 10) === id; })[0];
        if (!r || !confirm('Excluir a região "' + r.nome + '"?')) return;
        FTTH.chamar({
            url: 'regioes.php?ajax=excluir',
            method: 'POST',
            data: { csrf: CSRF, id: id, versao: r.versao },
            onOk: function () { aviso('Região excluída.', 'ftth-aviso--ok'); recarregar(); },
            onErro: function (m) { aviso(m, 'ftth-aviso--erro'); }
        });
    });

    desenhar();
})();
</script>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
