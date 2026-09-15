<?php
/**
 * src/PaymentGateway.php - CORREÇÃO FINAL Invalid user identification number
 * - Remove identification se CPF inválido (era o que causava HTTP 400)
 * - Projeto antigo não enviava CPF, só first_name + email
 */

class PaymentGateway {
    private string $accessToken;
    private string $notificationUrl;
    private string $staticPixKey;
    private string $beneficiaryName;
    private string $beneficiaryCity;
    private string $provider;

    public function __construct() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT gateway_provider, mp_access_token, pix_chave_estatica, pix_nome_beneficiario, pix_cidade_beneficiario FROM configuracoes WHERE id = 1");
        $cfg = $stmt->fetch();
        $this->provider = $cfg['gateway_provider'] ?? 'mercadopago';
        $this->accessToken = trim($cfg['mp_access_token'] ?? '');
        $baseUrl = defined('BASE_URL') ? BASE_URL : ((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $this->notificationUrl = rtrim($baseUrl, '/') . '/webhook/mercadopago.php';
        $this->staticPixKey = trim($cfg['pix_chave_estatica'] ?? '');
        $this->beneficiaryName = trim($cfg['pix_nome_beneficiario'] ?? 'SPACONETT PROVEDOR');
        $this->beneficiaryCity = trim($cfg['pix_cidade_beneficiario'] ?? 'SAO PAULO');
    }

    public function generatePixCharge(int $faturaId, float $valor, string $descricao, string $email, string $cpfCnpj): array {
        if ($this->provider === 'mercadopago' && !empty($this->accessToken)) {
            $mp = $this->createMercadoPagoPix($faturaId, $valor, $descricao, $email, $cpfCnpj);
            if ($mp['success']) return $mp;
            return [
                'success' => false,
                'message' => "MP falhou: " . ($mp['message'] ?? 'erro') . " | HTTP: " . ($mp['http_code'] ?? '?'),
                'mp_error' => $mp
            ];
        }
        if (empty($this->staticPixKey)) {
            return ['success'=>false, 'message'=>'Sem chave PIX estática e sem token MP.'];
        }
        return $this->createStaticPix($faturaId, $valor);
    }

    private function createMercadoPagoPix(int $faturaId, float $valor, string $descricao, string $email, string $cpfCnpj): array {
        $externalReference = 'FATURA_'.$faturaId.'_'.substr(bin2hex(random_bytes(6)),0,10).'_'.time();
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : "cliente{$faturaId}@spaconett.com";

        // NÃO envia CPF/CNPJ se for inválido - causa HTTP 400 Invalid user identification number
        // Projeto antigo só enviava first_name + email, vamos fazer igual
        $payer = [
            "first_name" => "Cliente",
            "email" => $email
        ];

        // Só adiciona identification se CPF/CNPJ for realmente válido (com checksum)
        $cleanDoc = preg_replace('/[^0-9]/','',$cpfCnpj);
        if (!empty($cleanDoc) && $this->isValidCpfCnpj($cleanDoc)) {
            $payer['identification'] = [
                'type' => strlen($cleanDoc) > 11 ? 'CNPJ' : 'CPF',
                'number' => $cleanDoc
            ];
        }
        // Se CPF for inválido tipo 99967943230, NÃO envia identification - evita erro 400

        $payload = [
            "notification_url" => $this->notificationUrl,
            "description" => substr($descricao,0,100),
            "external_reference" => $externalReference,
            "transaction_amount" => round($valor,2),
            "payment_method_id" => "pix",
            "payer" => $payer
        ];

        $ch = curl_init("https://api.mercadopago.com/v1/payments");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST=>'POST',
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->accessToken, 'Content-Type: application/json', 'X-Idempotency-Key: '.$externalReference],
            CURLOPT_TIMEOUT=>15
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) return ['success'=>false, 'message'=>"CURL: $curlErr", 'http_code'=>0];

        $data = json_decode($response, true);

        if ($httpCode>=200 && $httpCode<300 && isset($data['point_of_interaction']['transaction_data']['qr_code'])) {
            $trans = $data['point_of_interaction']['transaction_data'];
            return [
                'success'=>true,
                'gateway_id'=>(string)$data['id'],
                'mp_payment_id'=>(string)$data['id'],
                'txid'=>$externalReference,
                'external_reference'=>$externalReference,
                'copia_cola'=>$trans['qr_code'],
                'qr_code'=>$trans['qr_code'],
                'qr_code_base64'=>isset($trans['qr_code_base64']) ? 'data:image/png;base64,'.$trans['qr_code_base64'] : '',
                'ticket_url'=>$trans['ticket_url'] ?? '',
                'expiration_date'=>$data['date_of_expiration'] ?? date('c', strtotime('+30 minutes')),
                'value'=>$valor
            ];
        }
        $msg = $data['message'] ?? $data['error'] ?? substr($response,0,500);
        if (isset($data['cause'][0]['description'])) $msg .= " - ".$data['cause'][0]['description'];

