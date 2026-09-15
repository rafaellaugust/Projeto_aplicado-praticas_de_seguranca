const express = require('express');
const baileys = require('@whiskeysockets/baileys');
const makeWASocket = baileys.default || baileys;
const { useMultiFileAuthState, DisconnectReason, Browsers, fetchLatestBaileysVersion } = baileys;
const QRCode = require('qrcode');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(express.json());

// Carregar configurações locais
let config = {
    port: 21465,
    session: 'default',
    token: ''
};

const configPath = path.join(__dirname, 'config.json');
if (fs.existsSync(configPath)) {
    try {
        const fileConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        config = { ...config, ...fileConfig };
        console.log('[API] Configurações carregadas do config.json');
    } catch (e) {
        console.error('[API] Erro ao analisar config.json:', e.message);
    }
}

const cron = require('node-cron');
const http = require('http');
const https = require('https');

const PORT = process.env.PORT || config.port;
const SESSION_NAME = process.env.WA_SESSION || config.session;
const PHP_BASE_URL = process.env.PHP_BASE_URL || config.php_base_url || 'http://aplicacao.spaconett.com';
const SECRET_TOKEN = process.env.WA_TOKEN || config.token || '';

function getPhpBaseUrl() {
    try {
        if (fs.existsSync(configPath)) {
            const currentCfg = JSON.parse(fs.readFileSync(configPath, 'utf8'));
            if (currentCfg.php_base_url) return currentCfg.php_base_url.replace(/\/$/, '');
        }
    } catch (e) {}
    return (process.env.PHP_BASE_URL || config.php_base_url || 'http://aplicacao.spaconett.com').replace(/\/$/, '');
}

let sock = null;
let qrCodeBase64 = null;
let sessionStatus = 'DISCONNECTED';
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 10;
let isReconnecting = false;
let heartbeatInterval = null;
let lastMessageTime = Date.now();
let isCronRunning = false;

// Configurações de presença e status
let presenceConfig = {
    mode: config.presence_mode || 'phone', // 'phone' (meio principal) | 'online' | 'offline'
    simulateTyping: config.simulate_typing !== undefined ? Boolean(config.simulate_typing) : true,
    typingDelay: config.typing_delay ? parseInt(config.typing_delay) : 1500
};

let presenceInterval = null;

function setupPresenceKeepAlive() {
    if (presenceInterval) {
        clearInterval(presenceInterval);
        presenceInterval = null;
    }
    if (presenceConfig.mode === 'online') {
        presenceInterval = setInterval(async () => {
            if (sock && sessionStatus === 'CONNECTED') {
                try {
                    await sock.sendPresenceUpdate('available');
                } catch (e) {}
            }
        }, 20000);
    }
}

async function applyGlobalPresence() {
    setupPresenceKeepAlive();
    if (!sock || sessionStatus !== 'CONNECTED') return;
    try {
        if (presenceConfig.mode === 'online') {
            await sock.sendPresenceUpdate('available');
            console.log('[Presence] Status global: ONLINE');
        } else if (presenceConfig.mode === 'offline') {
            await sock.sendPresenceUpdate('unavailable');
            console.log('[Presence] Status global: OFFLINE (sem status)');
        } else {
            // 'phone': Celular como meio principal.
            // Envia unavailable uma vez para desvincular o estado online do servidor
            // e deixar o celular móvel governar a presença naturalmente.
            await sock.sendPresenceUpdate('unavailable');
            console.log('[Presence] Status governado pelo celular (meio principal)');
        }
    } catch (err) {
        console.warn('[Presence] Erro ao aplicar presença:', err.message);
    }
}

function updatePresenceConfig(newSettings) {
    if (newSettings && newSettings.mode !== undefined) {
        presenceConfig.mode = ['phone', 'online', 'offline'].includes(newSettings.mode) ? newSettings.mode : 'phone';
    }
    if (newSettings && newSettings.simulateTyping !== undefined) {
        presenceConfig.simulateTyping = Boolean(newSettings.simulateTyping);
    }
    if (newSettings && newSettings.typingDelay !== undefined) {
        presenceConfig.typingDelay = Math.max(500, Math.min(10000, parseInt(newSettings.typingDelay) || 1500));
    }

    try {
        let currentData = {};
        if (fs.existsSync(configPath)) {
            currentData = JSON.parse(fs.readFileSync(configPath, 'utf8'));
        }
        currentData.presence_mode = presenceConfig.mode;
        currentData.simulate_typing = presenceConfig.simulateTyping;
        currentData.typing_delay = presenceConfig.typingDelay;
        fs.writeFileSync(configPath, JSON.stringify(currentData, null, 2), 'utf8');
        console.log('[Presence] Configurações salvas no config.json:', presenceConfig);
    } catch (e) {
        console.error('[Presence] Falha ao persistir no config.json:', e.message);
    }

    applyGlobalPresence();
}

// =========================================================================
// DIRETÓRIOS CRÍTICOS E CONTROLE DE ESTADO
// =========================================================================
const authFolder = path.join(__dirname, 'auth_info');
const credsFile = path.join(authFolder, 'creds.json');
const credsBackupFile = path.join(authFolder, 'creds.json.bak');

const STORE_DIR = path.join(__dirname, 'store');
const MSG_STORE_DIR = path.join(STORE_DIR, 'messages');

if (!fs.existsSync(STORE_DIR)) {
    try { fs.mkdirSync(STORE_DIR, { recursive: true }); } catch (e) {}
}
if (!fs.existsSync(MSG_STORE_DIR)) {
    try { fs.mkdirSync(MSG_STORE_DIR, { recursive: true }); } catch (e) {}
}

