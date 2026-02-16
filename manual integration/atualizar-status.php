<?php
/**

 * 
 * Responsabilidades:
 * 1. Consultar API Paynch
 * 2. Validar pagamento
 * 3. Atualizar status no banco
 */

require_once 'config.php';

// ========================================
// 1. HEADERS E CONFIGURAÇÕES
// ========================================
header('Content-Type: application/json; charset=utf-8');

// ========================================
// 2. VALIDAÇÃO DE MÉTODO HTTP
// ========================================
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'pago' => false,
        'mensagem' => 'Método HTTP não permitido'
    ]);
    exit;
}

// ========================================
// 3. RATE LIMITING SIMPLES
// ========================================
$ip = $_SERVER['REMOTE_ADDR'];
$rateKey = "rate_limit_$ip";

if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
}

if (time() - $_SESSION[$rateKey]['time'] > 60) {
    $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
}

if ($_SESSION[$rateKey]['count'] > 30) {
    http_response_code(429);
    echo json_encode([
        'pago' => false,
        'mensagem' => 'Muitas requisições. Aguarde 1 minuto.'
    ]);
    logEvento('RATE_LIMIT', 'IP bloqueado temporariamente', ['ip' => $ip]);
    exit;
}

$_SESSION[$rateKey]['count']++;

// ========================================
// 4. RECEBE E VALIDA PARÂMETROS
// ========================================
$orderId = $_REQUEST['orderId'] ?? $_REQUEST['order_id'] ?? '';
$orderId = trim($orderId);

if (empty($orderId)) {
    http_response_code(400);
    echo json_encode([
        'pago' => false,
        'mensagem' => 'Order ID não informado'
    ]);
    logEvento('VALIDATION_ERROR', 'OrderID vazio');
    exit;
}

if (!validarOrderId($orderId)) {
    http_response_code(400);
    echo json_encode([
        'pago' => false,
        'mensagem' => 'Order ID inválido'
    ]);
    logEvento('VALIDATION_ERROR', 'OrderID com formato inválido', ['orderId' => $orderId]);
    exit;
}

