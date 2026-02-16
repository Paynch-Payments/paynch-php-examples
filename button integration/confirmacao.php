<?php
session_start();

header('Content-Type: text/html; charset=utf-8');

// ================================================
//            CONFIGURAÇÕES IMPORTANTES
// ================================================

define('VALOR_TOLERANCIA_PERCENT', 1.1);   // aceitar até 1.1% a menos (taxas) somente para quem NÃO tem plano PREMIUM
define('CONTRACT_LOJA', '0x3da016f27CFEA6C054889E5d793Ae0f1c67c7645'); // contrato gerado no banco de dados https://pay.paynch.io/

// ================================================
//               CONEXÃO COM BANCO
// ================================================

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=Nome-do-banco;charset=utf8mb4",
        "Seu username",
        "Senha do banco",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log("Erro de conexão: " . $e->getMessage());
    http_response_code(500);
    die("Erro ao conectar ao banco de dados.");
}

// ================================================
//               RECEBE DADOS
// ================================================

$orderId = $_GET['orderId'] ?? null;

if (!$orderId || strlen($orderId) < 6) {
    http_response_code(400);
    die("Order ID inválido.");
}

// ================================================
//      1. Consulta o pedido no BANCO DE DADOS
// ================================================

try {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            order_id,
            produto,
            valor_usdt,
            status,
            tx_code,
            payer,
            amount_recebido,
            criado_em,
            pago_em
        FROM pedidos 
        WHERE order_id = ?
          AND contract_loja = ?
        LIMIT 1
    ");

    $stmt->execute([$orderId, CONTRACT_LOJA]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        http_response_code(404);
        die("Pedido não encontrado.");
    }
} catch (PDOException $e) {
    error_log("Erro ao consultar pedido: " . $e->getMessage());
    http_response_code(500);
    die("Erro ao consultar pedido.");
}

$valorEsperado   = (float) $pedido['valor_usdt'];
$statusAtual     = $pedido['status'];

// Já confirmado anteriormente
if ($statusAtual === 'confirmado') {
    $jaEstavaConfirmado = true;
} else {
    $jaEstavaConfirmado = false;
}

// ================================================
//      2. Consulta status na API Paynch
// ================================================

$apiUrl = "https://api.paynch.io/paynch.php?contract=" . urlencode(CONTRACT_LOJA) . "&orderId=" . urlencode($orderId);

$response = @file_get_contents($apiUrl);

if ($response === false) {
    $mensagemStatus = "Error API Paynch";
    $classeStatus   = "pending";
    goto exibir_pagina;
}

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Erro ao decodificar JSON da API Paynch: " . json_last_error_msg());
    $mensagemStatus = "Erro ao processar resposta da API";
    $classeStatus   = "error";
    goto exibir_pagina;
}

if (!$data || !isset($data['success']) || $data['success'] !== true || empty($data['payments'])) {
    $mensagemStatus = "Pagamento ainda não detectado";
    $classeStatus   = "pending";
    goto exibir_pagina;
}

// Pega o último pagamento
$ultimoPgto = end($data['payments']);

$valorRecebidoRaw = $ultimoPgto['amount'] ?? $ultimoPgto['amountHuman'] ?? '0';
$valorRecebido = (float) str_replace(',', '.', $valorRecebidoRaw);

if ($valorRecebido > 1000000000000) {
    $valorRecebido = $valorRecebido / 1e18;
}

$valorRecebidoFormatado = number_format($valorRecebido, 2, ',', '.');
$txCode          = $ultimoPgto['code']           ?? null;
$payer           = $ultimoPgto['customer']       ?? $ultimoPgto['payer'] ?? null;
$timestampFormat = $ultimoPgto['timestampFormatted'] ?? date('c');

// Verifica se o valor está dentro da tolerância
$valorMinimoAceitavel = $valorEsperado * (1 - VALOR_TOLERANCIA_PERCENT / 100);

if ($valorRecebido < $valorMinimoAceitavel) {
    $mensagemStatus = "Valor recebido insuficiente";
    $classeStatus   = "pending";
    goto exibir_pagina;
}

// ================================================
//      3. Se chegou aqui → pagamento VÁLIDO
// ================================================