// =========================================================================
// 1. BACKUP E PROTEÇÃO DE INTEGRIDADE DAS CREDENCIAIS (creds.json)
// =========================================================================
function backupCredentials() {
    try {
        if (fs.existsSync(credsFile)) {
            const stat = fs.statSync(credsFile);
            if (stat.size > 200) {
                const content = fs.readFileSync(credsFile, 'utf8');
                JSON.parse(content); // Valida formato JSON
                fs.copyFileSync(credsFile, credsBackupFile);
            }
        }
    } catch (e) {}
}

function restoreCredentialsIfCorrupted() {
    try {
        let isCorrupted = false;
        if (!fs.existsSync(credsFile)) {
            isCorrupted = true;
        } else {
            const stat = fs.statSync(credsFile);
            if (stat.size < 100) {
                isCorrupted = true;
            } else {
                try {
                    const content = fs.readFileSync(credsFile, 'utf8');
                    JSON.parse(content);
                } catch (pe) {
                    isCorrupted = true;
                }
            }
        }

        if (isCorrupted && fs.existsSync(credsBackupFile)) {
            const backupStat = fs.statSync(credsBackupFile);
            if (backupStat.size > 200) {
                console.warn('[WhatsApp] creds.json ausente ou corrompido! Restaurando automaticamente do backup creds.json.bak...');
                fs.copyFileSync(credsBackupFile, credsFile);
                return true;
            }
        }
    } catch (e) {
        console.error('[WhatsApp] Erro ao verificar integridade das credenciais:', e.message);
    }
    return false;
}

// =========================================================================
// 2. DISK MESSAGE STORE (PERSISTÊNCIA CONTRA "AGUARDANDO MENSAGEM...")
// =========================================================================
// Quando o celular do destinatário pede re-encriptação (retries), o Baileys
// chama getMessage(key). Com armazenamento em disco, as mensagens sobrevivem
// a qualquer reinicialização do cPanel/Passenger, eliminando a falha.

const messageMemoryCache = new Map();
const MAX_MEMORY_MESSAGES = 1500;

function storeMessage(key, message) {
    if (!key || !key.id) return;
    const msgId = String(key.id);
    const record = {
        key: {
            id: key.id,
            remoteJid: key.remoteJid,
            fromMe: Boolean(key.fromMe),
            participant: key.participant
        },
        message: message,
        timestamp: Date.now()
    };

    // 1. Salvar na memória RAM para resposta instantânea
    messageMemoryCache.set(msgId, record);
    if (messageMemoryCache.size > MAX_MEMORY_MESSAGES) {
        const oldestKey = messageMemoryCache.keys().next().value;
        messageMemoryCache.delete(oldestKey);
    }

    // 2. Salvar em disco persistente
    try {
        const safeName = msgId.replace(/[^a-zA-Z0-9_-]/g, '_');
        const targetPath = path.join(MSG_STORE_DIR, `${safeName}.json`);
        fs.promises.writeFile(targetPath, JSON.stringify(record), 'utf8').catch(() => {});
    } catch (e) {}
}

async function getStoredMessage(key) {
    if (!key || !key.id) return undefined;
    const msgId = String(key.id);

    let rawMessage = undefined;

    // 1. Tentar memória RAM
    if (messageMemoryCache.has(msgId)) {
        const entry = messageMemoryCache.get(msgId);
        rawMessage = entry.message || entry;
    } else {
        // 2. Tentar disco persistente
        try {
            const safeName = msgId.replace(/[^a-zA-Z0-9_-]/g, '_');
            const targetPath = path.join(MSG_STORE_DIR, `${safeName}.json`);
            if (fs.existsSync(targetPath)) {
                const raw = await fs.promises.readFile(targetPath, 'utf8');
                const data = JSON.parse(raw);
                if (data && data.message) {
                    messageMemoryCache.set(msgId, data);
                    rawMessage = data.message;
                }
            }
        } catch (e) {
            console.warn(`[Store] Falha ao ler mensagem persistida ${msgId}:`, e.message);
        }
    }

    if (!rawMessage) return undefined;

    // Compatibilidade total: converte para proto.Message se o Baileys esperar o objeto protobuf
    try {
        if (baileys && baileys.proto && baileys.proto.Message && typeof baileys.proto.Message.fromObject === 'function') {
            return baileys.proto.Message.fromObject(rawMessage);
        }
    } catch (protoErr) {}

    return rawMessage;
}

// Limpeza automática de mensagens com mais de 48 horas (retenção segura)
function cleanupOldStoredMessages() {
    try {
        if (!fs.existsSync(MSG_STORE_DIR)) return;
        const files = fs.readdirSync(MSG_STORE_DIR);
        const cutoff = Date.now() - (48 * 60 * 60 * 1000);
        let deleted = 0;
        for (const file of files) {
            if (!file.endsWith('.json')) continue;
            const fullPath = path.join(MSG_STORE_DIR, file);
            try {
                const stat = fs.statSync(fullPath);
                if (stat.mtimeMs < cutoff) {
                    fs.unlinkSync(fullPath);
                    deleted++;
                }
            } catch (err) {}
        }
        if (deleted > 0) {
            console.log(`[Store] Limpeza preventiva: ${deleted} mensagens antigas (>48h) removidas.`);
        }
    } catch (e) {
        console.warn('[Store] Erro ao limpar mensagens antigas:', e.message);
    }
}
cleanupOldStoredMessages();
setInterval(cleanupOldStoredMessages, 6 * 60 * 60 * 1000);

// =========================================================================
// 3. AUTO-CURA DE CRIPTOGRAFIA SIGNAL (AUTO-HEAL SEM PERDER AUTENTICAÇÃO)
// =========================================================================
// O erro "Bad MAC" / "SessionCipher" ocorre quando o arquivo de sessão de um
// contato específico perde a sincronia do ratchet de criptografia.
// Em vez de deletar todo o auth_info e perder o QR Code, removemos cirurgicamente
// apenas os arquivos de sessão corrompidos. O Baileys renegocia as chaves com o WhatsApp.