// ========================================
// 5. BUSCA PEDIDO NO BANCO (COM LOCK)
// ========================================
try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            valor_usdt, 
            status,
            produto
        FROM pedidos 
        WHERE order_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$orderId]);
    $pedido = $stmt->fetch();
    
    if (!$pedido) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Pedido não encontrado'
        ]);
        logEvento('ORDER_NOT_FOUND', 'OrderID não existe no banco', ['orderId' => $orderId]);
        exit;
    }
    
    if ($pedido['status'] === 'confirmado') {
        $pdo->rollBack();
        echo json_encode([
            'pago' => true,
            'mensagem' => 'Pedido já confirmado anteriormente',
            'status' => 'confirmado'
        ]);
        logEvento('ALREADY_CONFIRMED', 'Tentativa de confirmar pedido já confirmado', [
            'orderId' => $orderId
        ]);
        exit;
    }
    
    if ($pedido['status'] !== 'pendente') {
        $pdo->rollBack();
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Pedido em status inválido para confirmação',
            'status_atual' => $pedido['status']
        ]);
        exit;
    }
    
    // ========================================
    // 6. CONSULTA API PAYNCH
    // ========================================
    
    $contract = $_GET['contract'] ?? $_GET['shop'] ?? '';
    
    if (empty($contract)) {
        $contract = '0x3da016f27CFEA6C054889E5d793Ae0f1c67c7645'; // SEU CONTRATO GERADO NO DASHBOARD https://pay.paynch.io/
    }
    
    if (!validarContrato($contract)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Contrato inválido'
        ]);
        logEvento('VALIDATION_ERROR', 'Contrato com formato inválido', ['contract' => $contract]);
        exit;
    }
    
    $paynchData = consultarPaynchApi($contract, $orderId);
    
    if ($paynchData === false) {
        $pdo->rollBack();
        http_response_code(503);
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Erro ao consultar blockchain. Tente novamente em alguns segundos.'
        ]);
        exit;
    }
    
    // ========================================
    // 7. VALIDA RESPOSTA DA API
    // ========================================
    
    if (!isset($paynchData['success']) || !$paynchData['success']) {
        $pdo->rollBack();
        echo json_encode([
            'pago' => false,
            'mensagem' => 'API retornou erro',
            'detalhes' => $paynchData['message'] ?? 'Erro desconhecido'
        ]);
        logEvento('API_ERROR', 'API Paynch retornou success=false', $paynchData);
        exit;
    }
    
    if (!isset($paynchData['count']) || $paynchData['count'] == 0) {
        $pdo->rollBack();
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Pagamento ainda não detectado na blockchain',
            'debug_info' => [
                'contract' => $contract,
                'orderId' => $orderId,
                'api_response' => $paynchData
            ]
        ]);
        logEvento('NO_PAYMENT', 'Nenhum pagamento encontrado', [
            'orderId' => $orderId,
            'api_response' => $paynchData
        ]);
        exit;
    }
    
    if (!isset($paynchData['payments']) || !is_array($paynchData['payments'])) {
        $pdo->rollBack();
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Resposta da API em formato inválido'
        ]);
        logEvento('API_ERROR', 'Campo payments ausente ou inválido', $paynchData);
        exit;
    }
    
    // ========================================
    // 8. PEGA PAGAMENTO MAIS RECENTE
    // ========================================
    
    usort($paynchData['payments'], function($a, $b) {
        return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
    });
    
    $pagamento = $paynchData['payments'][0];
    
    // CORREÇÃO: Campo é 'customer', não 'payer'
    if (!isset($pagamento['orderId'], $pagamento['amountHuman'], $pagamento['code'], $pagamento['customer'])) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Dados do pagamento incompletos',
            'campos_recebidos' => array_keys($pagamento)
        ]);
        logEvento('API_ERROR', 'Pagamento com campos faltando', $pagamento);
        exit;
    }
    
    if ($pagamento['orderId'] !== $orderId) {
        $pdo->rollBack();
        echo json_encode([
            'pago' => false,
            'mensagem' => 'OrderID do pagamento não corresponde ao pedido',
            'esperado' => $orderId,
            'recebido' => $pagamento['orderId']
        ]);
        logEvento('ORDER_MISMATCH', 'OrderID não bate', [
            'esperado' => $orderId,
            'recebido' => $pagamento['orderId']
        ]);
        exit;
    }
    
    // ========================================
    // 9. VALIDA VALOR PAGO
    // ========================================
    
    $valorEsperado = floatval($pedido['valor_usdt']);
    $valorRecebido = floatval($pagamento['amountHuman']);
    
    $toleranciaEmValor = $valorEsperado * (TOLERANCIA_PERCENTUAL / 100);
    $diferenca = $valorEsperado - $valorRecebido;
    
    if ($valorRecebido < ($valorEsperado - $toleranciaEmValor)) {
        $pdo->rollBack();
        
        echo json_encode([
            'pago' => false,
            'mensagem' => 'Valor pago é insuficiente (considerando taxas da plataforma)',
            'valor_esperado' => $valorEsperado,
            'valor_recebido' => $valorRecebido,
            'valor_minimo_aceito' => round($valorEsperado - $toleranciaEmValor, 2),
            'diferenca' => round($diferenca, 4),
            'percentual_diferenca' => round(($diferenca / $valorEsperado) * 100, 2) . '%'
        ]);
        
        logEvento('VALOR_INSUFICIENTE', 'Cliente pagou menos que o mínimo aceitável', [
            'orderId' => $orderId,
            'esperado' => $valorEsperado,
            'recebido' => $valorRecebido,
            'minimo_aceito' => $valorEsperado - $toleranciaEmValor,
            'diferenca_percentual' => ($diferenca / $valorEsperado) * 100,
            'tx' => $pagamento['code']
        ]);
        exit;
    }
    
    if ($valorRecebido > $valorEsperado) {
        logEvento('VALOR_EXCEDENTE', 'Cliente pagou mais que o esperado', [
            'orderId' => $orderId,
            'esperado' => $valorEsperado,
            'recebido' => $valorRecebido,
            'diferenca' => -$diferenca,
            'tx' => $pagamento['code']
        ]);
    }
    
    // ========================================
    // 10. ATUALIZA PEDIDO COMO CONFIRMADO
    // ========================================
    
    $txCode = substr($pagamento['code'], 0, 100);
    $customer = substr($pagamento['customer'], 0, 50); // CORRIGIDO: customer ao invés de payer
    
    $updateStmt = $pdo->prepare("
        UPDATE pedidos 
        SET 
            status = 'confirmado',
            tx_code = ?,
            payer = ?,
            pago_em = NOW(),
            amount_recebido = ?
        WHERE id = ? AND status = 'pendente'
    ");
    
    $updateStmt->execute([
        $txCode,
        $customer,
        $valorRecebido,
        $pedido['id']
    ]);
    
    $linhasAfetadas = $updateStmt->rowCount();
    
    if ($linhasAfetadas === 0) {
        $pdo->rollBack();
        echo json_encode([
            'pago' => true,
            'mensagem' => 'Pedido já foi confirmado por outra requisição'
        ]);
        exit;
    }
    
    $pdo->commit();
    
    if (isset($_SESSION['current_order_id']) && $_SESSION['current_order_id'] === $orderId) {
        unset($_SESSION['current_order_id']);
    }
    
    // ========================================
    // 11. LOG DE SUCESSO
    // ========================================
    
    logEvento('PAGAMENTO_CONFIRMADO', 'Pedido confirmado com sucesso', [
        'orderId' => $orderId,
        'produto' => $pedido['produto'],
        'valor_esperado' => $valorEsperado,
        'valor_recebido' => $valorRecebido,
        'tx_code' => $txCode,
        'customer' => $customer
    ]);
    
    // ========================================
    // 12. RESPOSTA DE SUCESSO
    // ========================================
    
    echo json_encode([
        'pago' => true,
        'mensagem' => 'Pagamento confirmado e pedido atualizado com sucesso!',
        'dados' => [
            'order_id' => $orderId,
            'produto' => $pedido['produto'],
            'valor_esperado' => $valorEsperado,
            'valor_recebido' => $valorRecebido,
            'diferenca' => round($diferenca, 4),
            'diferenca_percentual' => round(($diferenca / $valorEsperado) * 100, 2) . '%',
            'taxa_plataforma' => round($diferenca, 4),
            'tx_code' => $txCode,
            'customer' => $customer,
            'confirmado_em' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvento('DB_ERROR', 'Erro de banco de dados', [
        'erro' => $e->getMessage(),
        'orderId' => $orderId ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'pago' => false,
        'mensagem' => 'Erro interno ao processar pagamento. Tente novamente.'
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvento('ERROR', 'Erro genérico', [
        'erro' => $e->getMessage(),
        'orderId' => $orderId ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'pago' => false,
        'mensagem' => 'Erro inesperado. Contate o suporte.'
    ]);
}