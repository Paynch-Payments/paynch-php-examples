<?php
/**
 * 
 * Este script implementa uma página de checkout simples para um produto digital,
 */

session_start();

// ================================================
//            CONEXÃO COM O BANCO (ajuste aqui)
// ================================================
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=seu-banco-de-dados;charset=utf8mb4",
        "Seu username",
        "Senha do banco",
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Em produção: mostrar página de erro amigável
    die("Erro de conexão com o banco de dados. Tente novamente mais tarde.");
}

// ================================================
//            DADOS DO PRODUTO (pode vir do banco de dados)
// ================================================
$produto = [
    'id'          => 1,
    'nome'        => 'Curso Completo de Marketing Digital',
    'descricao'   => 'Aprenda as estratégias mais atuais do mercado de forma prática e aplicada. Curso 100% online com mais de 120 aulas, suporte vitalício e atualizações constantes.',
    'preco'       => 97.00,
    'imagem'      => 'https://proofs.pancakeswap.com/cms/uploads/1a5fc97f080616edec01249bf67af442adc2be0c1aa9130d658a1f5c8f2e1549.png'
];

// ================================================
//         CONFIGURAÇÃO PAYNCH
// ================================================
$paynchConfig = [
    'contract'    => '0x3da016f27CFEA6C054889E5d793Ae0f1c67c7645', // contrato gerado no dashboard (https://pay.paynch.io/)
    'redirectUrl' => 'confirmacao.php'   // OPCIONAL, MAS É ALTAMENTE RECOMENDADO SE VOCÊ QUISER 
];


// ================================================
// CHECAGEM SIMPLES: O orderId DA URL AINDA É VÁLIDO pra evitar pagamento duplicado
// ================================================
$orderIdUrl = $_GET['orderId'] ?? null;

