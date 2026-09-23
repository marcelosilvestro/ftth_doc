<?php
/**
 * ftth_doc :: catalogo de codigos de erro estaveis (3b.4).
 *
 * O codigo e contrato: interface, logs, testes e a futura API dependem dele, nunca do texto.
 * Mensagem aqui e a que o USUARIO ve — sem SQL, sem nome de tabela, sem stack trace.
 */
final class Erros
{
    public const MENSAGENS = [
        // Topologia
        'FTTH-TOP-001' => 'Caixa não encontrada.',
        'FTTH-TOP-002' => 'Vão não encontrado.',
        'FTTH-TOP-003' => 'Fibra inexistente para este cabo.',
        'FTTH-TOP-004' => 'Esta fibra já está conectada.',
        'FTTH-TOP-005' => 'Esta porta de splitter já está conectada.',
        'FTTH-TOP-006' => 'A ligação precisa de exatamente duas pontas.',
        'FTTH-TOP-007' => 'As duas pontas da ligação precisam estar na mesma caixa.',
        'FTTH-TOP-008' => 'Não é possível ligar uma ponta nela mesma.',
        'FTTH-TOP-009' => 'Splitter não encontrado.',
        'FTTH-TOP-010' => 'Cliente só pode ser ligado em saída de splitter de atendimento.',
        'FTTH-TOP-011' => 'Esta saída de splitter já tem cliente.',
        'FTTH-TOP-012' => 'Este cliente já está em outra porta.',
        'FTTH-TOP-013' => 'Porta de DIO não encontrada.',
        'FTTH-TOP-014' => 'Esta porta de DIO já está em uso.',
        'FTTH-TOP-015' => 'Ligação não encontrada.',
        'FTTH-TOP-016' => 'A caixa ainda tem ligações; desconecte antes de excluir.',
        'FTTH-TOP-017' => 'Esta combinação de pontas não é permitida.',
        'FTTH-TOP-018' => 'Não é possível ligar a entrada e a saída do mesmo splitter.',
        'FTTH-TOP-019' => 'O padrão de cores do cabo mudou: as fibras trocam de cor no diagrama.',
        'FTTH-TOP-020' => 'Saída de splitter de atendimento recebe cliente, não fusão.',
        'FTTH-TOP-021' => 'Esta ligação não é uma passagem: bitola ou número de fibra diferentes.',

        // Geometria
        'FTTH-GEO-001' => 'Geometria inválida: são necessários ao menos dois pontos.',
        'FTTH-GEO-002' => 'Coordenada fora de faixa válida.',
        'FTTH-GEO-003' => 'O vão não pode começar e terminar na mesma caixa.',
        'FTTH-GEO-004' => 'Vão muito curto para ser válido.',
        'FTTH-GEO-005' => 'Nenhuma caixa próxima o suficiente para ancorar a ponta.',

        // Potencia
        'FTTH-PWR-001' => 'Não há caminho óptico completo até a origem.',
        'FTTH-PWR-002' => 'Comprimento de onda não configurado.',
        'FTTH-PWR-003' => 'Perda de splitter não configurada para esta razão.',

        // Importacao
        'FTTH-KMZ-001' => 'Arquivo inválido ou ilegível.',
        'FTTH-KMZ-002' => 'Este arquivo já foi importado.',
        'FTTH-KMZ-003' => 'Item já importado anteriormente.',
        'FTTH-KMZ-004' => 'O vão só pode ser importado depois das duas caixas das pontas.',
        'FTTH-KMZ-005' => 'Item fora da quarentena ou já decidido.',
        'FTTH-KMZ-006' => 'Capacidade do cabo não reconhecida no KMZ: usado o padrão.',

        // Validador
        'FTTH-VAL-001' => 'Falha de validação da rede.',

        // Sessao
        'FTTH-AUTH-001' => 'Sessão expirada.',
        'FTTH-AUTH-003' => 'Requisição inválida (token de segurança).',

        // Concorrencia
        'FTTH-CONC-001' => 'Este registro foi alterado por outro usuário. Recarregue antes de salvar.',

        // Integracoes externas
        'FTTH-EXT-001' => 'Serviço externo indisponível no momento.',


        // Sincronizacao com as tabelas nativas (espelho de ida)
        'FTTH-SYNC-001' => 'Não foi possível espelhar no cadastro do MK-AUTH; a documentação foi salva.',
        'FTTH-SYNC-002' => 'O nome não cabe no campo do cadastro nativo e foi gravado abreviado.',
        'FTTH-SYNC-003' => 'Esta CTO tem mais de um splitter de atendimento: a porta foi gravada com o nome do splitter.',
        'FTTH-SYNC-004' => 'Esta CTO tem mais de um splitter de atendimento: o número da porta gravado é ambíguo.',
        'FTTH-SYNC-005' => 'A CTO tem mais portas do que o cadastro nativo comporta; foi gravado o máximo.',
        'FTTH-SYNC-006' => 'Os clientes desta CTO estão provisionados em PONs diferentes; a PON não foi espelhada.',
        'FTTH-SYNC-007' => 'O registro no cadastro nativo foi alterado por fora e não foi removido.',
        // Genericos
        'FTTH-SYS-001' => 'Não foi possível concluir a operação.',
        'FTTH-SYS-002' => 'Dados inválidos na requisição.',
    ];

    public static function mensagem(string $code): string
    {
        return self::MENSAGENS[$code] ?? self::MENSAGENS['FTTH-SYS-001'];
    }

    public static function existe(string $code): bool
    {
        return isset(self::MENSAGENS[$code]);
    }
}