function purgeContactSessionFiles(contactIdOrPhone) {
    if (!contactIdOrPhone) return 0;
    const clean = String(contactIdOrPhone).replace(/[^0-9]/g, '');
    if (!clean) return 0;

    let removed = 0;
    try {
        if (!fs.existsSync(authFolder)) return 0;
        const files = fs.readdirSync(authFolder);
        for (const file of files) {
            // NUNCA apagar credenciais master ou chaves app-state
            if (file.startsWith('creds') || file.startsWith('app-state-sync-key')) continue;

            if (file.includes(clean)) {
                try {
                    fs.unlinkSync(path.join(authFolder, file));
                    removed++;
                } catch (e) {}
            }
        }
    } catch (err) {
        console.error('[AutoHeal] Erro ao purgar sessões do contato:', err.message);
    }
    return removed;
}

function purgeAllContactSessions() {
    let removed = 0;
    try {
        if (!fs.existsSync(authFolder)) return 0;
        const files = fs.readdirSync(authFolder);
        for (const file of files) {
            // NUNCA apagar credenciais master ou chaves de sincronização app-state
            if (file.startsWith('creds') || file.startsWith('app-state-sync-key')) continue;

            // Remove sessões transitórias, chaves de emissor, pré-chaves e versões corrompidas de app-state
            if (
                file.startsWith('session-') || 
                file.startsWith('sender-key-') || 
                file.startsWith('pre-key-') ||
                file.startsWith('device-list-') ||
                file.startsWith('app-state-sync-version-')
            ) {
                try {
                    fs.unlinkSync(path.join(authFolder, file));
                    removed++;
                } catch (e) {}
            }
        }
        console.log(`[AutoHeal] Faxina preventiva concluída: ${removed} arquivos de sessão renovados. creds.json preservado.`);
    } catch (err) {
        console.error('[AutoHeal] Erro ao purgar todas as sessões:', err.message);
    }
    return removed;
}

let lastAutoHealTime = 0;
function handleCryptoError(error) {
    if (!error) return false;
    const msg = error instanceof Error ? `${error.message}\n${error.stack}` : String(error);
    const isCryptoError = 
        msg.includes('Bad MAC') || 
        msg.includes('SessionCipher') || 
        msg.includes('doDecryptWhisperMessage') || 
        msg.includes('doDecryptPreKeyWhisperMessage') ||
        msg.includes('Failed to decrypt message') ||
        msg.includes('SignalError');

    if (!isCryptoError) return false;

    // Throttle para evitar tempestade de reparos
    const now = Date.now();
    if (now - lastAutoHealTime < 5000) {
        return false;
    }
    lastAutoHealTime = now;

    console.warn('[AutoHeal] Desincronização de criptografia E2EE detectada:', msg.split('\n')[0]);

    // Tentar identificar o contato envolvido
    const jidMatch = msg.match(/(\d{10,15})(@s\.whatsapp\.net|@lid|)/);
    if (jidMatch && jidMatch[1]) {
        const jidNumber = jidMatch[1];
        console.log(`[AutoHeal] Curando sessão individual do contato ${jidNumber}...`);
        const count = purgeContactSessionFiles(jidNumber);
        console.log(`[AutoHeal] ${count} arquivo(s) de sessão resetados para ${jidNumber}. Chaves serão renegociadas.`);
        return true;
    }

    // Se o contato não for identificado, renova sessões mantendo o creds.json
    console.log('[AutoHeal] Executando renovação de chaves em lote (mantendo login creds.json)...');
    purgeAllContactSessions();
    return true;
}

// Cache para o Baileys gerenciar as contagens de retry
class SimpleCache {
    constructor(ttlMs = 3600000) {
        this.ttl = ttlMs;
        this.cache = new Map();
    }
    get(key) {
        const item = this.cache.get(key);
        if (!item) return undefined;
        if (Date.now() > item.expiry) {
            this.cache.delete(key);
            return undefined;
        }
        return item.value;
    }
    set(key, value) {
        this.cache.set(key, { value, expiry: Date.now() + this.ttl });
        if (this.cache.size > 3000) {
            const now = Date.now();
            for (const [k, v] of this.cache.entries()) {
                if (now > v.expiry) this.cache.delete(k);
            }
        }
    }
    del(key) {
        this.cache.delete(key);
    }
    flushAll() {
        this.cache.clear();
    }
}
const msgRetryCounterCache = new SimpleCache(3600000);

// Limite de logs para evitar crescimento infinito
const MAX_LOG_SIZE = 5 * 1024 * 1024; // 5MB

const logErrorToFile = (error) => {
    try {
        const logPath = path.join(__dirname, 'error.log');
        // Rotacionar log se muito grande
        if (fs.existsSync(logPath)) {
            const stats = fs.statSync(logPath);
            if (stats.size > MAX_LOG_SIZE) {
                const backupPath = path.join(__dirname, 'error.old.log');
                fs.renameSync(logPath, backupPath);
            }
        }
        const timestamp = new Date().toISOString();
        const errorMessage = error instanceof Error ? `${error.message}\n${error.stack}` : String(error);
        const logMessage = `[${timestamp}] CRASH/ERROR:\n${errorMessage}\n----------------------------------------\n`;
        fs.appendFileSync(logPath, logMessage, 'utf8');
    } catch (e) {
        console.error('Falha ao gravar no arquivo error.log:', e.message);
    }
};

// Capturar erros globais com Auto-Heal integrado
process.on('uncaughtException', (err) => {
    console.error('[GLOBAL] Uncaught Exception:', err);
    logErrorToFile(err);
    handleCryptoError(err);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('[GLOBAL] Unhandled Rejection:', reason);
    logErrorToFile(reason);
    handleCryptoError(reason);
});

