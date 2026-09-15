<?php

/**
 * Serviço de Envio de Notificações WhatsApp via API OpenWA / WPPConnect
 */
class WhatsAppService {
    private string $apiUrl;
    private string $session;
    private string $token;
    private string $modeloMensagem;
    private int $delayPix;

    public function __construct() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT wa_api_url, wa_session, wa_token, wa_modelo_mensagem, wa_delay_pix FROM configuracoes WHERE id = 1");
        $config = $stmt->fetch();

        $this->apiUrl = rtrim($config['wa_api_url'] ?? 'http://localhost:21465', '/');
        $this->session = $config['wa_session'] ?? 'default';
        $this->token = $config['wa_token'] ?? '';
        $this->delayPix = (int)($config['wa_delay_pix'] ?? 2);
        if ($this->delayPix < 1) $this->delayPix = 1;

        // Modelo padrão
        $modeloPadrao = "🔔 *FATURA DISPONÍVEL - PROVEDOR DE INTERNET*

Olá, *{NOME_CLIENTE}*!
Sua fatura de internet já está disponível para pagamento.

💰 *Valor:* {VALOR_FATURA}
📅 *Vencimento:* {DATA_VENCIMENTO}

🔗 *Acesse sua fatura online:* {LINK_FATURA}

Obrigado por utilizar nossos serviços!";

        $this->modeloMensagem = $config['wa_modelo_mensagem'] ?? $modeloPadrao;
    }

    /**
     * Formatar telefone para o padrão internacional do WhatsApp (ex: 5511999999999)
     */
    public static function formatPhone($phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) === 10 || strlen($clean) === 11) {
            $clean = '55' . $clean;
        }
        return $clean;
    }

    /**
     * Enviar mensagem de texto simples
     */
    public function sendTextMessage($phone, $message): bool {
        if (empty($this->apiUrl)) return false;

        $formattedPhone = self::formatPhone($phone);
        $endpoint = $this->apiUrl . '/api/' . $this->session . '/send-message';

        $payload = [
            'phone' => $formattedPhone,
            'message' => $message,
            'isGroup' => false
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            Database::log('whatsapp', "Mensagem enviada com sucesso para {$formattedPhone}");
            return true;
        } else {
            Database::log('whatsapp', "Erro ao enviar WhatsApp para {$formattedPhone}", ['code' => $httpCode, 'response' => $response]);
            return false;
        }
    }

    /**
     * Enviar notificação de Fatura em DUAS mensagens separadas:
     *   1) Corpo da fatura (sem o PIX)
     *   2) Após um delay configurável (wa_delay_pix), somente a chave PIX Copia e Cola (texto puro)
     *
     * Retorna um array detalhado para permitir registro correto de falha parcial:
     *   ['corpo' => bool, 'pix' => bool|null, 'erro' => string|null]
     *   - 'pix' fica null quando não havia PIX para enviar (não é considerado falha).
     */
    public function sendInvoiceNotification($phone, $nomeCliente, $valor, $dataVencimento, $pixCopiaCola, $faturaUrl, $pppoeUsuario = '', $planoNome = '', $empresaNome = ''): array {

        // Buscar nome da empresa das configurações
        if (empty($empresaNome)) {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT empresa_nome FROM configuracoes WHERE id = 1");
            $config = $stmt->fetch();
            $empresaNome = $config['empresa_nome'] ?? 'Provedor ISP';
        }

        // Preparar variáveis para substituição
        $valorFormatado = 'R$ ' . number_format($valor, 2, ',', '.');
        $dataFormatada = date('d/m/Y', strtotime($dataVencimento));

        $substituicoes = [
            '{NOME_CLIENTE}' => $nomeCliente,
            '{PPPOE_USUARIO}' => $pppoeUsuario,
            '{VALOR_FATURA}' => $valorFormatado,
            '{DATA_VENCIMENTO}' => $dataFormatada,
            '{LINK_FATURA}' => $faturaUrl,
            '{PIX_COPIA_COLA}' => '', // Removido do corpo: PIX vai em mensagem separada
            '{PLANO_NOME}' => $planoNome,
            '{EMPRESA_NOME}' => $empresaNome
        ];

        // Substituir variáveis no modelo (corpo SEM o PIX)
        $mensagemCorpo = str_replace(
            array_keys($substituicoes),
            array_values($substituicoes),
            $this->modeloMensagem
        );

        // Remove linhas em branco duplicadas deixadas pela remoção do {PIX_COPIA_COLA}
        $mensagemCorpo = preg_replace("/\n{3,}/", "\n\n", $mensagemCorpo);
        $mensagemCorpo = rtrim($mensagemCorpo);

        // ── Mensagem 1: corpo da fatura (sem PIX) ─────────────────────────────
        $sucessoCorpo = $this->sendTextMessage($phone, $mensagemCorpo);

        $resultado = [
            'corpo' => $sucessoCorpo,
            'pix'   => null,
            'erro'  => null,
        ];

        if (!$sucessoCorpo) {
            $resultado['erro'] = 'Falha ao enviar o corpo da fatura.';
            return $resultado;
        }

        // Se não há PIX, encerra aqui (não envia segunda mensagem vazia)
        if (empty($pixCopiaCola)) {
            return $resultado;
        }

        // ── Delay configurável antes da segunda mensagem (padrão: wa_delay_pix) ─
        sleep($this->delayPix);

        // ── Mensagem 2: somente a chave PIX Copia e Cola, texto puro ──────────
        $sucessoPix = $this->sendTextMessage($phone, $pixCopiaCola);
        $resultado['pix'] = $sucessoPix;

        if (!$sucessoPix) {
            $resultado['erro'] = 'Corpo enviado, falha ao enviar PIX';
        }

        return $resultado;
    }

    /**
     * Enviar comprovante/confirmação de pagamento
     */
    public function sendPaymentConfirmation($phone, $nomeCliente, $valor, $faturaId): bool {
        $mensagem = "✅ *PAGAMENTO CONFIRMADO!*\n\n";
        $mensagem .= "Olá, *{$nomeCliente}*!\n";
        $mensagem .= "Confirmamos o recebimento do seu pagamento no valor de *" . formatMoeda($valor) . "* referente à fatura #{$faturaId}.\n\n";
        $mensagem .= "Seu acesso à internet continua ativo. Agradecemos a preferência!";

        return $this->sendTextMessage($phone, $mensagem);
    }

    /**
     * Verificar Status da API OpenWA
     */
    public function checkStatus(): bool {
        if (empty($this->apiUrl)) return false;

        $endpoint = $this->apiUrl . '/api/' . $this->session . '/check-connection-session';
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300);
    }
}