if ($orderIdUrl) {
    $stmt = $pdo->prepare("
        SELECT status 
        FROM pedidos 
        WHERE order_id = ? 
          AND contract_loja = ?
        LIMIT 1
    ");
    $stmt->execute([$orderIdUrl, $paynchConfig['contract']]);
    $row = $stmt->fetch();

    // Se o pedido da URL NÃO existe ou NÃO está pendente → redireciona com novo
    if (!$row || $row['status'] !== 'pendente') {
        
        // Gera novo ID único
        do {
            $novoOrderId = 'P' . date('ymd') . strtoupper(substr(md5(uniqid(true)), 0, 8));
            $check = $pdo->prepare("SELECT 1 FROM pedidos WHERE order_id = ? LIMIT 1");
            $check->execute([$novoOrderId]);
        } while ($check->fetch());

        // Cria o novo pedido pendente
        $stmtInsert = $pdo->prepare("
            INSERT INTO pedidos (
                order_id, produto, valor_usdt, status,
                contract_loja, criado_em, ip_cliente, user_agent
            ) VALUES (
                ?, ?, ?, 'pendente',
                ?, NOW(), ?, ?
            )
        ");
        $stmtInsert->execute([
            $novoOrderId,
            $produto['nome'],
            $produto['preco'],
            $paynchConfig['contract'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Atualiza a sessão com o novo (para reutilizar em refresh normal)
        $_SESSION['pending_paynch_order'] = [
            'order_id'     => $novoOrderId,
            'product_id'   => $produto['id'],
            'amount'       => $produto['preco'],
            'product_name' => $produto['nome'],
            'created_at'   => time(),
            'status'       => 'pending'
        ];

        // ================================================
        // REDIRECIONA COM O NOVO orderId NA URL
        // ================================================
        $params = [
            'shop'   => $paynchConfig['contract'],
            'orderId' => $novoOrderId,
            'amount'  => number_format($produto['preco'], 2, '.', '')
        ];

        $novaUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
        
        header("Location: $novaUrl");
        exit;
    }
}

// ================================================
//     LÓGICA PARA REUTILIZAR OU CRIAR NOVO PEDIDO
// ================================================

$orderId = null;
$pedidoJaCriado = false;

if (isset($_SESSION['pending_paynch_order'])) {
    $pendente = $_SESSION['pending_paynch_order'];

    if (
        isset($pendente['order_id'], $pendente['product_id'], $pendente['amount'], $pendente['created_at']) &&
        $pendente['product_id'] === $produto['id'] &&
        abs((float)$pendente['amount'] - $produto['preco']) < 0.01 &&
        (time() - $pendente['created_at']) < 7200  // 2 horas de validade
    ) {
        $orderId = $pendente['order_id'];

        // Verifica se ainda existe no banco e está pendente
        $stmt = $pdo->prepare("SELECT status FROM pedidos WHERE order_id = ? AND contract_loja = ?");
        $stmt->execute([$orderId, $paynchConfig['contract']]);
        $row = $stmt->fetch();

        if ($row && $row['status'] === 'pendente') {
            $pedidoJaCriado = true;
        } else {
            // Pedido foi pago/cancelado/expirado → força novo
            unset($_SESSION['pending_paynch_order']);
        }
    } else {
        unset($_SESSION['pending_paynch_order']);
    }
}

// ================================================
//         CRIAR NOVO PEDIDO (se necessário)
// ================================================
if (!$pedidoJaCriado) {
    $orderId = null;
    $tentativas = 0;
    $maxTentativas = 10; // segurança contra loop infinito (muito improvável)

    do {
        if ($tentativas++ >= $maxTentativas) {
            error_log("Falha após $maxTentativas tentativas de gerar order_id único");
            die("Erro interno: não foi possível gerar um ID único. Tente novamente.");
        }

        // Gera ID legível e único
        $orderId = 'P' . date('ymd') . strtoupper(substr(md5(uniqid(more_entropy: true)), 0, 8));

        // Verifica se já existe
        $stmtCheck = $pdo->prepare("SELECT 1 FROM pedidos WHERE order_id = ? LIMIT 1");
        $stmtCheck->execute([$orderId]);
        $existe = $stmtCheck->fetch() !== false;

    } while ($existe);

    // Agora $orderId é garantidamente único → prossegue com insert
    try {
        $stmtInsert = $pdo->prepare("
            INSERT INTO pedidos (
                order_id,
                produto,
                valor_usdt,
                status,
                contract_loja,
                criado_em,
                ip_cliente,
                user_agent
            ) VALUES (
                :order_id,
                :produto,
                :valor_usdt,
                'pendente',
                :contract,
                NOW(),
                :ip,
                :ua
            )
        ");

        $stmtInsert->execute([
            ':order_id'    => $orderId,
            ':produto'     => $produto['nome'],
            ':valor_usdt'  => $produto['preco'],
            ':contract'    => $paynchConfig['contract'],
            ':ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':ua'          => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        $pedidoJaCriado = true;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // duplicate entry — não deve mais acontecer
            // Por precaução: loga e tenta novamente (mas o loop acima já evita)
            error_log("Colisão inesperada no order_id: $orderId");
            // Aqui você poderia voltar pro loop, mas como o do-while já cuida disso, só loga
        } else {
            error_log("Erro ao inserir pedido: " . $e->getMessage());
            die("Não foi possível criar o pedido. Tente novamente.");
        }
    }

    // Salva na sessão
    $_SESSION['pending_paynch_order'] = [
        'order_id'    => $orderId,
        'product_id'  => $produto['id'],
        'amount'      => $produto['preco'],
        'product_name'=> $produto['nome'],
        'created_at'  => time(),
        'status'      => 'pending'
    ];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?= htmlspecialchars($produto['nome']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            color: #1f2937;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        @media (max-width: 768px) { .checkout-grid { grid-template-columns: 1fr; } }
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            padding: 30px;
        }
        .header { text-align: center; color: white; margin-bottom: 30px; }
        .header h1 { font-size: 2.5rem; margin-bottom: 0.5rem; }
        .header p { opacity: 0.9; font-size: 1.2rem; }
        h2 { margin-bottom: 1.2rem; font-size: 1.8rem; }
        .product-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        .price-section {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 12px;
            margin: 1.5rem 0;
        }
        .price-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            color: #4b5563;
        }
        .price-total {
            display: flex;
            justify-content: space-between;
            font-size: 1.8rem;
            font-weight: 700;
            color: #667eea;
            padding-top: 1rem;
            border-top: 2px solid #e5e7eb;
            margin-top: 0.8rem;
        }
        .order-id {
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 10px;
            font-family: monospace;
            font-size: 1.1rem;
            text-align: center;
            margin: 1.2rem 0;
            word-break: break-all;
            font-weight: bold;
        }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1.2rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            color: #92400e;
        }
        .info-box {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 1.2rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            color: #1e40af;
        }
        .success-box {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 1.2rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            color: #065f46;
        }
        .features { list-style: none; }
        .features li {
            padding: 0.6rem 0;
            display: flex;
            align-items: center;
        }
        .features li:before {
            content: "✓ ";
            color: #10b981;
            font-weight: bold;
            margin-right: 0.8rem;
        }
        .security-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        .badge {
            background: #f3f4f6;
            padding: 0.6rem 1.2rem;
            border-radius: 999px;
            font-size: 0.9rem;
            color: #4b5563;
        }
        .footer {
            text-align: center;
            color: white;
            margin-top: 3rem;
            opacity: 0.9;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🛒 Finalizar Compra</h1>
        <p>Pagamento seguro, rápido e direto na blockchain</p>
    </div>

    <div class="checkout-grid">

        <!-- Produto -->
        <div class="card product-info">
            <h2>Seu Pedido</h2>
            <img src="<?= htmlspecialchars($produto['imagem']) ?>" 
                 alt="<?= htmlspecialchars($produto['nome']) ?>" 
                 class="product-image">

            <div class="product-name"><?= htmlspecialchars($produto['nome']) ?></div>
            <div class="product-description"><?= nl2br(htmlspecialchars($produto['descricao'])) ?></div>

            <div class="price-section">
                <div class="price-row"><span>Subtotal:</span><span>R$ <?= number_format($produto['preco'], 2, ',', '.') ?></span></div>
                <div class="price-row"><span>Taxa de transação:</span><span>R$ 0,00</span></div>
                <div class="price-total">
                    <span>Total a pagar:</span>
                    <span><?= number_format($produto['preco'], 2, ',', '.') ?> USDT</span>
                </div>
            </div>

            <div class="success-box">
                <strong>✨ O que você recebe imediatamente após confirmação:</strong>
                <ul class="features">
                    <li>Acesso vitalício à área de membros</li>
                    <li>Todas as aulas + materiais atualizados</li>
                    <li>Certificado digital de conclusão</li>
                    <li>Suporte direto via comunidade e e-mail</li>
                    <li>Atualizações gratuitas para sempre</li>
                </ul>
            </div>

            <div class="info-box">
                <strong>🔒 Pagamento 100% seguro e descentralizado</strong><br>
                Transação processada diretamente na blockchain.<br>
                Não armazenamos chaves privadas nem dados sensíveis.
            </div>
        </div>

        <!-- Pagamento -->
        <div class="card order-summary">
            <h2>Finalizar Pagamento</h2>

            <div class="warning-box">
                <strong>⚠️ Atenção:</strong><br>
                Não recarregue esta página após iniciar o pagamento.<br>
                Guarde o ID do pedido para consultar o status se necessário.
            </div>

            <div class="order-id">
                <strong>ID do seu Pedido:</strong><br>
                <?= htmlspecialchars($orderId) ?>
            </div>

            <div class="info-box">
                <strong>Como pagar (3 passos rápidos):</strong><br><br>
                1️⃣ Conecte sua carteira (MetaMask, Trust Wallet, etc)<br>
                2️⃣ Clique em "Pagar com Paynch" e confirme<br>
                3️⃣ Aguarde ~15 segundos → você será redirecionado automaticamente
            </div>

            <!-- Botão Paynch -->
            <script src="https://pay.paynch.io/button/button-connect.js"
                    data-shop="<?= htmlspecialchars($paynchConfig['contract']) ?>"
                    data-amount="<?= number_format($produto['preco'], 2, '.', '') ?>"
                    data-order-id="<?= htmlspecialchars($orderId) ?>"
                    data-product-name="<?= htmlspecialchars($produto['nome']) ?>"
                    data-redirect="<?= $paynchConfig['redirectUrl'] ?>?orderId=<?= urlencode($orderId) ?>"
                    data-theme="light"
                    data-currency="USDT"
                    data-language="en">
            </script>

            <div class="security-badges">
                <div class="badge">🔐 SSL 256-bit</div>
                <div class="badge">⛓️ Blockchain</div>
                <div class="badge">✅ 100% On-chain</div>
                <div class="badge">🛡️ Sem intermediários</div>
            </div>
        </div>

    </div>

    <div class="footer">
        <p>Dúvidas? Entre em contato: suporte@sualoja.com</p>
        <p style="margin-top:8px;">
            Pagamentos processados via Paynch • © 2026
        </p>
    </div>
</div>

<script>
    console.log('Checkout iniciado', {
        orderId: '<?= addslashes($orderId) ?>',
        produto: '<?= addslashes($produto['nome']) ?>',
        valor: <?= $produto['preco'] ?>,
        timestamp: '<?= date('c') ?>'
    });
</script>

</body>
</html>