// Middleware de Autenticação
function authenticateToken(req, res, next) {
    if (!SECRET_TOKEN || SECRET_TOKEN.trim() === '') {
        return next();
    }
    const authHeader = req.headers['authorization'];
    const token = authHeader && authHeader.split(' ')[1];
    if (!token || token !== SECRET_TOKEN) {
        console.warn('[API] Tentativa de acesso não autorizado de:', req.ip);
        return res.status(403).json({ error: 'Token inválido ou ausente' });
    }
    next();
}

// Health check para monitoramento
function performHealthCheck() {
    const now = Date.now();
    const timeSinceLastMessage = now - lastMessageTime;

    // Se passou mais de 5 minutos sem atividade e está CONNECTED, pode ter travado
    if (sessionStatus === 'CONNECTED' && timeSinceLastMessage > 300000) {
        console.warn('[HealthCheck] Possível travamento detectado - 5min sem atividade');
        // Tenta um ping no socket para ver se responde
        if (sock) {
            try {
                sock.sendMessage('status@broadcast', { text: 'ping' }).catch(() => {
                    console.warn('[HealthCheck] Socket não respondeu, reiniciando...');
                    forceReconnect();
                });
            } catch (e) {
                forceReconnect();
            }
        }
    }
}

// Forçar reconexão
function forceReconnect() {
    if (isReconnecting) return;
    isReconnecting = true;
    sessionStatus = 'DISCONNECTED';
    if (sock) {
        try {
            sock.end();
        } catch (e) {}
        sock = null;
    }
    qrCodeBase64 = null;
    setTimeout(() => {
        isReconnecting = false;
        initWhatsApp();
    }, 3000);
}

// Inicializar WhatsApp com reconexão melhorada
async function initWhatsApp() {
    if (sessionStatus === 'INITIALIZING' || sessionStatus === 'CONNECTED') {
        console.log('[WhatsApp] Sessão já está ativa ou inicializando.');
        return;
    }

    if (isReconnecting) {
        console.log('[WhatsApp] Já está em processo de reconexão, aguarde...');
        return;
    }

    sessionStatus = 'INITIALIZING';
    qrCodeBase64 = null;
    reconnectAttempts = 0;
    console.log(`[WhatsApp] Inicializando conexão via Baileys para a sessão "${SESSION_NAME}"...`);

    try {
        restoreCredentialsIfCorrupted();
        const { state, saveCreds } = await useMultiFileAuthState(authFolder);

        let version = undefined;
        if (typeof fetchLatestBaileysVersion === 'function') {
            try {
                const v = await fetchLatestBaileysVersion();
                version = v.version;
            } catch (e) {
                console.warn('[WhatsApp] Usando versão interna do Baileys:', e.message);
            }
        }

        const socketConfig = {
            auth: state,
            printQRInTerminal: false,
            logger: require('pino')({ level: 'silent' }),
            browser: Browsers ? Browsers.ubuntu('Chrome') : ['Ubuntu', 'Chrome', '22.04.2'],
            syncFullHistory: false,
            markOnlineOnConnect: presenceConfig.mode === 'online',
            generateHighQualityLinkPreview: false,
            // Timeouts para evitar travamentos
            connectTimeoutMs: 60000,
            defaultQueryTimeoutMs: 60000,
            keepAliveIntervalMs: 30000,
            // CRÍTICO: Resolve permanentemente "Aguardando mensagem. Essa ação pode levar alguns instantes"
            // Busca na RAM e no disco persistente (/store/messages) mesmo após reboot do cPanel/Passenger.
            getMessage: async (key) => {
                return await getStoredMessage(key);
            },
            msgRetryCounterCache,
            // Formatação segura de mensagens para evitar exceção interna no Baileys
            patchMessageBeforeSending: (message) => {
                try {
                    const requiresPatch = message?.interactiveMessage || message?.buttonsMessage || message?.templateMessage || message?.listMessage;
                    if (requiresPatch) {
                        return {
                            viewOnceMessage: {
                                message: {
                                    messageContextInfo: {
                                        deviceListMetadataVersion: 2,
                                        deviceListMetadata: {},
                                    },
                                    ...message,
                                },
                            },
                        };
                    }
                    return message;
                } catch (e) {
                    return message;
                }
            }
        };

        if (version) {
            socketConfig.version = version;
        }

        sock = makeWASocket(socketConfig);

        // Atualizar timestamp e salvar mensagens no store persistente
        sock.ev.on('messages.upsert', ({ messages }) => {
            try {
                lastMessageTime = Date.now();
                if (Array.isArray(messages)) {
                    for (const msg of messages) {
                        if (msg.key && msg.key.id && msg.message) {
                            storeMessage(msg.key, msg.message);
                        }
                    }
                }
            } catch (upsertErr) {
                console.warn('[WhatsApp] Erro em messages.upsert:', upsertErr.message);
                handleCryptoError(upsertErr);
            }
        });

        sock.ev.on('creds.update', async () => {
            try {
                await saveCreds();
                backupCredentials();
            } catch (credErr) {
                console.error('[WhatsApp] Erro ao salvar credenciais:', credErr.message);
            }
        });

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                sessionStatus = 'QRCODE';
                try {
                    qrCodeBase64 = await QRCode.toDataURL(qr);
                    console.log('[WhatsApp] Novo QR Code gerado.');
                } catch (err) {
                    console.error('Erro ao gerar imagem do QR Code:', err);
                }
            }

            if (connection === 'close') {
                if (presenceInterval) {
                    clearInterval(presenceInterval);
                    presenceInterval = null;
                }
                const statusCode = lastDisconnect?.error?.output?.statusCode;
                const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

                console.log(`[WhatsApp] Conexão encerrada. Motivo: ${statusCode}. Reconectando: ${shouldReconnect}`);
                sessionStatus = 'DISCONNECTED';
                sock = null;
                qrCodeBase64 = null;

                if (shouldReconnect && !isReconnecting) {
                    reconnectAttempts++;
                    const delay = Math.min(5000 * reconnectAttempts, 60000);
                    console.log(`[WhatsApp] Tentativa ${reconnectAttempts} de reconexão em ${delay}ms...`);
                    isReconnecting = true;
                    setTimeout(() => {
                        isReconnecting = false;
                        initWhatsApp();
                    }, delay);
                } else if (statusCode === DisconnectReason.loggedOut) {
                    console.log('[WhatsApp] Deslogado permanentemente. Removendo credenciais...');
                    try {
                        if (fs.existsSync(authFolder)) {
                            fs.rmSync(authFolder, { recursive: true, force: true });
                            console.log('[WhatsApp] Credenciais removidas. Reinicie o serviço para reconectar.');
                        }
                    } catch (e) {
                        console.error('Erro ao remover credenciais:', e);
                    }
                    sessionStatus = 'DISCONNECTED';
                }
            } else if (connection === 'open') {
                console.log(`[WhatsApp] Sessão "${SESSION_NAME}" CONECTADA com sucesso!`);
                sessionStatus = 'CONNECTED';
                qrCodeBase64 = null;
                reconnectAttempts = 0;
                lastMessageTime = Date.now();
                applyGlobalPresence();
            }
        });

    } catch (err) {
        console.error('[WhatsApp] Falha ao iniciar Baileys:', err);
        logErrorToFile(err);
        sessionStatus = 'DISCONNECTED';
        sock = null;
        // Tentar reconectar após erro
        if (!isReconnecting) {
            isReconnecting = true;
            setTimeout(() => {
                isReconnecting = false;
                initWhatsApp();
            }, 10000);
        }
    }
}

