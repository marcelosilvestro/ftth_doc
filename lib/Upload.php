<?php
/**
 * ftth_doc :: recebimento de arquivos (3b.11).
 *
 * Trata upload como superficie de ataque: valida extensao, MIME real, tamanho e conteudo,
 * gera nome proprio (nunca usa o nome enviado) e grava em pasta sem execucao.
 */
require_once __DIR__ . '/Resultado.php';

final class Upload
{
    /** Tipos aceitos: extensao => [mimes reais aceitos]. */
    private const ACEITOS = [
        'kmz' => ['application/zip', 'application/octet-stream', 'application/vnd.google-earth.kmz'],
        'kml' => ['text/xml', 'application/xml', 'text/plain', 'application/vnd.google-earth.kml+xml'],
        'jpg' => ['image/jpeg'],
        'jpeg'=> ['image/jpeg'],
        'png' => ['image/png'],
        'webp'=> ['image/webp'],
    ];

    /**
     * Valida e move o arquivo para $destinoDir com nome gerado.
     * @return array{ok:bool,code?:string,detalhe?:string,caminho?:string,nome_original?:string,bytes?:int,mime?:string}
     */
    public static function receber(array $arquivo, array $extensoesPermitidas, string $destinoDir, int $maxBytes): array
    {
        if (!isset($arquivo['tmp_name'], $arquivo['error']) || $arquivo['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'code' => 'FTTH-SYS-002',
                    'detalhe' => 'Falha no envio do arquivo (código ' . ($arquivo['error'] ?? '?') . ').'];
        }
        if (!is_uploaded_file($arquivo['tmp_name'])) {
            return ['ok' => false, 'code' => 'FTTH-SYS-002', 'detalhe' => 'Origem do arquivo inválida.'];
        }
        if ($arquivo['size'] <= 0 || $arquivo['size'] > $maxBytes) {
            return ['ok' => false, 'code' => 'FTTH-SYS-002',
                    'detalhe' => 'Tamanho fora do limite (máximo ' . round($maxBytes / 1048576) . ' MB).'];
        }

        $nomeOriginal = (string) ($arquivo['name'] ?? 'arquivo');
        $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensoesPermitidas, true) || !isset(self::ACEITOS[$ext])) {
            return ['ok' => false, 'code' => 'FTTH-SYS-002', 'detalhe' => 'Extensão não aceita: ' . $ext];
        }

        $mime = 'application/octet-stream';
        if (class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $fi->file($arquivo['tmp_name']);
        }
        if (!in_array($mime, self::ACEITOS[$ext], true)) {
            return ['ok' => false, 'code' => 'FTTH-SYS-002',
                    'detalhe' => 'Conteúdo não corresponde à extensão (' . $mime . ').'];
        }

        if (!is_dir($destinoDir) && !@mkdir($destinoDir, 0770, true)) {
            return ['ok' => false, 'code' => 'FTTH-SYS-001', 'detalhe' => 'Pasta de destino indisponível.'];
        }

        // Nome proprio: nada do que o usuario enviou entra no caminho (sem path traversal).
        $nome = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $caminho = rtrim($destinoDir, '/\\') . '/' . $nome;

        if (!move_uploaded_file($arquivo['tmp_name'], $caminho)) {
            return ['ok' => false, 'code' => 'FTTH-SYS-001', 'detalhe' => 'Não foi possível gravar o arquivo.'];
        }
        @chmod($caminho, 0640);

        return ['ok' => true, 'caminho' => $caminho, 'nome_original' => basename($nomeOriginal),
                'bytes' => (int) $arquivo['size'], 'mime' => $mime];
    }
}
