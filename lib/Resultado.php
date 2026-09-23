<?php
/**
 * ftth_doc :: envelope unico de resposta (3b.3).
 *
 * Toda operacao do lib/ devolve um Resultado. Paginas, AJAX e a futura API/v2ftth.api
 * consomem o mesmo formato:
 *   { ok, data, warnings[], errors[{code,message,details}], request_id }
 */
require_once __DIR__ . '/Erros.php';

final class Resultado implements JsonSerializable
{
    private static ?string $requestId = null;

    public bool $ok;
    public $data;
    public array $warnings = [];
    public array $errors   = [];

    private function __construct(bool $ok, $data = null)
    {
        $this->ok   = $ok;
        $this->data = $data;
    }

    /** ID unico da requisicao: aparece na resposta, no log e na auditoria (3b.5). */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = 'REQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        }
        return self::$requestId;
    }

    public static function ok($data = null): self
    {
        return new self(true, $data);
    }

    public static function erro(string $code, array $details = [], ?string $mensagem = null): self
    {
        $r = new self(false, null);
        $r->addErro($code, $details, $mensagem);
        return $r;
    }

    public function addErro(string $code, array $details = [], ?string $mensagem = null): self
    {
        $this->ok = false;
        $this->errors[] = [
            'code'    => $code,
            'message' => $mensagem ?? Erros::mensagem($code),
            'details' => $details,
        ];
        return $this;
    }

    public function addAviso(string $code, array $details = [], ?string $mensagem = null): self
    {
        $this->warnings[] = [
            'code'    => $code,
            'message' => $mensagem ?? Erros::mensagem($code),
            'details' => $details,
        ];
        return $this;
    }

    public function primeiroCodigo(): ?string
    {
        return $this->errors[0]['code'] ?? null;
    }

    /** A mensagem que o usuário leria — útil quando um erro vira item de uma lista. */
    public function primeiraMensagem(): ?string
    {
        return $this->errors[0]['message'] ?? null;
    }

    public function jsonSerialize(): array
    {
        return [
            'ok'         => $this->ok,
            'data'       => $this->data,
            'warnings'   => $this->warnings,
            'errors'     => $this->errors,
            'request_id' => self::requestId(),
        ];
    }

    /** Resposta AJAX padrao: encerra a requisicao com o envelope em JSON. */
    public function enviar(int $status = 0): void
    {
        if (!headers_sent()) {
            if ($status === 0) {
                $status = $this->ok ? 200 : 400;
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Request-Id: ' . self::requestId());
        }
        echo json_encode($this, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