// Router
const router = express.Router();

router.get('/', (req, res) => {
    const basePath = req.baseUrl;
    let statusBadge = '';
    let content = '';

    if (sessionStatus === 'CONNECTED') {
        statusBadge = '<span class="badge bg-success fs-5">Conectado</span>';
        content = `
            <div class="alert alert-success">
                <strong>Sucesso!</strong> O WhatsApp está autenticado e pronto para enviar mensagens.
            </div>

            <!-- Badges de Proteção Ativa -->
            <div class="row g-2 mb-3 text-start">
                <div class="col-sm-6">
                    <div class="p-2 rounded-3" style="background-color: #0f172a; border: 1px solid #1e293b;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-shield-lock-fill text-success fs-4 me-2"></i>
                            <div>
                                <div class="text-white small fw-bold">Auto-Cura Signal (E2EE)</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Protegido contra Bad MAC / Desincronização</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-2 rounded-3" style="background-color: #0f172a; border: 1px solid #1e293b;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-hdd-network-fill text-info fs-4 me-2"></i>
                            <div>
                                <div class="text-white small fw-bold">Store Persistente em Disco</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Evita "Aguardando mensagem..." após reboots</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="text-muted small">Você pode fechar esta página. O serviço continuará rodando em segundo plano.</p>
            
            <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                <button onclick="forceReconnect()" class="btn btn-warning btn-sm px-3">
                    <i class="bi bi-arrow-repeat me-1"></i> Reconectar
                </button>
                <button id="btnRepair" onclick="repairSessions()" class="btn btn-info btn-sm px-3 text-dark fw-bold" title="Renova chaves de criptografia corrompidas de contatos sem desconectar e sem ler QR Code">
                    <i class="bi bi-shield-check me-1"></i> Reparar Criptografia (Sem Desconectar)
                </button>
                <button onclick="resetSession()" class="btn btn-outline-danger btn-sm px-3" title="Limpa todo o login e gera um novo QR Code (use apenas se trocou de chip/aparelho)">
                    <i class="bi bi-qr-code me-1"></i> Novo QR Code
                </button>
            </div>

            <div class="mt-4 p-4 text-start rounded-4" style="background-color: #0f172a; border: 1px solid #334155;">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-sliders text-info me-2"></i>Controle de Status e Presença</h5>
                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Tempo Real</span>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold">COMPORTAMENTO DO STATUS (ONLINE / OFFLINE):</label>
                    
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="presenceMode" id="modePhone" value="phone" ${presenceConfig.mode === 'phone' ? 'checked' : ''}>
                        <label class="form-check-label text-light" for="modePhone">
                            <i class="bi bi-phone text-info me-1"></i> <strong>Controlado pelo Celular (Principal / Recomendado)</strong>
                            <div class="text-secondary small">O servidor não interfere no status. O seu celular físico dita quando você está online ou offline naturalmente.</div>
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="presenceMode" id="modeOnline" value="online" ${presenceConfig.mode === 'online' ? 'checked' : ''}>
                        <label class="form-check-label text-light" for="modeOnline">
                            <i class="bi bi-circle-fill text-success small me-1"></i> <strong>Manter Sempre Online</strong>
                            <div class="text-secondary small">O servidor mantém a conta com status "Online" continuamente para seus contatos.</div>
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="presenceMode" id="modeOffline" value="offline" ${presenceConfig.mode === 'offline' ? 'checked' : ''}>
                        <label class="form-check-label text-light" for="modeOffline">
                            <i class="bi bi-dash-circle text-secondary small me-1"></i> <strong>Manter Offline / Sem Status</strong>
                            <div class="text-secondary small">O servidor oculta o status online ativamente, mantendo sua conta invisível.</div>
                        </label>
                    </div>
                </div>

                <hr style="border-color: #334155;">

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold">AO DISPARAR MENSAGENS:</label>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="simulateTyping" ${presenceConfig.simulateTyping ? 'checked' : ''} onchange="toggleTypingDelay()">
                        <label class="form-check-label text-light" for="simulateTyping">
                            <i class="bi bi-chat-dots text-warning me-1"></i> <strong>Simular "Digitando..." antes de enviar</strong>
                            <div class="text-secondary small">Mostra "digitando..." para o cliente por alguns instantes antes do texto ser entregue.</div>
                        </label>
                    </div>

                    <div class="row g-2 align-items-center mt-2 ${presenceConfig.simulateTyping ? '' : 'd-none'}" id="typingDelayContainer">
                        <div class="col-auto">
                            <label for="typingDelay" class="text-secondary small">Tempo digitando:</label>
                        </div>
                        <div class="col-auto">
                            <select class="form-select form-select-sm bg-dark text-white border-secondary" id="typingDelay">
                                <option value="1000" ${presenceConfig.typingDelay === 1000 ? 'selected' : ''}>1.0 segundo</option>
                                <option value="1500" ${presenceConfig.typingDelay === 1500 ? 'selected' : ''}>1.5 segundos (Padrão)</option>
                                <option value="2500" ${presenceConfig.typingDelay === 2500 ? 'selected' : ''}>2.5 segundos</option>
                                <option value="4000" ${presenceConfig.typingDelay === 4000 ? 'selected' : ''}>4.0 segundos</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between pt-2">
                    <button type="button" onclick="savePresenceSettings()" class="btn btn-primary btn-sm px-3 shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Salvar Preferências
                    </button>
                    <span id="presenceSaveAlert" class="badge bg-success py-2 px-3" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i> Salvo com sucesso!
                    </span>
                </div>
            </div>

            <script>
            function forceReconnect() {
                fetch('${basePath}/api/force-reconnect', { method: 'POST' })
                    .then(() => window.location.reload());
            }
            function repairSessions() {
                if (confirm('Deseja reparar as chaves de criptografia? Isso corrige falhas de descriptografia (Bad MAC / Aguardando mensagem) SEM deslogar e SEM precisar ler o QR Code.')) {
                    const btn = document.getElementById('btnRepair');
                    const origHtml = btn ? btn.innerHTML : '';
                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Reparando...';
                    }
                    fetch('${basePath}/api/repair-sessions', { method: 'POST' })
                        .then(r => r.json())
                        .then(data => {
                            alert(data.message || 'Chaves renovadas com sucesso!');
                            window.location.reload();
                        })
                        .catch(err => {
                            alert('Erro ao reparar: ' + err.message);
                            if (btn) {
                                btn.disabled = false;
                                btn.innerHTML = origHtml;
                            }
                        });
                }
            }
            function resetSession() {
                if (confirm('Deseja realmente limpar a sessão e ler novo QR Code? Isso renova todas as chaves criptográficas do WhatsApp.')) {
                    fetch('${basePath}/api/reset-session', { method: 'POST' })
                        .then(() => setTimeout(() => window.location.reload(), 1500));
                }
            }
            function toggleTypingDelay() {
                const isChecked = document.getElementById('simulateTyping').checked;
                const container = document.getElementById('typingDelayContainer');
                if (isChecked) {
                    container.classList.remove('d-none');
                } else {
                    container.classList.add('d-none');
                }
            }
            function savePresenceSettings() {
                const mode = document.querySelector('input[name="presenceMode"]:checked').value;
                const simulateTyping = document.getElementById('simulateTyping').checked;
                const typingDelay = parseInt(document.getElementById('typingDelay').value);
                const alertEl = document.getElementById('presenceSaveAlert');

                fetch('${basePath}/api/presence-settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode, simulateTyping, typingDelay })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alertEl.style.display = 'inline-block';
                        setTimeout(() => { alertEl.style.display = 'none'; }, 3500);
                    } else {
                        alert('Erro ao salvar: ' + (data.error || 'Falha desconhecida'));
                    }
                })
                .catch(err => {
                    alert('Falha na comunicação: ' + err.message);
                });
            }
            </script>
        `;
    } else if (sessionStatus === 'QRCODE' && qrCodeBase64) {
        statusBadge = '<span class="badge bg-warning text-dark fs-5">Aguardando QR Code</span>';
        content = `
            <p class="text-muted fs-6">Aponte o leitor de QR Code do seu WhatsApp para a imagem abaixo:</p>
            <div class="text-center my-4">
                <img src="${qrCodeBase64}" alt="QR Code" class="img-thumbnail img-fluid border border-dark p-2" style="max-width: 320px;" />
            </div>
            <div class="alert alert-info py-2 small">
                No celular, vá em: <strong>Aparelhos Conectados &gt; Conectar um Aparelho</strong>.
            </div>
            <script>
                setInterval(() => {
                    fetch('${basePath}/api/status-raw')
                        .then(r => r.json())
                        .then(data => {
                            if (data.status === 'CONNECTED') {
                                window.location.reload();
                            }
                        })
                        .catch(() => {});
                }, 3000);
            </script>
        `;
    } else if (sessionStatus === 'INITIALIZING') {
        statusBadge = '<span class="badge bg-info text-dark fs-5">Iniciando...</span>';
        content = `
            <div class="d-flex align-items-center justify-content-center my-5">
                <div class="spinner-grow text-info" style="width: 3rem; height: 3rem;" role="status"></div>
            </div>
            <p class="text-muted">Aguarde. O WhatsApp está iniciando...</p>
            <script>
                setTimeout(() => { window.location.reload(); }, 4000);
            </script>
        `;
    } else {
        statusBadge = '<span class="badge bg-danger fs-5">Desconectado</span>';
        content = `
            <div class="alert alert-danger">
                O serviço está inativo ou o celular foi desconectado.
            </div>
            <form action="${basePath}/api/init-session" method="POST" class="mt-4">
                <button type="submit" class="btn btn-success btn-lg px-4"><i class="bi bi-play-fill"></i> Iniciar Sessão</button>
            </form>
            <div class="mt-3 text-start small">
                <a href="${basePath}/api/view-log" target="_blank" class="text-secondary"><i class="bi bi-journal-text"></i> Ver logs</a>
            </div>
        `;
    }

    res.send(`
        <!DOCTYPE html>
        <html lang="pt-br">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>WhatsApp API - Provedor ISP</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
            <style>
                body { background-color: #0f172a; color: #f8fafc; font-family: system-ui, sans-serif; }
                .card { background-color: #1e293b; border: 1px solid #334155; border-radius: 16px; }
                hr { border-color: #334155; }
            </style>
        </head>
        <body>
            <div class="container py-5">
                <div class="row justify-content-center">
                    <div class="col-lg-7 col-md-9 col-12">
                        <div class="card p-5 text-center shadow-lg">
                            <h2 class="fw-bold mb-1"><i class="bi bi-whatsapp text-success me-2"></i>WhatsApp API</h2>
                            <p class="text-secondary small">Integração com Sistema de Cobrança</p>
                            <div class="my-4">${statusBadge}</div>
                            <hr>
                            <div class="my-3">${content}</div>
                            <div class="mt-5 text-muted small">
                                Sessão: <span class="badge bg-secondary font-monospace">${SESSION_NAME}</span> | 
                                Porta: <span class="badge bg-secondary font-monospace">${PORT}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
    `);
});

