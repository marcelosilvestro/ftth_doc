<?php
/**
 * ftth_doc :: regioes (cidade/bairro). Toda caixa e todo cabo pertencem a uma.
 *
 * Regra de exclusao: regiao so e excluida (logicamente) se estiver vazia — nao existe
 * "excluir em cascata" aqui, para ninguem apagar uma cidade inteira sem perceber.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Resultado.php';

final class Regiao
{
    public static function listar(): array
    {
        return Db::todos(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM tab_ftth_caixa c
                      WHERE c.regiao_id = r.id AND c.excluido_em IS NULL) AS caixas,
                    (SELECT COUNT(*) FROM tab_ftth_cabo_vao v
                      WHERE v.regiao_id = r.id AND v.excluido_em IS NULL) AS vaos,
                    (SELECT COUNT(*) FROM tab_ftth_importacao_item i
                      WHERE i.regiao_id = r.id AND i.status = "pendente") AS quarentena
               FROM tab_ftth_regiao r
              WHERE r.excluido_em IS NULL
              ORDER BY r.nome'
        );
    }

    public static function obter(int $id): ?array
    {
        return Db::um('SELECT * FROM tab_ftth_regiao WHERE id = ? AND excluido_em IS NULL', [$id]);
    }

    public static function criar(string $nome, ?float $lat, ?float $lng, int $zoom, string $usuario): Resultado
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 80) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome']);
        }
        if (Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = ?', [$nome])) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], 'Já existe uma região com esse nome.');
        }

        return Db::transacao(function () use ($nome, $lat, $lng, $zoom, $usuario) {
            Db::exec(
                'INSERT INTO tab_ftth_regiao (nome, lat, lng, zoom, criado_por, criado_em)
                 VALUES (?,?,?,?,?,NOW())',
                [$nome, $lat, $lng, max(3, min(21, $zoom)), $usuario]
            );
            $id = Db::ultimoId();
            Auditoria::registrar('regiao', $id, 'criar', null,
                ['nome' => $nome, 'lat' => $lat, 'lng' => $lng], $id);
            return Resultado::ok(['id' => $id] + (self::obter($id) ?? []));
        });
    }

    public static function alterar(int $id, string $nome, ?float $lat, ?float $lng, int $zoom,
                                   ?int $versao, string $usuario): Resultado
    {
        $antes = self::obter($id);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['regiao' => $id], 'Região não encontrada.');
        }
        $nome = trim($nome);
        if ($nome === '') {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome']);
        }
        $conflito = Db::valor('SELECT id FROM tab_ftth_regiao WHERE nome = ? AND id <> ?', [$nome, $id]);
        if ($conflito) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], 'Já existe uma região com esse nome.');
        }

        return Db::transacao(function () use ($id, $nome, $lat, $lng, $zoom, $versao, $usuario, $antes) {
            if (!Versao::avancar('regiao', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'regiao', 'id' => $id, 'versao_atual' => Versao::atual('regiao', $id)]);
            }
            Db::exec('UPDATE tab_ftth_regiao SET nome = ?, lat = ?, lng = ?, zoom = ? WHERE id = ?',
                [$nome, $lat, $lng, max(3, min(21, $zoom)), $id]);
            $depois = self::obter($id);
            Auditoria::registrar('regiao', $id, 'alterar', $antes, $depois, $id);
            return Resultado::ok($depois);
        });
    }

    /** Exclusao logica, so quando a regiao esta vazia. */
    public static function excluir(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = self::obter($id);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['regiao' => $id], 'Região não encontrada.');
        }

        $caixas = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_caixa WHERE regiao_id = ? AND excluido_em IS NULL', [$id]);
        $quarentena = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE regiao_id = ? AND status = "pendente"', [$id]);
        if ($caixas > 0 || $quarentena > 0) {
            return Resultado::erro('FTTH-TOP-016',
                ['caixas' => $caixas, 'quarentena' => $quarentena],
                'A região ainda tem ' . $caixas . ' caixa(s) e ' . $quarentena . ' item(ns) em quarentena.');
        }

        return Db::transacao(function () use ($id, $versao, $usuario, $antes) {
            if (!Versao::avancar('regiao', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'regiao', 'id' => $id, 'versao_atual' => Versao::atual('regiao', $id)]);
            }
            Db::exec('UPDATE tab_ftth_regiao SET excluido_em = NOW() WHERE id = ?', [$id]);
            Auditoria::registrar('regiao', $id, 'excluir', $antes, null, $id);
            return Resultado::ok(['id' => $id]);
        });
    }
}
