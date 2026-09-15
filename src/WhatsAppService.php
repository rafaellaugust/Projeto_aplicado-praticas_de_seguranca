<?php

/**
 * Serviço de Envio de Notificações WhatsApp via API OpenWA / WPPConnect / Baileys
 */
class WhatsAppService {
    private string $apiUrl;
    private string $session;
    private string $token;
    private string $modeloMensagem;
    private string $modeloAvisoBloqueio;
    private string $modeloConfirmacao;
    private int $delayPix;
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $stmt = $this->db->query("SELECT * FROM configuracoes WHERE id = 1");
        $config = $stmt->fetch();

        $this->apiUrl = rtrim($config['wa_api_url'] ?? 'http://localhost:21465', '/');
        $this->session = $config['wa_session'] ?? 'default';
        $this->token = $config['wa_token'] ?? '';
        // Utiliza o mesmo tempo de "Espera p/ Cliente (s)" para o delay da 2ª mensagem (chave PIX), evitando disparos rápidos e banimento
        $delayDisparo = (int)($config['wa_disparo_delay'] ?? 5);
        $delayPixCfg  = (int)($config['wa_delay_pix'] ?? 5);
        $this->delayPix = max(2, $delayDisparo > 0 ? $delayDisparo : $delayPixCfg);

        // Modelo padrão de cobrança (antes do vencimento)
        $modeloPadrao = "🔔 *FATURA DISPONÍVEL - PROVEDOR DE INTERNET*

Olá, *{NOME_CLIENTE}*!
Sua fatura de internet já está disponível para pagamento.

💰 *Valor:* {VALOR_FATURA}
📅 *Vencimento:* {DATA_VENCIMENTO}

🔗 *Acesse sua fatura online:* {LINK_FATURA}

Obrigado por utilizar nossos serviços!";

        $this->modeloMensagem = !empty($config['wa_modelo_mensagem']) ? $config['wa_modelo_mensagem'] : $modeloPadrao;

        // Modelo padrão de aviso pré-bloqueio (após o vencimento, antes do corte)
        $modeloAvisoPadrao = "⚠️ *AVISO DE VENCIMENTO - ALERTA DE BLOQUEIO*

Olá, *{NOME_CLIENTE}*!
Identificamos que sua fatura de internet no valor de *{VALOR_FATURA}* com vencimento em *{DATA_VENCIMENTO}* continua pendente.

O seu acesso entrará em *bloqueio automático* caso o pagamento não seja identificado.

🔗 *Acesse sua fatura online:* {LINK_FATURA}

Para evitar o corte do sinal, efetue o pagamento pelo PIX Copia e Cola abaixo:";

        $this->modeloAvisoBloqueio = !empty($config['wa_modelo_aviso']) ? $config['wa_modelo_aviso'] : $modeloAvisoPadrao;

        // Modelo padrão de confirmação de pagamento
        $modeloConfirmacaoPadrao = "✅ *PAGAMENTO CONFIRMADO!*

Olá, *{NOME_CLIENTE}*!
Confirmamos o recebimento do seu pagamento no valor de *{VALOR_FATURA}* referente à fatura #{FATURA_ID}.

Seu acesso à internet continua ativo. Agradecemos a preferência!";