router.get('/api/status-raw', (req, res) => {
    res.json({
        status: sessionStatus,
        reconnectAttempts: reconnectAttempts,
        uptime: process.uptime()
    });
});

router.get('/api/presence-settings', (req, res) => {
    res.json({
        success: true,
        presenceConfig
    });
});

router.post('/api/presence-settings', (req, res) => {
    const { mode, simulateTyping, typingDelay } = req.body || {};
    updatePresenceConfig({ mode, simulateTyping, typingDelay });
    res.json({
        success: true,
        message: 'Configurações de presença salvas com sucesso',
        presenceConfig
    });
});

router.get('/api/view-log', (req, res) => {
    const logPath = path.join(__dirname, 'error.log');
    if (fs.existsSync(logPath)) {
        res.setHeader('Content-Type', 'text/plain; charset=utf-8');
        res.sendFile(logPath);
    } else {
        res.send('Nenhum erro registrado.');
    }
});

router.post('/api/init-session', (req, res) => {
    const basePath = req.baseUrl;
    if (sessionStatus === 'DISCONNECTED') {
        initWhatsApp();
    }
    res.redirect(basePath + '/');
});

router.post('/api/force-reconnect', (req, res) => {
    forceReconnect();
    res.json({ success: true, message: 'Reconexão iniciada' });
});

