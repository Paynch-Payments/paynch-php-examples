<?php

require_once 'config.php';

// ========================================
// 1. VALIDAÇÃO DE PARÂMETROS
// ========================================

$produto = $_GET['produto'] ?? 'Produto desconhecido';
$produto = sanitizarString($produto, 255);

$valor = floatval($_GET['amount'] ?? 0);



if (!validarValor($valor)) {
    die('<h1>Erro</h1><p>Valor inválido. Deve ser maior que 0 e menor que 1.000.000 USDT.</p><a href="index.php">Voltar</a>');
}

$contract = $_GET['shop'] ?? '';

if (!validarContrato($contract)) {
    die('<h1>Erro</h1><p>Contrato de loja inválido.</p><a href="index.php">Voltar</a>');
}

// ========================================
// 2. GERAÇÃO DE ORDER ID
// ========================================

function generateOrderId($length = 12) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

// ========================================
// 3. VERIFICA SE JÁ EXISTE PEDIDO NA SESSÃO
// ========================================


$orderId = null;
$pedidoExistente = false;

// Verifica se existe order_id na sessão E se ainda está pendente
if (isset($_SESSION['current_order_id'])) {
    $orderIdSessao = $_SESSION['current_order_id'];
    
    try {
        // CORRIGIDO: Verifica se o pedido é EXATAMENTE o mesmo (produto + valor)
        $stmt = $pdo->prepare("
            SELECT order_id, status, criado_em, produto, valor_usdt
            FROM pedidos 
            WHERE order_id = ? 
            AND status = 'pendente'
            AND produto = ?
            AND ABS(valor_usdt - ?) < 0.01
            AND criado_em > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ");
        $stmt->execute([$orderIdSessao, $produto, $valor]);
        $pedidoCheck = $stmt->fetch();
        
        if ($pedidoCheck) {
            // Pedido existe e é EXATAMENTE o mesmo produto/valor
            $orderId = $orderIdSessao;
            $pedidoExistente = true;
            
            logEvento('PEDIDO_REUTILIZADO', 'Pedido existente reutilizado', [
                'orderId' => $orderId,
                'produto' => $produto,
                'valor' => $valor
            ]);
        } else {
            // Produto ou valor mudou - limpa sessão e cria novo
            unset($_SESSION['current_order_id']);
            
            logEvento('SESSAO_LIMPA', 'Produto/valor mudou, criando novo pedido', [
                'orderIdAntigo' => $orderIdSessao,
                'produtoNovo' => $produto,
                'valorNovo' => $valor
            ]);
        }
    } catch (PDOException $e) {
        // Se der erro, ignora e cria novo pedido
        logEvento('DB_ERROR', 'Erro ao verificar pedido existente', [
            'erro' => $e->getMessage()
        ]);
        unset($_SESSION['current_order_id']);
    }
}
// Se não encontrou pedido válido, cria um novo
if (!$orderId) {
    $orderId = generateOrderId();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pedidos (
                order_id, 
                produto, 
                valor_usdt, 
                status,
                contract_loja,
                ip_cliente,
                user_agent,
                criado_em
            ) VALUES (?, ?, ?, 'pendente', ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $orderId,
            $produto,
            $valor,
            $contract,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Salva na sessão para reutilizar em reloads
        $_SESSION['current_order_id'] = $orderId;
        
        logEvento('PEDIDO_CRIADO', 'Novo pedido criado', [
            'orderId' => $orderId,
            'produto' => $produto,
            'valor' => $valor,
            'contract' => $contract
        ]);
        
    } catch (PDOException $e) {
        logEvento('DB_ERROR', 'Erro ao criar pedido', [
            'erro' => $e->getMessage()
        ]);
        die('<h1>Erro</h1><p>Não foi possível criar o pedido. Tente novamente.</p><a href="index.php">Voltar</a>');
    }
}

// Gera token CSRF para segurança
$csrfToken = gerarCsrfToken();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Checkout - <?= htmlspecialchars($produto, ENT_QUOTES, 'UTF-8') ?></title>
  
  <style>
    :root {
      --primary: #4f46e5;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --bg-light: #f9fafb;
      --text-dark: #1f2937;
      --text-muted: #6b7280;
    }
    
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body { 
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      max-width: 650px; 
      margin: 40px auto; 
      text-align: center; 
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      padding: 20px;
    }
    
    .box { 
      background: white; 
      padding: 40px; 
      border-radius: 16px; 
      box-shadow: 0 10px 40px rgba(0,0,0,0.2); 
    }
    
    h1 { 
      color: var(--primary);
      margin-bottom: 10px;
      font-size: 1.8em;
    }
    
    .info { 
      font-size: 18px; 
      margin: 24px 0;
      padding: 20px;
      background: #f0f4ff;
      border-radius: 12px;
      border-left: 4px solid var(--primary);
    }
    
    .info strong {
      color: var(--primary);
      font-size: 1.3em;
    }
    
    #pay-status { 
      min-height: 120px; 
      padding: 24px; 
      background: var(--bg-light);
      border-radius: 12px; 
      margin: 24px 0; 
      font-weight: 500; 
      font-size: 16px; 
      line-height: 1.6;
      border: 2px dashed #d1d5db;
    }
    
    button { 
      padding: 16px 32px; 
      font-size: 18px; 
      margin: 10px; 
      border: none; 
      border-radius: 10px; 
      cursor: pointer; 
      color: white; 
      transition: all 0.3s ease;
      font-weight: 600;
      box-shadow: 0 4px 14px rgba(0,0,0,0.1);
    }
    
    button:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    }
    
    button:active:not(:disabled) {
      transform: translateY(0);
    }
    
    button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      transform: none !important;
    }
    
    #connect-wallet { 
      background: linear-gradient(135deg, #6366f1, #4f46e5);
      min-width: 200px;
    }
    
    #connect-wallet.connected { 
      background: linear-gradient(135deg, #10b981, #059669);
    }
    
    #pay-button { 
      background: linear-gradient(135deg, #10b981, #059669);
      min-width: 200px;
    }
    
    #verificar-btn { 
      background: linear-gradient(135deg, #f59e0b, #d97706);
      font-size: 16px;
    }
    
    .loading-spinner {
      display: inline-block;
      width: 24px;
      height: 24px;
      border: 3px solid #f3f3f3;
      border-top: 3px solid var(--primary);
      border-radius: 50%;
      animation: spin 1s linear infinite;
      vertical-align: middle;
      margin-right: 10px;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    .alert {
      padding: 16px;
      border-radius: 8px;
      margin: 20px 0;
      text-align: left;
    }
    
    .alert-info {
      background: #dbeafe;
      border-left: 4px solid #3b82f6;
      color: #1e40af;
    }
    
    .alert-success {
      background: #d1fae5;
      border-left: 4px solid #10b981;
      color: #065f46;
    }
    
    .alert-warning {
      background: #fef3c7;
      border-left: 4px solid #f59e0b;
      color: #92400e;
    }
    
    .badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 0.85em;
      font-weight: 600;
      margin: 0 4px;
    }
    
    .badge-network {
      background: #fbbf24;
      color: #78350f;
    }
    
    .steps {
      text-align: left;
      margin: 20px 0;
      padding: 20px;
      background: #f9fafb;
      border-radius: 8px;
    }
    
    .steps h3 {
      color: var(--primary);
      margin-bottom: 16px;
      font-size: 1.1em;
    }
    
    .steps ol {
      margin-left: 20px;
      line-height: 1.8;
      color: var(--text-dark);
    }
    
    .steps li {
      margin: 8px 0;
    }
    
    @media (max-width: 600px) {
      body {
        padding: 10px;
        margin: 20px auto;
      }
      
      .box {
        padding: 24px;
      }
      
      h1 {
        font-size: 1.4em;
      }
      
      button {
        width: 100%;
        margin: 8px 0;
      }
    }
  </style>

  <!-- Bibliotecas Web3 -->

