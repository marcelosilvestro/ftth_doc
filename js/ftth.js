/* ftth_doc :: utilitarios de front compartilhados.
 *
 * Detalhe importante descoberto na VM (modo HHVM): quando a sessao expira, quem responde
 * e o proprio core do MK-AUTH, com HTML de "Acesso negado" — nao o nosso config.php.
 * Entao todo AJAX precisa aguentar receber HTML no lugar de JSON e mandar para o login,
 * em vez de estourar um erro de parse no console.
 */
window.FTTH = (function () {
    'use strict';

    var URL_LOGIN = '/admin/login.php';

    function pareceLogin(texto) {
        return typeof texto === 'string' &&
            (texto.indexOf('Acesso negado') !== -1 || texto.indexOf('login.hhvm') !== -1 ||
             texto.indexOf('login.php') !== -1);
    }

    function irParaLogin() {
        window.location.href = URL_LOGIN;
    }

    /**
     * Escapa texto para interpolar em HTML. Estava copiada em mapa.js, regioes.php e
     * importar.php; fica aqui para a proxima tela nao virar a quarta copia.
     */
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /** Texto de erro pronto para exibir: mensagem + codigo + request_id. */
    function erroDe(resp) {
        if (resp && resp.errors && resp.errors.length) {
            var e = resp.errors[0];
            var txt = e.message + ' [' + e.code;
            if (resp.request_id) txt += ' · ' + resp.request_id;
            txt += ']';
            if (e.details && e.details.detalhe) txt += '\n' + e.details.detalhe;
            return txt;
        }
        return 'Não foi possível concluir a operação.';
    }

    /**
     * Chamada AJAX padrao do addon. Sempre recebe texto e decide o que fazer:
     *  - HTML de login  -> redireciona
     *  - JSON com ok    -> onOk(data, resp)
     *  - JSON com erro  -> onErro(texto, resp)
     */
    /**
     * Aceita objeto comum ou FormData (upload). Atencao: em .fail o primeiro argumento
     * e o jqXHR, nao o corpo — ler o corpo errado ai fazia toda mensagem de erro do
     * servidor virar "resposta inesperada".
     */
    function chamar(opts) {
        var ehFormData = (typeof FormData !== 'undefined') && (opts.data instanceof FormData);
        var cfg = {
            url: opts.url,
            method: opts.method || 'GET',
            dataType: 'text'
        };
        if (opts.data) cfg.data = opts.data;
        if (ehFormData) {
            cfg.processData = false;
            cfg.contentType = false;
        }

        return jQuery.ajax(cfg)
            .done(function (texto) { tratar(texto, opts); })
            .fail(function (jqXHR) { tratar(jqXHR && jqXHR.responseText, opts); });
    }

    function tratar(texto, opts) {
        if (pareceLogin(texto)) { irParaLogin(); return; }

        var resp;
        try {
            resp = JSON.parse(texto);
        } catch (e) {
            // Mostra um pedaco do que veio: sem isso, depurar vira adivinhacao.
            var trecho = String(texto || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 300);
            if (opts.onErro) {
                opts.onErro('Resposta inesperada do servidor.' + (trecho ? '\n' + trecho : ''), null);
            }
            return;
        }
        if (resp.sessao_expirada) { irParaLogin(); return; }

        if (resp.ok) {
            if (opts.onOk) opts.onOk(resp.data, resp);
        } else if (opts.onErro) {
            opts.onErro(erroDe(resp), resp);
        }
    }

    /**
     * Toast no canto inferior direito. O painel lateral rola, e um aviso escrito no fim
     * dele pode nascer fora da tela -- foi o que aconteceu com o erro de excluir cabo.
     * O toast aparece sempre, some sozinho e sai no clique.
     *
     * @param tipo  'ok' | 'info' | 'avis' | 'erro'
     * @param itens lista opcional de detalhes (uma linha cada)
     */
    function toast(tipo, texto, itens) {
        var icones = { ok: 'bi-check-circle-fill', info: 'bi-info-circle-fill',
                       avis: 'bi-exclamation-triangle-fill', erro: 'bi-x-octagon-fill' };

        var $pilha = jQuery('#ftth-toasts');
        if (!$pilha.length) {
            $pilha = jQuery('<div id="ftth-toasts" class="ftth-toasts"></div>').appendTo('body');
        }

        var html = '<i class="' + (icones[tipo] || icones.ok) + '"></i>' + esc(texto);
        if (itens && itens.length) {
            html += '<ul>';
            itens.forEach(function (i) { html += '<li>' + esc(i) + '</li>'; });
            html += '</ul>';
        }

        var $t = jQuery('<div class="ftth-toast ftth-toast--' + tipo + '">' + html + '</div>');
        $pilha.append($t);
        window.setTimeout(function () { $t.addClass('aparece'); }, 10);

        // Erro fica mais tempo: é o que o usuário precisa ler com calma.
        var sumir = function () {
            $t.removeClass('aparece').addClass('sai');
            window.setTimeout(function () { $t.remove(); }, 220);
        };
        var relogio = window.setTimeout(sumir, tipo === 'erro' ? 9000 : 4000);
        $t.on('click', function () { window.clearTimeout(relogio); sumir(); });
    }

    return { chamar: chamar, erroDe: erroDe, irParaLogin: irParaLogin, esc: esc, toast: toast };
})();