router.post('/api/reset-session', (req, res) => {
    try {
        if (sock) {
            try { sock.end(); } catch (e) {}
            sock = null;
        }
        sessionStatus = 'DISCONNECTED';
        qrCodeBase64 = null;
        if (fs.existsSync(authFolder)) {
            fs.rmSync(authFolder, { recursive: true, force: true });
        }
        console.log('[WhatsApp] Sessão limpa pelo usuário. Reiniciando para novo QR Code...');
        setTimeout(() => initWhatsApp(), 1500);
        res.json({ success: true, message: 'Sessão limpa com sucesso. Leia o novo QR Code.' });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

router.post('/api/repair-sessions', async (req, res) => {
    try {
        console.log('[API] Reparo manual de criptografia solicitado.');
        const removed = purgeAllContactSessions();

        // Reinicializar o socket de forma limpa preservando creds.json
        sessionStatus = 'DISCONNECTED';
        if (sock) {
            try { sock.end(); } catch (e) {}
            sock = null;
        }
        setTimeout(() => {
            initWhatsApp();
        }, 1500);

        return res.json({
            success: true,
            removedFiles: removed,
            message: `Reparo concluído! ${removed} chaves transitórias foram renovadas. O WhatsApp está restabelecendo a criptografia sem precisar ler o QR Code.`
        });
    } catch (err) {
        console.error('[API] Erro ao reparar sessões:', err);
        return res.status(500).json({ success: false, error: err.message });
    }
});

router.get('/api/health', (req, res) => {
    res.json({
        status: sessionStatus,
        connected: sessionStatus === 'CONNECTED',
        uptime: process.uptime(),
        reconnectAttempts: reconnectAttempts,
        timestamp: new Date().toISOString()
    });
});

router.get('/api/:session/check-connection-session', authenticateToken, (req, res) => {
    const { session } = req.params;
    if (session !== SESSION_NAME) {
        return res.status(400).json({ status: false, error: 'Nome da sessão inválido' });
    }

    if (sessionStatus === 'CONNECTED' && sock) {
        return res.status(200).json({ status: true, sessionStatus: 'CONNECTED' });
    }

    // Se desconectado, tenta reconectar automaticamente
    if (sessionStatus === 'DISCONNECTED' && !isReconnecting) {
        setTimeout(() => initWhatsApp(), 1000);
    }

    return res.status(500).json({ status: false, sessionStatus: sessionStatus });
});

router.post('/api/:session/send-message', authenticateToken, async (req, res) => {
    const { session } = req.params;
    const { phone, message } = req.body;

    if (session !== SESSION_NAME) {
        return res.status(400).json({ error: 'Nome da sessão inválido' });
    }

    if (sessionStatus !== 'CONNECTED' || !sock) {
        // Tenta reconectar antes de falhar
        if (sessionStatus === 'DISCONNECTED' && !isReconnecting) {
            initWhatsApp();
            // Aguarda um pouco para tentar reconectar
            await new Promise(resolve => setTimeout(resolve, 3000));
            if (sessionStatus !== 'CONNECTED') {
                return res.status(503).json({ error: 'WhatsApp desconectado. Tentando reconectar...' });
            }
        } else {
            return res.status(503).json({ error: 'WhatsApp não conectado' });
        }
    }

    if (!phone || !message) {
        return res.status(400).json({ error: 'Campos "phone" e "message" são obrigatórios' });
    }

    try {
        let cleanPhone = phone.trim().replace(/[^0-9]/g, '');
        if (cleanPhone.length === 10 || cleanPhone.length === 11) {
            cleanPhone = '55' + cleanPhone;
        }
        let targetJid = null;

        // Validar e obter o JID canônico exato registrado no WhatsApp (resolve o 9º dígito no Brasil)
        try {
            const onWa = await sock.onWhatsApp(cleanPhone);
            if (Array.isArray(onWa) && onWa.length > 0 && onWa[0].exists && onWa[0].jid) {
                targetJid = onWa[0].jid;
            }
        } catch (onWaErr) {
            console.warn('[API] Aviso ao consultar onWhatsApp:', onWaErr.message);
        }

        if (!targetJid) {
            targetJid = `${cleanPhone}@s.whatsapp.net`;
        }

        // Simular presença "digitando..." se configurado
        if (presenceConfig.simulateTyping) {
            try {
                await sock.sendPresenceUpdate('composing', targetJid);
                const delayMs = presenceConfig.typingDelay || 1500;
                await new Promise(resolve => setTimeout(resolve, delayMs));
                await sock.sendPresenceUpdate('paused', targetJid);
            } catch (presErr) {
                console.warn('[Presence] Erro ao simular digitando:', presErr.message);
            }
        }

        console.log(`[API] Enviando mensagem para: ${targetJid} (original: ${phone})`);
        const result = await sock.sendMessage(targetJid, { text: message });
        lastMessageTime = Date.now();

        // Se modo for offline, reaplica offline após o envio
        if (presenceConfig.mode === 'offline') {
            sock.sendPresenceUpdate('unavailable').catch(() => {});
        }

        // Salvar a mensagem no store persistente para responder a retries de criptografia
        if (result && result.key) {
            storeMessage(result.key, result.message || { conversation: message });
        }

        return res.status(200).json({
            success: true,
            messageId: result && result.key ? result.key.id : null,
            to: targetJid
        });
    } catch (err) {
        console.error('[API] Falha ao enviar mensagem:', err);
        logErrorToFile(err);
        handleCryptoError(err);
        return res.status(500).json({
            error: 'Falha ao enviar mensagem',
            details: err.message
        });
    }
});

app.use('/whatsapp', router);
app.use('/', router);

// Função para fazer requisições com timeout
function makeRequest(url, method = 'GET', headers = {}, payload = null, timeout = 30000) {
    return new Promise((resolve, reject) => {
        const client = url.startsWith('https') ? https : http;

        const req = client.request(url, {
            method,
            headers,
            timeout: timeout
        }, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                resolve({
                    statusCode: res.statusCode,
                    body: data
                });
            });
        });

        req.on('error', (err) => { reject(err); });
        req.on('timeout', () => {
            req.destroy();
            reject(new Error('Timeout na requisição'));
        });

        if (payload) {
            req.write(typeof payload === 'string' ? payload : JSON.stringify(payload));
        }
        req.end();
    });
}