<!-- jQuery -->
<script type="module">
  window.process = { browser: true, env: { ENVIRONMENT: 'BROWSER' } };
</script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Web3.js v4 -->
<script src="https://unpkg.com/web3@4.0.1/dist/web3.min.js"></script>

<!-- Paynch Script  -->
<script type="module" src="https://pay.paynch.io/js/paynch-connect-en.js"></script>


</head>
<body>

  <div class="box">
    <h1>🛒 Finalizar Compra</h1>
    <p style="color: var(--text-muted); margin-top: 8px;">Pagamento via USDT (BSC)</p>
    
    <div class="info">
      <div style="margin-bottom: 12px;">
        <strong><?= htmlspecialchars($produto, ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
      <div style="font-size: 2em; color: var(--success); font-weight: bold; margin: 12px 0;">
        <?= number_format($valor, 2, ',', '.') ?> USDT
      </div>
      <div style="font-size: 0.9em; color: var(--text-muted);">
        Order ID: <code style="background: #e5e7eb; padding: 4px 8px; border-radius: 4px; font-family: monospace;"><?= $orderId ?></code>
      </div>
      <div style="margin-top: 8px;">
        <span class="badge badge-network">🔗 Binance Smart Chain (BEP20)</span>
      </div>
    </div>

    <!-- Inputs hidden -->
    <input type="hidden" id="amount-input" value="<?= $valor ?>">
    <input type="hidden" id="order-id-input" value="<?= $orderId ?>">
    <input type="hidden" id="contract-input" value="<?= htmlspecialchars($contract, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" id="csrf-token" value="<?= $csrfToken ?>">

    <!-- Status de Pagamento -->
    <div id="pay-status">
      <div style="font-size: 1.1em; margin-bottom: 8px;">
        💳 Conecte sua carteira para continuar
      </div>
      <div style="font-size: 0.9em; color: var(--text-muted);">
        MetaMask, Trust Wallet, WalletConnect ou qualquer carteira BSC
      </div>
    </div>

    <!-- Botões -->
    <div style="margin: 24px 0;">
    <!-- Action buttons -->
    <button id="connect-wallet">🔌 Connect Wallet</button>
    <button id="pay-button">🚀 Pay <?= $valor ?> USDT</button>
    <button id="verificar-btn" style="display: none;">🔍 Verificar Pagamento</button>
    <!-- Payment status -->
    <div id="pay-status">
      💳 Connect your wallet to continue
    </div>
    </div>

    <!-- Como funciona -->
    <div class="steps">
      <h3>📋 Como funciona</h3>
      <ol>
        <li>Clique em <strong>"Conectar Carteira"</strong></li>
        <li>Aprove a conexão em sua carteira</li>
        <li>Clique em <strong>"Pagar"</strong></li>
        <li>Confirme a transação na sua carteira</li>
        <li>Aguarde a confirmação automática (15-30 segundos)</li>
      </ol>
    </div>

    <div class="alert alert-info" style="font-size: 0.9em;">
      <strong>ℹ️ Importante:</strong><br>
      • Certifique-se de estar na rede <strong>Binance Smart Chain (BSC)</strong><br>
      • Tenha USDT e BNB suficiente para taxa de rede (~$0.20)<br>
      • A verificação é <strong>automática</strong> após o pagamento
    </div>

    <p style="margin-top: 32px; color: var(--text-muted); font-size: 0.85em;">
      🔒 Pagamento 100% seguro via blockchain<br>
      Seus fundos vão diretamente para a carteira da loja
    </p>
  </div>

  <!-- Script da Paynch -->
  


  <!-- Configuração da URL -->
  <script>
    const url = new URL(window.location);
    let changed = false;

    const params = {
      shop: '<?= $contract ?>',
      orderId: '<?= $orderId ?>',
      amount: '<?= $valor ?>'
    };

    Object.keys(params).forEach(key => {
      if (!url.searchParams.has(key)) {
        url.searchParams.set(key, params[key]);
        changed = true;
      }
    });

    if (changed) {
      history.replaceState(null, '', url.toString());
    }
  </script>

  <!-- Sistema de Verificação Automática -->
  <script>
    // ========================================
    // CONFIGURAÇÕES
    // ========================================
    const CONFIG = {
      CONTRACT: '<?= $contract ?>',
      ORDER_ID: '<?= $orderId ?>',
      VALOR: <?= $valor ?>,
      CSRF_TOKEN: '<?= $csrfToken ?>',
      MAX_TENTATIVAS: <?= MAX_TENTATIVAS_AUTO ?>,
      INTERVALO_MS: <?= INTERVALO_VERIFICACAO_MS ?>,
      DELAY_INICIAL_MS: <?= DELAY_INICIAL_MS ?>
    };

    // ========================================
    // VARIÁVEIS GLOBAIS
    // ========================================
    let autoCheckInterval = null;
    let tentativasAuto = 0;
    let pagamentoConfirmado = false;

    // ========================================
    // FUNÇÃO PRINCIPAL DE VERIFICAÇÃO
    // ========================================
    async function verificarPagamento(options = {}) {
      const {
        isAuto = false,
        showLoading = true
      } = options;

      const statusEl = document.getElementById('pay-status');
      const btnVerificar = document.getElementById('verificar-btn');

      // Atualiza interface
      if (showLoading && isAuto) {
        statusEl.innerHTML = `
          <div class="loading-spinner"></div>
          <div style="font-size: 1.1em; margin-bottom: 8px;">
            Aguardando confirmação na blockchain...
          </div>
          <div style="font-size: 0.9em; color: var(--text-muted);">
            Tentativa ${tentativasAuto}/${CONFIG.MAX_TENTATIVAS}
          </div>
        `;
      } else if (showLoading) {
        statusEl.innerHTML = `
          <div class="loading-spinner"></div>
          <div>Verificando pagamento... Aguarde...</div>
        `;
        btnVerificar.disabled = true;
      }

      try {
        // Consulta API de atualização
        const formData = new FormData();
        formData.append('orderId', CONFIG.ORDER_ID);
        formData.append('contract', CONFIG.CONTRACT);

        const updateRes = await fetch('atualizar-status.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        if (!updateRes.ok) {
          throw new Error(`Erro HTTP ${updateRes.status}`);
        }

        const updateText = await updateRes.text();
        console.log('Resposta RAW:', updateText);

        let updateData;
        try {
          updateData = JSON.parse(updateText.trim());
        } catch (parseError) {
          console.error('Erro ao parsear JSON:', parseError);
          throw new Error('Resposta inválida do servidor');
        }

        console.log('Dados parseados:', updateData);

        // Verifica se foi confirmado
        if (updateData.pago) {
          pagamentoConfirmado = true;
          pararVerificacaoAutomatica();

          const dados = updateData.dados || {};
          
          statusEl.innerHTML = `
            <div style="color: var(--success); font-size: 1.2em; margin-bottom: 16px;">
              ✅ <strong>Pagamento Confirmado!</strong>
            </div>
            <div style="text-align: left; background: #d1fae5; padding: 16px; border-radius: 8px; font-size: 0.95em; line-height: 1.8;">
              <div><strong>💰 Valor:</strong> ${dados.valor_recebido || CONFIG.VALOR} USDT</div>
              <div><strong>📦 Produto:</strong> ${dados.produto || '<?= htmlspecialchars($produto) ?>'}</div>
              <div><strong>🔗 TX:</strong> <code style="font-size: 0.85em; word-break: break-all;">${dados.tx_code || 'N/A'}</code></div>
              <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #10b981;">
                ${isAuto ? '🤖 Confirmado automaticamente' : '👤 Confirmado manualmente'}
              </div>
            </div>
          `;

          btnVerificar.disabled = true;
          btnVerificar.textContent = '✅ Pagamento Confirmado';
          btnVerificar.style.background = 'linear-gradient(135deg, #10b981, #059669)';

          // Redirecionar ou mostrar próximos passos
          setTimeout(() => {
            if (confirm('Pagamento confirmado! Deseja voltar para a loja?')) {
              window.location.href = 'index.php';
            }
          }, 3000);

          return true;
        } else {
          // Pagamento ainda não detectado
          if (!isAuto) {
            statusEl.innerHTML = `
              <div style="color: var(--warning);">
                ⚠️ Pagamento ainda não detectado na blockchain.<br>
                <small style="color: var(--text-muted);">Aguarde alguns segundos e tente novamente.</small>
              </div>
            `;
            btnVerificar.disabled = false;
          }
          return false;
        }

      } catch (error) {
        console.error('Erro na verificação:', error);
        
        if (!isAuto) {
          statusEl.innerHTML = `
            <div style="color: var(--danger);">
              ❌ Erro: ${error.message}<br>
              <small>Verifique o console (F12) para mais detalhes</small>
            </div>
          `;
          btnVerificar.disabled = false;
        }
        
        return false;
      }
    }

    // ========================================
    // VERIFICAÇÃO AUTOMÁTICA
    // ========================================
    function iniciarVerificacaoAutomatica() {
      if (pagamentoConfirmado || autoCheckInterval) {
        return;
      }

      const statusEl = document.getElementById('pay-status');
      const btnVerificar = document.getElementById('verificar-btn');
      
      // Mostra botão de verificação manual
      btnVerificar.style.display = 'inline-block';
      
      statusEl.innerHTML = `
        <div class="loading-spinner"></div>
        <div style="font-size: 1.1em; margin-bottom: 8px;">
          Aguardando confirmação na blockchain...
        </div>
        <div style="font-size: 0.9em; color: var(--text-muted);">
          A verificação automática começará em instantes
        </div>
      `;

      setTimeout(() => {
        tentativasAuto = 0;
        
        autoCheckInterval = setInterval(async () => {
          tentativasAuto++;

          const confirmado = await verificarPagamento({ isAuto: true });

          if (confirmado) {
            pararVerificacaoAutomatica();
          } else if (tentativasAuto >= CONFIG.MAX_TENTATIVAS) {
            pararVerificacaoAutomatica();
            
            statusEl.innerHTML = `
              <div style="color: var(--warning);">
                ⏱️ Tempo limite de verificação automática atingido.<br>
                <strong>Use o botão "Verificar Pagamento" para continuar.</strong>
              </div>
            `;
          }
        }, CONFIG.INTERVALO_MS);

      }, CONFIG.DELAY_INICIAL_MS);
    }

    function pararVerificacaoAutomatica() {
      if (autoCheckInterval) {
        clearInterval(autoCheckInterval);
        autoCheckInterval = null;
        console.log('Verificação automática parada');
      }
    }

    // ========================================
    // BOTÃO MANUAL
    // ========================================
    document.getElementById('verificar-btn').addEventListener('click', async () => {
      pararVerificacaoAutomatica();
      await verificarPagamento({ isAuto: false, showLoading: true });
    });

    // ========================================
    // INTEGRAÇÃO COM BOTÃO DE PAGAMENTO
    // ========================================
    const originalPayButton = document.getElementById('pay-button');
    
    if (originalPayButton) {
      originalPayButton.addEventListener('click', () => {
        console.log('Botão de pagamento clicado');
        
        setTimeout(() => {
          if (!pagamentoConfirmado) {
            iniciarVerificacaoAutomatica();
          }
        }, 2000);
      });
    }

    // ========================================
    // LIMPEZA
    // ========================================
    window.addEventListener('beforeunload', () => {
      pararVerificacaoAutomatica();
    });

    // ========================================
    // LOG INICIAL
    // ========================================
    console.log('Sistema carregado');
    console.log('Config:', CONFIG);
  </script>
 

</body>
</html>