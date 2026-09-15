<?php

/**
 * Serviço de Integração com Telegram Bot API
 */
class TelegramService {
    private string $botToken;
    private string $chatId;
    private bool $ativo;

    public function __construct() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT telegram_bot_token, telegram_chat_id, telegram_ativo FROM configuracoes WHERE id = 1");
        $config = $stmt->fetch();

        $this->botToken = $config['telegram_bot_token'] ?? '';
        $this->chatId = $config['telegram_chat_id'] ?? '';
        $this->ativo = !empty($config['telegram_ativo']);
    }

    /**
     * Enviar mensagem para o Telegram
     */
    public function sendMessage($message): bool {
        if (!$this->ativo || empty($this->botToken) || empty($this->chatId)) {
            return false;
        }

        $endpoint = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $payload = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            Database::log('telegram', 'Notificação enviada ao Telegram com sucesso');
            return true;
        } else {
            Database::log('telegram', 'Erro ao enviar Telegram', ['code' => $httpCode, 'response' => $response]);
            return false;
        }
    }

    /**
     * Notificar pagamento no Telegram
     */
    public function notifyPaymentReceived($clienteNome, $valor, $faturaId, $forma = 'PIX') {
        $msg = "<b>💰 NOVO PAGAMENTO RECEBIDO!</b>\n\n";
        $msg .= "👤 <b>Cliente:</b> {$clienteNome}\n";
        $msg .= "💵 <b>Valor:</b> " . formatMoeda($valor) . "\n";
        $msg .= "📄 <b>Fatura:</b> #{$faturaId}\n";
        $msg .= "💳 <b>Forma:</b> {$forma}\n";
        $msg .= "⏰ <b>Data:</b> " . date('d/m/Y H:i:s') . "\n";

        return $this->sendMessage($msg);
    }

    /**
     * Relatar resultado de disparos do WhatsApp (lote diário e falhas)
     */
    public function notifyDisparoReport(int $enviados, int $falhos, array $resultados = []): bool {
        $total = $enviados + $falhos;
        $icone = ($falhos === 0) ? "✅" : "⚠️";

        $msg = "<b>{$icone} RELATÓRIO DE DISPAROS WHATSAPP</b>\n\n";
        $msg .= "📊 <b>Total Processado:</b> {$total} faturas\n";
        $msg .= "✅ <b>Enviados com Sucesso:</b> {$enviados}\n";

        if ($falhos > 0) {
            $msg .= "❌ <b>Falhas Detectadas:</b> {$falhos}\n\n";
            $msg .= "<b>⚠️ Detalhes das Falhas:</b>\n";
            $count = 0;
            foreach ($resultados as $r) {
                if (($r['status'] ?? '') === 'falhou') {
                    $count++;
                    $cli = htmlspecialchars($r['cliente'] ?? 'Cliente', ENT_QUOTES, 'UTF-8');
                    $fatId = $r['fatura_id'] ?? '?';
                    $err = htmlspecialchars($r['erro'] ?? 'Erro desconhecido', ENT_QUOTES, 'UTF-8');
                    $tipo = ($r['tipo'] === 'aviso_bloqueio') ? 'Aviso Bloqueio' : 'Cobrança';
                    $msg .= "• <b>{$cli}</b> (Fatura #{$fatId} - {$tipo}): <i>{$err}</i>\n";
                    if ($count >= 8) {
                        $restantes = $falhos - $count;
                        if ($restantes > 0) $msg .= "• <i>... e mais {$restantes} falha(s).</i>\n";
                        break;
                    }
                }
            }
        } else {
            $msg .= "✨ <i>Todos os disparos foram entregues com êxito!</i>\n";
        }

        $msg .= "\n⏰ <b>Data/Hora:</b> " . date('d/m/Y H:i:s') . "\n";
        return $this->sendMessage($msg);
    }

    /**
     * Notificar falha de comunicação ou sincronização com o MikroTik
     */
    public function notifyMikrotikError(string $usuario, string $profileAlvo, string $erro): bool {
        $msg = "<b>⚠️ ALERTA MIKROTIK: FALHA DE SINCRONIZAÇÃO!</b>\n\n";
        $msg .= "👤 <b>PPPoE Usuário:</b> {$usuario}\n";
        $msg .= "🎯 <b>Profile Alvo:</b> {$profileAlvo}\n";
        $msg .= "❌ <b>Erro RouterOS:</b> <code>" . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . "</code>\n";
        $msg .= "📌 <i>Aviso: Verifique a conexão com o roteador MikroTik.</i>\n";
        $msg .= "⏰ <b>Data:</b> " . date('d/m/Y H:i:s') . "\n";

        return $this->sendMessage($msg);
    }

    /**
     * Notificar alerta geral do sistema (erros de gateway, banco ou serviço)
     */
    public function notifySystemAlert(string $titulo, string $mensagem): bool {
        $msg = "<b>⚠️ ALERTA DO SISTEMA: " . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . "</b>\n\n";
        $msg .= $mensagem . "\n\n";
        $msg .= "⏰ <b>Data:</b> " . date('d/m/Y H:i:s') . "\n";

        return $this->sendMessage($msg);
    }
}
