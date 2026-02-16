<?php

// ========================================
// 1. CONFIGURAÇÕES DE SEGURANÇA
// ========================================
// Headers de segurança (aplicar em todos os arquivos)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Iniciar sessão segura
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// ========================================
// 2. CREDENCIAIS DO BANCO
// ========================================
// Usar variáveis de ambiente em produção
$host   = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'Seu-Banco-De-Dados';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';


// ========================================
// 3. CONEXÃO PDO
// ========================================
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {
    // NÃO revelar detalhes do erro em produção
    error_log("Erro de conexão PDO: " . $e->getMessage());
    die(json_encode([
        'success' => false,
        'mensagem' => 'Erro ao conectar ao banco de dados. Tente novamente mais tarde.'
    ]));
}

// ========================================
// 4. FUNÇÕES AUXILIARES DE SEGURANÇA
// ========================================

/**
 * Gera token CSRF
 */
function gerarCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida token CSRF
 */
function validarCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitiza string genérica
 */
function sanitizarString($string, $maxLength = 255) {
    $string = trim($string);
    $string = substr($string, 0, $maxLength);
    $string = htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    return $string;
}

/**
 * Valida endereço de contrato Ethereum/BSC
 */
function validarContrato($contract) {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $contract);
}

/**
 * Valida orderId
 */
function validarOrderId($orderId) {
    return preg_match('/^[A-Z0-9]{8,20}$/', $orderId);
}

/**
 * Valida valor USDT
 */
function validarValor($valor) {
    $valor = floatval($valor);
    return $valor > 0 && $valor <= 1000000; // máximo 1 milhão
}

/**
 * Log seguro de eventos
 */
function logEvento($tipo, $mensagem, $dados = []) {
    $log = [
        'timestamp' => date('Y-m-d H:i:s'),
        'tipo' => $tipo,
        'mensagem' => $mensagem,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'dados' => $dados
    ];
    
    $logFile = __DIR__ . '/logs/paynch_' . date('Y-m-d') . '.log';
    
    // Criar diretório de logs se não existir
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0750, true);
    }
    
    error_log(json_encode($log) . PHP_EOL, 3, $logFile);
}

/**
 * Consulta API Paynch usando cURL
 * CORRIGIDO: Usa cURL e valida response corretamente
 */
function consultarPaynchApi($contract, $orderId) {
    $url = "https://api.paynch.io/paynch.php?" . http_build_query([
        'contract' => $contract,
        'orderId' => $orderId
    ]);
    
    // Log da requisição
    logEvento('API_REQUEST', 'Consultando Paynch API', [
        'url' => $url,
        'contract' => $contract,
        'orderId' => $orderId
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: PaynchIntegration/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Verifica erro de cURL
    if ($response === false || !empty($curlError)) {
        logEvento('CURL_ERROR', 'Erro ao fazer requisição cURL', [
            'url' => $url,
            'error' => $curlError,
            'orderId' => $orderId
        ]);
        return false;
    }
    
    // Verifica HTTP status
    if ($httpCode !== 200) {
        logEvento('HTTP_ERROR', 'API retornou código HTTP diferente de 200', [
            'httpCode' => $httpCode,
            'response' => substr($response, 0, 500),
            'orderId' => $orderId
        ]);
        return false;
    }
    
    // Decodifica JSON
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logEvento('JSON_ERROR', 'Resposta da API não é JSON válido', [
            'error' => json_last_error_msg(),
            'response' => substr($response, 0, 500),
            'orderId' => $orderId
        ]);
        return false;
    }
    
    // Log da resposta completa para debug
    logEvento('API_RESPONSE', 'Resposta da API recebida', [
        'orderId' => $orderId,
        'success' => $data['success'] ?? false,
        'count' => $data['count'] ?? 0,
        'payments_count' => isset($data['payments']) ? count($data['payments']) : 0,
        'first_payment' => isset($data['payments'][0]) ? [
            'customer' => $data['payments'][0]['customer'] ?? 'N/A',
            'amount' => $data['payments'][0]['amountHuman'] ?? 'N/A',
            'orderId' => $data['payments'][0]['orderId'] ?? 'N/A'
        ] : null
    ]);
    
    return $data;
}

// ========================================
// 5. CONSTANTES DA APLICAÇÃO
// ========================================
define('TOLERANCIA_PERCENTUAL', 1.2); // 1.2% de tolerância (taxas da plataforma, apenas para quem não tem premium)
define('MAX_TENTATIVAS_AUTO', 12); 
define('INTERVALO_VERIFICACAO_MS', 5000);
define('DELAY_INICIAL_MS', 3000);

// ========================================
// 6. CONFIGURAÇÃO DE ERROS
// ========================================
// Em produção, desabilitar display de erros
if (getenv('ENVIRONMENT') === 'production') {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}