// Cron job melhorado
cron.schedule('* * * * *', async () => {
    // Evitar execução concorrente
    if (isCronRunning) {
        console.log('[Agendador] Execução anterior ainda em andamento, pulando...');
        return;
    }

    isCronRunning = true;
    try {
        const baseUrlClean = getPhpBaseUrl();
        const checkUrl = `${baseUrlClean}/cron/disparar_whatsapp.php?action=schedule_check`;
        const headers = SECRET_TOKEN ? { 'Authorization': `Bearer ${SECRET_TOKEN}` } : {};

        const checkRes = await makeRequest(checkUrl, 'GET', headers, null, 10000);

        if (checkRes.statusCode === 200) {
            try {
                const data = JSON.parse(checkRes.body);
                if (data.should_dispatch) {
                    console.log('[Agendador] Iniciando envio de cobranças...');
                    const dispatchUrl = `${baseUrlClean}/cron/disparar_whatsapp.php?action=dispatch`;
                    const dispatchRes = await makeRequest(dispatchUrl, 'GET', headers, null, 60000);
                    console.log('[Agendador] Lote enviado.');
                }
            } catch (parseErr) {
                console.error('[Agendador] Erro ao parsear resposta:', parseErr.message);
            }
        }
    } catch (err) {
        console.error('[Agendador] Erro:', err.message);
        logErrorToFile(err);
    } finally {
        isCronRunning = false;
    }
});

// Health check a cada 2 minutos
setInterval(performHealthCheck, 120000);

// Iniciar servidor
const server = app.listen(PORT, '0.0.0.0', () => {
    console.log(`=============================================================`);
    console.log(`Servidor WhatsApp rodando na porta: ${PORT}`);
    console.log(`URL: http://localhost:${PORT}`);
    console.log(`Sessão: ${SESSION_NAME}`);
    console.log(`=============================================================`);
    initWhatsApp();
});

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('SIGTERM recebido, finalizando...');
    server.close(() => {
        if (sock) {
            try { sock.end(); } catch (e) {}
        }
        process.exit(0);
    });
});

process.on('SIGINT', () => {
    console.log('SIGINT recebido, finalizando...');
    server.close(() => {
        if (sock) {
            try { sock.end(); } catch (e) {}
        }
        process.exit(0);
    });
});