        $this->modeloConfirmacao = !empty($config['wa_modelo_confirmacao']) ? $config['wa_modelo_confirmacao'] : $modeloConfirmacaoPadrao;
    }

    /**
     * Limpa e padroniza o telefone para o formato nacional (DDD + Número, sem 55, ex: 82999334425)
     */
    public static function sanitizePhone($phone): string {
        $clean = preg_replace('/[^0-9]/', '', (string)$phone);
        // Se começar com 55 e tiver 12 ou 13 dígitos (DDI 55 + DDD + 8 ou 9 dígitos), remove o prefixo 55
        if (strlen($clean) >= 12 && substr($clean, 0, 2) === '55') {
            $clean = substr($clean, 2);
        }
        return $clean;
    }

    /**
     * Formatar telefone para o padrão internacional do WhatsApp (ex: 5582999334425)
     * Utilizado exclusivamente no envio da API e links wa.me
     */
    public static function formatPhone($phone): string {
        $clean = self::sanitizePhone($phone);
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
     * Garante que a fatura tenha um PIX Copia e Cola válido (não expirado).
     * Se não tiver ou estiver expirado, gera um novo via PaymentGateway e salva na fatura.
     * Retorna a string do PIX (ou '' em caso de falha na geração).
     *
     * Centralizado aqui para que TODO chamador de sendInvoiceNotification()
     * (cron, admin/faturas.php, ou qualquer outro ponto futuro) sempre tenha
     * a chave gerada automaticamente, sem depender de cada script lembrar de
     * chamar isso manualmente.
     */
    public function garantirPixFatura(int $faturaId, string $pixAtual = '', ?string $expirationDate = null): string {
        if (!empty($pixAtual) && !empty($expirationDate) && strtotime($expirationDate) > time()) {
            return $pixAtual;
        }

        if ($faturaId <= 0) {
            return $pixAtual; // Sem ID de fatura não há como gerar/salvar novo PIX
        }

        try {
            $stmtFull = $this->db->prepare("
                SELECT f.*, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario,
                       p.nome as plano_nome, p.valor as plano_valor
                FROM faturas f
                JOIN clientes c ON f.cliente_id = c.id
                LEFT JOIN planos p ON f.plano_id = p.id
                WHERE f.id = ?
            ");
            $stmtFull->execute([$faturaId]);
            $faturaFull = $stmtFull->fetch();

            if (!$faturaFull) {
                Database::log('whatsapp', "Fatura #{$faturaId} não encontrada ao tentar gerar PIX automático.");
                return $pixAtual;
            }

            // Se a fatura já estiver paga, não faz sentido gerar novo PIX
            if (($faturaFull['status'] ?? '') === 'pago') {
                return $pixAtual;
            }

            $email   = !empty($faturaFull['email']) ? $faturaFull['email'] : "cliente{$faturaFull['cliente_id']}@spaconett.com";
            $descPix = "Mensalidade - " . ($faturaFull['plano_nome'] ?? 'Internet') . " - " . $faturaFull['nome'];

            $gateway = new PaymentGateway();
            $pix     = $gateway->generatePixCharge(
                (int)$faturaFull['id'],
                (float)$faturaFull['valor'],
                $descPix,
                $email,
                $faturaFull['cpf_cnpj'] ?? ''
            );

            if (empty($pix['success'])) {
                $motivo = $pix['message'] ?? 'Falha desconhecida no gateway de pagamento.';
                Database::log('whatsapp', "Falha ao gerar PIX automático para fatura #{$faturaId}", $pix);
                return $pixAtual;
            }

            $this->db->prepare("
                UPDATE faturas SET
                    external_reference = ?,
                    pix_txid           = ?,
                    pix_copia_cola     = ?,
                    qr_code            = ?,
                    pix_qr_code_base64 = ?,
                    gateway_id         = ?,
                    mp_payment_id      = ?,
                    gateway_status     = 'pending',
                    expiration_date    = ?
                WHERE id = ?
            ")->execute([
                $pix['external_reference'],
                $pix['txid'],
                $pix['copia_cola'],
                $pix['qr_code'],
                $pix['qr_code_base64'] ?? '',
                $pix['gateway_id'],
                $pix['mp_payment_id'],
                date('Y-m-d H:i:s', strtotime($pix['expiration_date'])),
                $faturaId
            ]);

            return $pix['copia_cola'];

        } catch (Exception $e) {
            Database::log('whatsapp', "Erro ao gerar PIX automático para fatura #{$faturaId}: " . $e->getMessage());
            return $pixAtual;
        }
    }

    /**
     * Enviar notificação de Fatura em DUAS mensagens separadas:
     *   1) Corpo da fatura (sem o PIX)
     *   2) Após um delay configurável (wa_delay_pix), somente a chave PIX Copia e Cola (texto puro)
     *
     * A chave PIX é gerada automaticamente aqui dentro (se ainda não existir ou
     * estiver expirada), desde que $faturaId seja informado — assim funciona
     * de forma consistente em qualquer tela que dispare a cobrança.
     *
     * Retorna um array detalhado para permitir registro correto de falha parcial:
     *   ['corpo' => bool, 'pix' => bool|null, 'erro' => string|null, 'pix_copia_cola' => string]
     *   - 'pix' fica null quando não havia PIX para enviar (não é considerado falha).
     */
    public function sendInvoiceNotification($phone, $nomeCliente, $valor, $dataVencimento, $pixCopiaCola, $faturaUrl, $pppoeUsuario = '', $planoNome = '', $empresaNome = '', int $faturaId = 0, ?string $expirationDate = null): array {

        // Garante que exista um PIX válido, gerando automaticamente se necessário
        $pixCopiaCola = $this->garantirPixFatura($faturaId, (string)$pixCopiaCola, $expirationDate);

        // Buscar nome da empresa das configurações
        if (empty($empresaNome)) {
            $stmt = $this->db->query("SELECT empresa_nome FROM configuracoes WHERE id = 1");
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
            'pix_copia_cola' => $pixCopiaCola,
        ];

        if (!$sucessoCorpo) {
            $resultado['erro'] = 'Falha ao enviar o corpo da fatura.';
            return $resultado;
        }

        // Se não há PIX (nem existia, nem foi possível gerar), encerra aqui
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
     * Enviar notificação de Aviso de Vencimento / Pré-Bloqueio em DUAS mensagens separadas:
     *   1) Corpo do aviso de vencimento / bloqueio iminente (sem o PIX)
     *   2) Chave PIX Copia e Cola (texto puro)
     */
    public function sendDueWarningNotification($phone, $nomeCliente, $valor, $dataVencimento, $pixCopiaCola, $faturaUrl, $pppoeUsuario = '', $planoNome = '', $empresaNome = '', int $faturaId = 0, ?string $expirationDate = null, int $diasAtraso = 0): array {

        // Garante que exista um PIX válido (não expirado)
        $pixCopiaCola = $this->garantirPixFatura($faturaId, (string)$pixCopiaCola, $expirationDate);

        if (empty($empresaNome)) {
            $stmt = $this->db->query("SELECT empresa_nome FROM configuracoes WHERE id = 1");
            $config = $stmt->fetch();
            $empresaNome = $config['empresa_nome'] ?? 'Provedor ISP';
        }

        $valorFormatado = 'R$ ' . number_format($valor, 2, ',', '.');
        $dataFormatada = date('d/m/Y', strtotime($dataVencimento));
        $diasAtrasoTexto = $diasAtraso > 0 ? "{$diasAtraso} dia(s)" : "hoje";

        $substituicoes = [
            '{NOME_CLIENTE}'    => $nomeCliente,
            '{PPPOE_USUARIO}'   => $pppoeUsuario,
            '{VALOR_FATURA}'    => $valorFormatado,
            '{DATA_VENCIMENTO}' => $dataFormatada,
            '{LINK_FATURA}'     => $faturaUrl,
            '{PIX_COPIA_COLA}'  => '',
            '{PLANO_NOME}'      => $planoNome,
            '{EMPRESA_NOME}'    => $empresaNome,
            '{DIAS_ATRASO}'     => $diasAtrasoTexto
        ];

        $mensagemCorpo = str_replace(
            array_keys($substituicoes),
            array_values($substituicoes),
            $this->modeloAvisoBloqueio
        );

        $mensagemCorpo = preg_replace("/\n{3,}/", "\n\n", $mensagemCorpo);
        $mensagemCorpo = rtrim($mensagemCorpo);

        // ── Mensagem 1: corpo do aviso pré-bloqueio ──────────────────────────
        $sucessoCorpo = $this->sendTextMessage($phone, $mensagemCorpo);

        $resultado = [
            'corpo' => $sucessoCorpo,
            'pix'   => null,
            'erro'  => null,
            'pix_copia_cola' => $pixCopiaCola,
        ];

        if (!$sucessoCorpo) {
            $resultado['erro'] = 'Falha ao enviar o corpo do aviso de vencimento.';
            return $resultado;
        }

        if (empty($pixCopiaCola)) {
            return $resultado;
        }

        // ── Delay seguro antes do PIX ─────────────────────────────────────────
        sleep($this->delayPix);

        // ── Mensagem 2: somente a chave PIX Copia e Cola ──────────────────────
        $sucessoPix = $this->sendTextMessage($phone, $pixCopiaCola);
        $resultado['pix'] = $sucessoPix;

        if (!$sucessoPix) {
            $resultado['erro'] = 'Aviso enviado, falha ao enviar PIX';
        }

        return $resultado;
    }

    /**
     * Enviar comprovante/confirmação de pagamento
     */
    public function sendPaymentConfirmation($phone, $nomeCliente, $valor, $faturaId, $planoNome = '', $empresaNome = ''): bool {
        if (empty($this->apiUrl)) return false;

        if (empty($empresaNome)) {
            $stmt = $this->db->query("SELECT empresa_nome FROM configuracoes WHERE id = 1");
            $config = $stmt->fetch();
            $empresaNome = $config['empresa_nome'] ?? 'Provedor ISP';
        }

        $valorFormatado = 'R$ ' . number_format((float)$valor, 2, ',', '.');
        $dataHoje = date('d/m/Y H:i');

        $substituicoes = [
            '{NOME_CLIENTE}'   => $nomeCliente,
            '{VALOR_FATURA}'   => $valorFormatado,
            '{FATURA_ID}'      => $faturaId,
            '{DATA_PAGAMENTO}' => $dataHoje,
            '{EMPRESA_NOME}'   => $empresaNome,
            '{PLANO_NOME}'     => $planoNome
        ];

        $mensagem = str_replace(
            array_keys($substituicoes),
            array_values($substituicoes),
            $this->modeloConfirmacao
        );

        $resultado = $this->sendTextMessage($phone, $mensagem);

        // Registrar no histórico de disparos do WhatsApp
        try {
            $stmtLog = $this->db->prepare("
                INSERT INTO whatsapp_disparos (fatura_id, cliente_id, nome_cliente, whatsapp, tipo, status, erro_msg)
                SELECT ?, c.id, ?, ?, 'confirmacao', ?, ?
                FROM faturas f
                JOIN clientes c ON f.cliente_id = c.id
                WHERE f.id = ?
                LIMIT 1
            ");
            $stLog = $resultado ? 'enviado' : 'falhou';
            $errLog = $resultado ? null : 'Falha ao enviar confirmação de pagamento via WhatsApp';
            $stmtLog->execute([$faturaId, $nomeCliente, self::sanitizePhone($phone), $stLog, $errLog, $faturaId]);
        } catch (Exception $e) {}

        return $resultado;
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