if ($jaEstavaConfirmado) {
    $mensagemStatus = "Pagamento confirmado";
    $classeStatus   = "success";
} else {
    try {
        $pdo->beginTransaction();

        // CORRIGIDO: Usa order_id e contract_loja no WHERE em vez de id
        $stmtUpdate = $pdo->prepare("
            UPDATE pedidos SET
                status          = 'confirmado',
                tx_code         = ?,
                payer           = ?,
                amount_recebido = ?,
                pago_em         = NOW()
            WHERE order_id = ?
              AND contract_loja = ?
              AND status = 'pendente'
            LIMIT 1
        ");

        $stmtUpdate->execute([
            $txCode,
            $payer,
            $valorRecebido,
            $orderId,
            CONTRACT_LOJA
        ]);

        if ($stmtUpdate->rowCount() !== 1) {
            throw new Exception("Falha ao atualizar pedido (rows affected: " . $stmtUpdate->rowCount() . ")");
        }

        $pdo->commit();

        $mensagemStatus = "Pagamento confirmado com sucesso";
        $classeStatus   = "success";

        // Limpa a sessão
        if (isset($_SESSION['pending_paynch_order']) && 
            $_SESSION['pending_paynch_order']['order_id'] === $orderId) {
            unset($_SESSION['pending_paynch_order']);
        }

        // Aqui você pode:
        // - enviar e-mail de confirmação
        // - liberar acesso à área de membros
        // - etc.

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Erro ao confirmar pedido $orderId: " . $e->getMessage());
        $mensagemStatus = "Erro ao processar confirmação";
        $classeStatus   = "error";
    }
}

exibir_pagina:
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status do Pedido #<?= htmlspecialchars($orderId) ?></title>
  <style>
    body {
      font-family: system-ui, sans-serif;
      max-width: 640px;
      margin: 40px auto;
      padding: 20px;
      line-height: 1.6;
      color: #1f2937;
    }
    h1, h2 { text-align: center; }
    .box {
      padding: 24px;
      border-radius: 12px;
      margin: 24px 0;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
    }
    .success  { background: #ecfdf5; border-color: #10b981; color: #065f46; }
    .pending  { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
    .error    { background: #fef2f2; border-color: #ef4444; color: #991b1b; }
    .info     { font-size: 0.95rem; color: #4b5563; }
    .highlight { font-weight: bold; color: #1e40af; }
    .mono     { font-family: ui-monospace, monospace; word-break: break-all; }
  </style>
</head>
<body>

<h1>Status do Pedido</h1>
<h2>#<?= htmlspecialchars($orderId) ?></h2>

<div class="box <?= $classeStatus ?>">
  <h3>
    <?php if ($classeStatus === 'success'): ?>
      ✅ <?= htmlspecialchars($mensagemStatus) ?>
    <?php elseif ($classeStatus === 'pending'): ?>
      ⏳ <?= htmlspecialchars($mensagemStatus) ?>
    <?php else: ?>
      ⚠️ <?= htmlspecialchars($mensagemStatus) ?>
    <?php endif; ?>
  </h3>

  <div class="info">
    <p><strong>Produto:</strong> <?= htmlspecialchars($pedido['produto'] ?? '—') ?></p>
    <p><strong>Valor esperado:</strong> <?= number_format($valorEsperado, 2, ',', '.') ?> USDT</p>

    <?php if ($classeStatus === 'success'): ?>
      <p><strong>Valor recebido:</strong> <span class="highlight"><?= $valorRecebidoFormatado ?> USDT</span></p>
      <p><strong>Tx Code:</strong> <span class="mono"><?= htmlspecialchars($txCode ?? '—') ?></span></p>
      <p><strong>Pagador:</strong> <span class="mono"><?= htmlspecialchars($payer ?? '—') ?></span></p>
      <p><strong>Data:</strong> <?= htmlspecialchars($timestampFormat ?? '—') ?></p>
      <br>
      <strong style="color:#10b981">✅ Produto liberado! Verifique sua área de membros ou e-mail.</strong>
    <?php endif; ?>
  </div>
</div>

<?php if ($classeStatus === 'pending'): ?>
  <p style="text-align:center; color:#666; font-size:0.9rem;">
    Atualizando automaticamente em <span id="contagem">5</span> segundos...
  </p>

  <script>
    let seg = 5;
    const el = document.getElementById('contagem');
    const timer = setInterval(() => {
      seg--;
      el.textContent = seg;
      if (seg <= 0) {
        clearInterval(timer);
        location.reload();
      }
    }, 1000);
  </script>
<?php endif; ?>

</body>
</html>
