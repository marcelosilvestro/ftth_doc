/* ftth_doc :: primeiros passos — o assistente de quem acabou de instalar o addon.
 *
 * Cinco passos, na ordem em que a rede existe de verdade: chave do Google, região, POP,
 * primeira caixa na rua e o primeiro cabo entre os dois. O passo atual vem do banco
 * (mapa.php?ajax=passos, lib/PrimeirosPassos.php) e é pedido de novo a cada 'ftth:mudou' que
 * o mapa dispara — o assistente nunca "acha" que um passo foi feito.
 *
 * Duas formas na tela:
 *   card   centralizado sobre o mapa, nos momentos de ler e decidir;
 *   balão  fixo no topo do mapa, quando a pessoa precisa mexer no mapa (posicionar a
 *          região, marcar o ponto, desenhar o cabo). Não some como um toast.
 *
 * O mapa só é acionado pelo window.FTTH_MAPA_API (js/mapa.js). Sem chave do Google não
 * existe mapa, então o passo 1 funciona sozinho.
 */
(function () {
    'use strict';

    var cfg = window.FTTH_MAPA || {};
    var passos = cfg.passos || null;
    var fechado = !!(passos && passos.pulado);   // "pular por agora"
    var chaveRecusada = false;                   // o Google devolveu gm_authFailure
    var mostrandoFim = false;                    // o card de conclusão está na tela
    var acompanhou = false;                      // o assistente esteve aberto nesta visita
    var timer = null;

    var NUMERO = { chave: 1, regiao: 2, pop: 3, caixa: 4, cabo: 5 };
    var ORDEM = ['chave', 'regiao', 'pop', 'caixa', 'cabo'];

    function esc(s) { return FTTH.esc(s); }
    function api() { return window.FTTH_MAPA_API || null; }

    /** O passo em que a pessoa está. A chave recusada pelo Google volta tudo para o 1. */
    function passoAtual() {
        if (!passos) return null;
        return chaveRecusada ? 'chave' : passos.atual;
    }

    function prontos() {
        return passos ? passos.prontos - (chaveRecusada && passos.feitos.chave ? 1 : 0) : 0;
    }

    /* ------------------------------------------------------------------ desenho */

    /** A rede em miniatura: POP — cabo — caixa — clientes, com o que já existe em verde. */
    function cadeia(foco) {
        var f = passos ? passos.feitos : {};
        var pop = passos && passos.pop ? passos.pop.nome : 'POP';
        var cx = passos && passos.caixa ? passos.caixa.nome : 'CEO / CTO';
        var cls = function (feito, eFoco) {
            return (feito ? ' feito' : '') + (eFoco ? ' foco' : '');
        };
        return '<span class="ftth-onb-no' + cls(f.pop, foco === 'pop') + '">'
             +   '<i class="bi-hdd-rack-fill"></i>' + esc(pop) + '</span>'
             + '<span class="ftth-onb-fio' + cls(f.cabo, foco === 'cabo') + '"></span>'
             + '<span class="ftth-onb-no' + cls(f.caixa, foco === 'caixa') + '">'
             +   '<i class="bi-box-seam"></i>' + esc(cx) + '</span>'
             + '<span class="ftth-onb-fio ftth-onb-fio--depois"></span>'
             + '<span class="ftth-onb-no ftth-onb-no--depois"><i class="bi-people-fill"></i>Clientes</span>';
    }

    function mostrarCard(passo) {
        $('#onb-balao').hide();
        $('#painel').hide();              // a ficha que abriu sozinha não fica atrás do card
        $('.ftth-onb-passo').hide().filter('[data-passo="' + passo + '"]').show();

        var atual = NUMERO[passo] || 6;
        $('#onb-trilha li').each(function () {
            var n = NUMERO[$(this).data('passo')];
            var feito = passos.feitos[$(this).data('passo')] && !(chaveRecusada && n === 1);
            $(this).toggleClass('feito', !!feito && n !== atual).toggleClass('atual', n === atual);
        });
        $('.ftth-onb-cadeia').each(function () { $(this).html(cadeia($(this).data('foco'))); });
        $('.js-onb-pop').text(passos.pop ? passos.pop.nome : 'POP');
        $('.js-onb-caixa').text(passos.caixa ? passos.caixa.nome : 'caixa');

        var fim = passo === 'fim';
        // Sem chave não existe mapa nenhum: pular o passo 1 deixaria a tela vazia.
        var podePular = !fim && passo !== 'chave';
        $('#onb-pular, #onb-fechar').toggle(podePular);
        $('#onb-contagem').text(fim ? '' : 'Passo ' + atual + ' de ' + ORDEM.length);
        $('#onb-concluir').toggle(fim);

        if (passo === 'chave') {
            $('#onb-chave-titulo').text(chaveRecusada ? 'O Google recusou a chave' : 'Bem-vindo ao FTTH Doc');
            $('#onb-chave-texto').toggle(!chaveRecusada);
            $('#onb-chave-erro').toggle(chaveRecusada).html(chaveRecusada
                ? 'O mapa não abriu com a chave salva. No console do Google, confira três coisas: '
                  + 'a <strong>Maps JavaScript API</strong> está ativada, o projeto tem '
                  + '<strong>faturamento</strong> ativo e o endereço <code>' + esc($('#onb-host').text())
                  + '</code> está liberado na chave. Depois cole a chave de novo.'
                : '');
            if (chaveRecusada) $('#onb-chave-ajuda').attr('open', 'open');
        }
        if (fim) {
            $('#onb-link-pop').attr('href', passos.pop ? 'pop.php?caixa=' + passos.pop.id : '#');
            $('#onb-link-caixa').attr('href', passos.caixa ? 'caixa.php?id=' + passos.caixa.id : '#');
        }

        var jaAberto = $('#onb').is(':visible') && $('#onb').data('passo') === passo;
        $('#onb').data('passo', passo).show();
        acompanhou = true;
        if (!jaAberto) {
            setTimeout(function () {
                $('#onb .ftth-onb-passo:visible').find('input:visible, .ftth-onb-acao').first().trigger('focus');
            }, 50);
        }
    }

    function mostrarBalao(passo) {
        var a = api();
        var textos = {
            regiao: ['Posicione a região',
                     'Arraste e aproxime o mapa até a área da rede e clique em Confirmar.'],
            pop:    ['Marque o POP', 'Clique no mapa exatamente onde fica o seu POP.'],
            caixa:  ['A primeira caixa',
                     'Clique no mapa onde fica a caixa que recebe o cabo do '
                     + (passos.pop ? passos.pop.nome : 'POP') + '.'],
            cabo:   ['O primeiro cabo', a && a.desenhando()
                     ? 'Siga a rua clicando no mapa e termine clicando em '
                       + (passos.caixa ? passos.caixa.nome : 'na caixa') + '. Depois, Finalizar.'
                     : 'Escolha a capacidade do cabo e clique em Desenhar.']
        };
        var t = textos[passo];
        $('#onb').hide();
        $('#onb-balao-num').text(NUMERO[passo]);
        $('#onb-balao-titulo').text('Passo ' + NUMERO[passo] + ' de ' + ORDEM.length + ' · ' + t[0]);
        $('#onb-balao-texto').text(t[1]);
        // No posicionamento da região o card do mapa já tem Cancelar: dois botões para a
        // mesma coisa só confundem.
        $('#onb-balao-voltar').toggle(passo !== 'regiao');
        $('#onb-balao').show();
        acompanhou = true;
    }

    function esconder() {
        $('#onb, #onb-balao').hide();
    }

    /** Decide o que aparece, a partir do passo atual e do que o mapa está fazendo agora. */
    function render() {
        if (!passos) return;
        var passo = passoAtual();

        $('#onb-pilula').toggle(passo !== null);
        $('#onb-pilula-conta').text(prontos() + '/' + ORDEM.length);

        if (mostrandoFim) { mostrarCard('fim'); return; }
        if (passo === null || (fechado && passo !== 'chave')) { esconder(); return; }

        var a = api();
        if (!a) {
            // Sem mapa: só o passo da chave se faz. Com chave e mapa ainda carregando, espera.
            if (passo === 'chave') mostrarCard('chave'); else esconder();
            return;
        }
        if (passo === 'chave') { mostrarCard('chave'); return; }

        if (a.posicionando()) {
            mostrarBalao('regiao');
        } else if (a.modo() === 'caixa' && (passo === 'pop' || passo === 'caixa')) {
            mostrarBalao(passo);
        } else if (a.modo() === 'cabo' && passo === 'cabo') {
            mostrarBalao('cabo');
        } else if (a.modo() !== 'navegar') {
            esconder();                    // está fazendo outra coisa: não atrapalha
        } else {
            mostrarCard(passo);
        }
    }

    /* ------------------------------------------------------------------ estado */

    /** Pede o estado de novo. Várias mudanças seguidas (criar + recarregar) viram uma consulta. */
    function atualizar() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            FTTH.chamar({
                url: 'mapa.php?ajax=passos',
                onOk: function (d) {
                    var antes = passoAtual();
                    passos = d;
                    if (api()) api().atualizarPassos(d);
                    // Acabou de fechar o último passo com o assistente acompanhando: parabéns.
                    if (antes !== null && d.atual === null && acompanhou && !fechado) mostrandoFim = true;
                    render();
                }
            });
        }, 200);
    }

    function gravarPulo(pular) {
        FTTH.chamar({
            url: 'mapa.php?ajax=passos_pular',
            method: 'POST',
            data: { csrf: cfg.csrf, pular: pular ? '1' : '0' },
            onOk: function (d) { passos = d; }
        });
    }

    function reabrir() {
        fechado = false;
        if (passos && passos.pulado) gravarPulo(false);
        if (passoAtual() === null) mostrandoFim = true;   // tudo pronto: mostra o resumo final
        if (api() && api().modo() !== 'navegar') api().cancelar();
        render();
    }

    /* ------------------------------------------------------------------ Google */

    // O Google chama esta função global quando recusa a chave (API desativada, faturamento,
    // referenciador). Sem ela o mapa só fica cinza com um "Oops" em inglês.
    window.gm_authFailure = function () {
        chaveRecusada = true;
        mostrandoFim = false;
        render();
    };

    /* ------------------------------------------------------------------ ações */

    $(function () {
        if (!passos) return;

        $(document).on('ftth:mudou', atualizar);
        $(document).on('ftth:mapa-pronto', function () {
            api().atualizarPassos(passos);
            render();
        });

        // Passo 1: a chave. Salvar recarrega a página, que é quando o Google a lê.
        var salvarChave = function () {
            var chave = $.trim($('#onb-chave').val());
            if (!chave) {
                $('#onb-chave-saida').html('<p class="ftth-sub ftth-onb-erro">Cole a chave do Google Maps.</p>');
                return;
            }
            var $b = $('#onb-chave-salvar').prop('disabled', true);
            FTTH.chamar({
                url: 'mapa.php?ajax=ajustes',
                method: 'POST',
                data: { csrf: cfg.csrf, google_maps_key: chave },
                onOk: function () { window.location.reload(); },
                onErro: function (m) {
                    $('#onb-chave-saida').html('<div class="ftth-aviso ftth-aviso--erro">' + esc(m) + '</div>');
                    $b.prop('disabled', false);
                }
            });
        };
        $('#onb-chave-salvar').on('click', salvarChave);
        $('#onb-chave').on('keydown', function (e) { if (e.key === 'Enter') salvarChave(); });

        $('#onb-host-copiar').on('click', function () {
            var texto = $('#onb-host').text();
            var $b = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(texto).then(function () {
                    $b.html('<i class="bi-check-lg"></i> Copiado');
                });
            } else {
                window.prompt('Copie o endereço:', texto);
            }
        });

        // Passo 2: o nome, e depois o mapa até lá (o card "Nova região" do mapa confirma).
        var seguirRegiao = function () {
            var recusa = api() ? api().novaRegiao($.trim($('#onb-regiao').val())) : 'O mapa ainda está carregando.';
            $('#onb-regiao-saida').html(recusa
                ? '<p class="ftth-sub ftth-onb-erro">' + esc(recusa) + '</p>' : '');
            if (!recusa) render();
        };
        $('#onb-regiao-seguir').on('click', seguirRegiao);
        $('#onb-regiao').on('keydown', function (e) { if (e.key === 'Enter') seguirRegiao(); });

        // Passos 3, 4 e 5: o mapa entra no modo certo, já preenchido.
        $('.ftth-onb-acao').on('click', function () {
            var a = api();
            if (!a) return;
            var acao = $(this).data('acao');
            if (acao === 'pop') {
                a.novoPonto('DC', 'POP');
            } else if (acao === 'caixa') {
                a.novoPonto('CEO', '', passos.pop ? passos.pop.regiao_id : 0);
            } else if (acao === 'cabo' && passos.pop) {
                a.novoCabo(passos.pop, passos.caixa);
            }
            render();
        });

        $('#onb-balao-voltar').on('click', function () {
            if (api()) api().cancelar();
            render();
        });

        $('#onb-pular, #onb-fechar').on('click', function () {
            fechado = true;
            gravarPulo(true);
            render();
        });
        $('#onb-pilula, #onb-rever').on('click', reabrir);
        $('#onb-concluir').on('click', function () {
            mostrandoFim = false;
            render();
        });

        render();
    });
})();
