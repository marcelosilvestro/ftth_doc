<?php require_once(__DIR__ . '/../config.php'); ?>
<?php
/*
 * $ftth_tela_cheia = true ANTES deste include faz a página ocupar a janela sem barra de
 * rolagem. É preciso marcar no <html> porque o CSS do painel tem `html { overflow-y:
 * scroll }` — a barra é forçada a existir mesmo sem conteúdo para rolar, e com overflow
 * explícito no html o valor do body deixa de ser propagado para o viewport.
 */
$ftthHtmlClasse = !empty($ftth_tela_cheia) ? 'ftth-sem-scroll' : '';
?>
<!DOCTYPE html>
<?php if (isset($_SESSION['MM_Usuario'])): ?>
<html lang="pt-BR" class="<?= $ftthHtmlClasse ?>">
<?php else: ?>
<html lang="pt-BR" class="has-navbar-fixed-top <?= $ftthHtmlClasse ?>">
<?php endif; ?>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <title>MK - AUTH :: <?php echo isset($Manifest->{'name'}) ? $Manifest->{'name'} . " - V " . $Manifest->{'version'} : 'FTTH Doc'; ?></title>

    <!-- Grid isolado: NAO usar bootstrap.min.css inteiro, o reset dele vaza para o topo.php -->
    <link href="css/vendor/grid-utilities.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />

    <!-- jQuery vem do core e SEMPRE antes do mk-auth.js -->
    <script src="../../scripts/jquery.js"></script>
    <script src="../../scripts/mk-auth.js"></script>

    <link href="css/ftth.css?v=<?= time() ?>" rel="stylesheet" type="text/css" />
    <script src="js/ftth.js?v=<?= time() ?>"></script>

    <!-- Tema escuro do diagrama. Todo seletor e escopado em .ftth-diagrama, entao carregar
         sempre nao afeta as outras telas e evita um segundo caminho de include. -->
    <link href="css/diagrama.css?v=<?= time() ?>" rel="stylesheet" type="text/css" />
</head>
