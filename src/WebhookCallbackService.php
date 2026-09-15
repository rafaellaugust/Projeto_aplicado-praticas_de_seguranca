<?php
/**
 * src/WebhookCallbackService.php
 * 
 * Serviço para enviar um Webhook/Callback POST para uma URL externa customizada
 * (Ex: Macrodroid, webhook de terceiros, painel externo) quando um pagamento é confirmado.
 */

class WebhookCallbackService {
    private ?string $url;
    private bool $ativo;

    public function __construct() {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT webhook_callback_url, webhook_callback_ativo FROM configuracoes WHERE id = 1");
            $config = $stmt->fetch();
            
            $this->url = $config['webhook_callback_url'] ?? null;
            $this->ativo = !empty($config['webhook_callback_ativo']);
        } catch (Exception $e) {
            $this->url = null;
            $this->ativo = false;
        }
    }

    /**
     * Envia o POST para a URL configurada
     */
    public function sendPaymentNotification(string $clienteNome, float $valor, $faturaId, string $forma = 'PIX'): bool {
        if (!$this->ativo || empty($this->url)) {
            return false;
        }

        $msgTexto = "Pagamento de R$ " . number_format($valor, 2, ',', '.') . " aprovado para o cliente {$clienteNome} (Fatura #{$faturaId})";

        $payload = [
            'event'           => 'payment.approved',
            'fatura_id'       => $faturaId,
            'cliente_nome'    => $clienteNome,
            'valor'           => $valor,
            'forma_pagamento' => $forma,
            'data_pagamento'  => date('Y-m-d H:i:s'),
            'message'         => $msgTexto,
            'text'            => $msgTexto
        ];

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: MikroPay-Webhook/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            Database::log('webhook_callback', "Notificação POST enviada com sucesso para: {$this->url}");
            return true;
        } else {
            Database::log('webhook_callback', "Erro ao enviar POST para: {$this->url}", [
                'http_code' => $httpCode,
                'response'  => substr($response, 0, 500)
            ]);
            return false;
        }
    }
}
