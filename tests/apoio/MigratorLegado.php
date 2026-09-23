<?php
/**
 * ftth_doc :: runner das migrations numeradas — APOSENTADO.
 *
 * Foi o Migrator do addon ate 23/09/2026, quando o schema virou um baseline unico
 * (lib/Schema.php + sql/baseline.sql). Continua aqui por um motivo so: montar o schema
 * antigo a partir de sql/historico/ para que tests/comparar_schema.php possa provar que a
 * consolidacao nao perdeu nada. NAO e usado por nenhuma tela e NAO vai no pacote distribuido.
 *
 * Regra que ele seguia, e que e justamente a que nao serve mais: cada arquivo roda UMA vez;
 * arquivo alterado depois de aplicado e apenas sinalizado, nunca reaplicado.
 */
require_once __DIR__ . '/../../lib/Db.php';

final class MigratorLegado
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    /** Cria a tabela de controle se ainda nao existir. */
    public function prepararControle(): void
    {
        $sql = file_get_contents($this->dir . '/001_migration.sql');
        foreach ($this->comandos($sql) as $cmd) {
            Db::pdo()->exec($cmd);
        }
    }

    /**
     * Situacao de cada arquivo. NAO cria nada: so ler o estado nao pode ter efeito colateral
     * no banco (abrir a pagina inicial nao deve criar tabela).
     * @return array<int,array{arquivo:string,checksum:string,aplicada:bool,alterada:bool}>
     */
    public function status(): array
    {
        $aplicadas = [];
        try {
            foreach (Db::todos('SELECT migration, checksum FROM tab_ftth_migration WHERE resultado = "ok"') as $r) {
                $aplicadas[$r['migration']] = $r['checksum'];
            }
        } catch (Throwable $e) {
            // Tabela de controle ainda nao existe: nada foi aplicado.
        }

        $lista = [];
        foreach ($this->arquivos() as $arq) {
            $cs = hash_file('sha256', $this->dir . '/' . $arq);
            $lista[] = [
                'arquivo'  => $arq,
                'checksum' => $cs,
                'aplicada' => isset($aplicadas[$arq]),
                'alterada' => isset($aplicadas[$arq]) && $aplicadas[$arq] !== $cs,
            ];
        }
        return $lista;
    }

    /**
     * Aplica o que falta.
     * @return array{aplicadas:array,erro:?string,alteradas:array}
     */
    public function aplicar(string $usuario): array
    {
        $this->prepararControle();
        $status    = $this->status();
        $alteradas = array_values(array_filter($status, fn($s) => $s['alterada']));
        $feitas    = [];

        foreach ($status as $s) {
            if ($s['aplicada']) {
                continue;
            }
            $caminho = $this->dir . '/' . $s['arquivo'];
            $ini = microtime(true);
            try {
                foreach ($this->comandos(file_get_contents($caminho)) as $cmd) {
                    Db::pdo()->exec($cmd);
                }
            } catch (Throwable $e) {
                Db::exec(
                    'INSERT INTO tab_ftth_migration (migration, checksum, executed_at, executed_by, duracao_ms, resultado, erro)
                     VALUES (?, ?, NOW(), ?, ?, "erro", ?)
                     ON DUPLICATE KEY UPDATE resultado = "erro", erro = VALUES(erro), executed_at = NOW()',
                    [$s['arquivo'], $s['checksum'], $usuario,
                     (int) ((microtime(true) - $ini) * 1000), $e->getMessage()]
                );
                return ['aplicadas' => $feitas, 'erro' => $s['arquivo'] . ': ' . $e->getMessage(),
                        'alteradas' => $alteradas];
            }

            Db::exec(
                'INSERT INTO tab_ftth_migration (migration, checksum, executed_at, executed_by, duracao_ms, resultado)
                 VALUES (?, ?, NOW(), ?, ?, "ok")
                 ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), executed_at = NOW(),
                                         executed_by = VALUES(executed_by), resultado = "ok", erro = NULL',
                [$s['arquivo'], $s['checksum'], $usuario, (int) ((microtime(true) - $ini) * 1000)]
            );
            $feitas[] = $s['arquivo'];
        }

        return ['aplicadas' => $feitas, 'erro' => null, 'alteradas' => $alteradas];
    }

    /** @return string[] */
    private function arquivos(): array
    {
        $arqs = glob($this->dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
        $arqs = array_map('basename', $arqs);
        sort($arqs, SORT_STRING);
        return $arqs;
    }

    /**
     * Quebra o arquivo em comandos por ";" no fim da linha, ignorando linhas de comentario.
     * Suficiente para o nosso DDL (sem procedures, sem delimiter customizado).
     * @return string[]
     */
    private function comandos(string $sql): array
    {
        $linhas = preg_split('/\R/', $sql);
        $buffer = '';
        $saida  = [];
        foreach ($linhas as $linha) {
            $t = trim($linha);
            if ($t === '' || str_starts_with($t, '--')) {
                continue;
            }
            $buffer .= $linha . "\n";
            if (str_ends_with($t, ';')) {
                $saida[] = trim($buffer);
                $buffer  = '';
            }
        }
        if (trim($buffer) !== '') {
            $saida[] = trim($buffer);
        }
        return $saida;
    }
}