        return ['success'=>false, 'message'=>$msg, 'http_code'=>$httpCode, 'response'=>substr($response,0,1000), 'payload'=>json_encode($payload)];
    }

    private function createStaticPix(int $faturaId, float $valor): array {
        $chave = $this->staticPixKey;
        $nome = $this->sanitize($this->beneficiaryName);
        $cidade = $this->sanitize($this->beneficiaryCity);
        $valorStr = number_format($valor,2,'.','');
        $pixKeyLen = sprintf('%02d', strlen($chave));
        $merchant = "0014BR.GOV.BCB.PIX01{$pixKeyLen}{$chave}";
        $payload = "00020101021226".sprintf('%02d', strlen($merchant)).$merchant."52040000530398654".sprintf('%02d', strlen($valorStr)).$valorStr."5802BR59".sprintf('%02d', strlen($nome)).$nome."60".sprintf('%02d', strlen($cidade)).$cidade."62070503***6304";
        $crc = $this->crc16($payload);
        $pixCode = $payload . sprintf('%04X', $crc);
        return [
            'success'=>true,
            'gateway_id'=>'STATIC_'.$faturaId,
            'mp_payment_id'=>'STATIC_'.$faturaId,
            'txid'=>'FATURA_'.$faturaId.'_STATIC_'.time(),
            'external_reference'=>'FATURA_'.$faturaId.'_STATIC_'.time(),
            'copia_cola'=>$pixCode,
            'qr_code'=>$pixCode,
            'qr_code_base64'=>'',
            'ticket_url'=>'',
            'expiration_date'=>date('c', strtotime('+1 day')),
            'value'=>$valor,
            'is_fallback'=>true
        ];
    }

    private function sanitize($str) {
        $str = mb_strtoupper(trim($str), 'UTF-8');
        $str = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$str);
        $str = preg_replace('/[^A-Z0-9 ]/','',$str);
        return substr($str,0,25) ?: 'SPACONETT';
    }

    private function crc16($payload) {
        $pol=0x1021; $crc=0xFFFF;
        for ($i=0;$i<strlen($payload);$i++) {
            $crc ^= ord($payload[$i]) << 8;
            for ($j=0;$j<8;$j++) {
                if ($crc & 0x8000) $crc = (($crc << 1) ^ $pol) & 0xFFFF;
                else $crc = ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }

    // Valida CPF/CNPJ com checksum - evita enviar CPF falso tipo 99967943230
    private function isValidCpfCnpj(string $doc): bool {
        $doc = preg_replace('/[^0-9]/','',$doc);
        if (strlen($doc) === 11) return $this->isValidCpf($doc);
        if (strlen($doc) === 14) return $this->isValidCnpj($doc);
        return false;
    }

    private function isValidCpf(string $cpf): bool {
        if (strlen($cpf) != 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($t=9; $t<11; $t++) {
            $d=0;
            for ($c=0; $c<$t; $c++) $d += $cpf[$c] * (($t+1)-$c);
            $d = ((10*$d)%11)%10;
            if ($cpf[$c] != $d) return false;
        }
        return true;
    }

    private function isValidCnpj(string $cnpj): bool {
        if (strlen($cnpj)!=14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
        $b=[6,5,4,3,2,9,8,7,6,5,4,3,2];
        for ($i=0,$n=0;$i<12;$n+=$cnpj[$i++]*$b[++$i]){}
        if ($cnpj[12] != ((($n%=11)<2)?0:11-$n)) return false;
        for ($i=0,$n=0;$i<=12;$n+=$cnpj[$i++]*$b[$i++]){}
        if ($cnpj[13] != ((($n%=11)<2)?0:11-$n)) return false;
        return true;
    }

    public function testToken(): array {
        if (empty($this->accessToken)) return ['success'=>false, 'message'=>'Token vazio'];
        $ch = curl_init('https://api.mercadopago.com/v1/payment_methods');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->accessToken], CURLOPT_TIMEOUT=>8]);
        $res=curl_exec($ch); $code=curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code>=200 && $code<300) return ['success'=>true, 'message'=>"Token OK HTTP $code"];
        return ['success'=>false, 'message'=>"Token falhou HTTP $code: ".substr($res,0,400)];
    }
}
