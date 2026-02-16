<?php


require_once 'config.php';

// Contrato gerado no banco de dados https://pay.paynch.io/
$contractPadrao = getenv('SHOP_CONTRACT') ?: '0x3da016f27CFEA6C054889E5d793Ae0f1c67c7645';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Loja Online - Pagamentos em USDT via Blockchain">
  <title>Loja Simulada - Pagamento USDT</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
    }
    
    header {
      text-align: center;
      padding: 40px 20px;
      background: white;
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      margin-bottom: 40px;
    }
    
    h1 {
      color: #4f46e5;
      font-size: 2.5em;
      margin-bottom: 10px;
    }
    
    .subtitle {
      color: #6b7280;
      font-size: 1.1em;
    }
    
    .badge {
      display: inline-block;
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      padding: 6px 16px;
      border-radius: 20px;
      font-size: 0.9em;
      margin-top: 10px;
      font-weight: 600;
    }
    
    .produtos-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 24px;
      margin-bottom: 40px;
    }
    
    .produto {
      border: 1px solid #e5e7eb;
      padding: 24px;
      border-radius: 12px;
      background: white;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }
    
    .produto:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    }
    
    .produto::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, #4f46e5, #10b981);
    }
    
    .produto h3 {
      color: #1f2937;
      font-size: 1.5em;
      margin-bottom: 12px;
    }
    
    .produto-descricao {
      color: #6b7280;
      margin-bottom: 16px;
      line-height: 1.6;
    }
    
    .preco {
      font-size: 2em;
      color: #10b981;
      font-weight: bold;
      margin: 16px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .preco-label {
      font-size: 0.5em;
      color: #6b7280;
      font-weight: normal;
    }
    
    .btn {
      display: inline-block;
      padding: 14px 28px;
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 1.1em;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      width: 100%;
      text-align: center;
    }
    
    .btn:hover {
      transform: scale(1.02);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }
    
    .btn:active {
      transform: scale(0.98);
    }
    
    footer {
      text-align: center;
      padding: 20px;
      color: #6b7280;
      font-size: 0.9em;
    }
    
    .info-box {
      background: #f0f9ff;
      border: 1px solid #bfdbfe;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 30px;
      display: flex;
      align-items: center;
      gap: 16px;
    }
    
    .info-box-icon {
      font-size: 2em;
      min-width: 50px;
    }
    
    .info-box-content {
      flex: 1;
    }
    
    .info-box h4 {
      color: #1e40af;
      margin-bottom: 8px;
    }
    
    .info-box p {
      color: #1e3a8a;
      line-height: 1.6;
      margin: 0;
    }
    
    @media (max-width: 768px) {
      h1 {
        font-size: 1.8em;
      }
      
      .produtos-grid {
        grid-template-columns: 1fr;
      }
      
      .info-box {
        flex-direction: column;
        text-align: center;
      }
    }
  </style>
</head>
<body>
  <header>
    <h1>🛒 Loja Crypto</h1>
    <p class="subtitle">Pagamentos instantâneos com USDT</p>
    <span class="badge">✓ 100% Descentralizado • 0% Taxas</span>
  </header>

  <div class="info-box">
    <div class="info-box-icon">💳</div>
    <div class="info-box-content">
      <h4>Como funciona?</h4>
      <p>Escolha seu produto, conecte sua carteira Web3 (MetaMask, Trust Wallet, etc) e pague com USDT na rede BSC. Rápido, seguro e sem intermediários!</p>
    </div>
  </div>

  <div class="produtos-grid">
    <!-- Produto 1 -->
    <div class="produto">
      <h3>👕 Camiseta Básica</h3>
      <p class="produto-descricao">
        Camiseta 100% algodão, confortável e durável. 
        Perfeita para o dia a dia.
      </p>
      <div class="preco">
        <span class="preco-label">Apenas</span>
        9.90 USDT
      </div>
      <a href="checkout.php?produto=<?= urlencode('Camiseta Básica') ?>&amount=9.90&shop=<?= urlencode($contractPadrao) ?>" class="btn">
        🚀 Comprar Agora
      </a>
    </div>

    <!-- Produto 2 -->
    <div class="produto">
      <h3>📚 Curso Online</h3>
      <p class="produto-descricao">
        Aprenda desenvolvimento web moderno com React, Node.js e muito mais. 
        Acesso vitalício incluído.
      </p>
      <div class="preco">
        <span class="preco-label">Apenas</span>
        49.90 USDT
      </div>
      <a href="checkout.php?produto=<?= urlencode('Curso Online') ?>&amount=49.90&shop=<?= urlencode($contractPadrao) ?>" class="btn">
        🚀 Comprar Agora
      </a>
    </div>

    <!-- Produto 3 -->
    <div class="produto">
      <h3>📱 App Premium</h3>
      <p class="produto-descricao">
        Licença vitalícia do nosso aplicativo premium. 
        Sem mensalidades, pague uma vez e use para sempre.
      </p>
      <div class="preco">
        <span class="preco-label">Apenas</span>
        29.90 USDT
      </div>
      <a href="checkout.php?produto=<?= urlencode('App Premium') ?>&amount=29.90&shop=<?= urlencode($contractPadrao) ?>" class="btn">
        🚀 Comprar Agora
      </a>
    </div>

    <!-- Produto 4 -->
    <div class="produto">
      <h3>🎮 Game Pass Anual</h3>
      <p class="produto-descricao">
        Acesso ilimitado a mais de 100 jogos por um ano inteiro. 
        Novos jogos adicionados mensalmente.
      </p>
      <div class="preco">
        <span class="preco-label">Apenas</span>
        99.90 USDT
      </div>
      <a href="checkout.php?produto=<?= urlencode('Game Pass Anual') ?>&amount=99.90&shop=<?= urlencode($contractPadrao) ?>" class="btn">
        🚀 Comprar Agora
      </a>
    </div>

    <!-- Produto 5 -->
    <div class="produto">
      <h3>☕ Assinatura Café</h3>
      <p class="produto-descricao">
        Receba 1kg de café premium por mês durante 3 meses. 
        Grãos selecionados e torrados na hora.
      </p>
      <div class="preco">
        <span class="preco-label">Apenas</span>
        19.90 USDT
      </div>
      <a href="checkout.php?produto=<?= urlencode('Assinatura Café') ?>&amount=19.90&shop=<?= urlencode($contractPadrao) ?>" class="btn">
        🚀 Comprar Agora
      </a>
    </div>

    <!-- Produto 6 -->
    <div class="produto">
      <h3>🎨 Design Templates</h3>
      <p class="produto-descricao">
        Pack com +500 templates profissionais para Canva, Figma e Photoshop. 
        Uso comercial incluído.
      </p>
      <div class="preco">
        <span class="preco-label">Apenas</span>
        39.90 USDT
      </div>
      <a href="checkout.php?produto=<?= urlencode('Design Templates') ?>&amount=39.90&shop=<?= urlencode($contractPadrao) ?>" class="btn">
        🚀 Comprar Agora
      </a>
    </div>
  </div>

  <footer>
    <p>
      <strong>🔒 Pagamentos 100% Seguros via Blockchain</strong><br>
      Seus USDT vão diretamente para nossa carteira, sem intermediários.<br>
      Dúvidas? Entre em contato: suporte@loja.com
    </p>
    <p style="margin-top: 16px; font-size: 0.85em; color: #9ca3af;">
      Powered by <strong style="color: #4f46e5;">Paynch</strong> - Gateway de Pagamento Descentralizado
    </p>
  </footer>

  <script>
    // Log para debug
    console.log('Loja carregada');
    console.log('Contrato: <?= $contractPadrao ?>');
    
    // Verifica se MetaMask está instalado
    if (typeof window.ethereum !== 'undefined') {
      console.log('✓ MetaMask detectado');
    } else {
      console.warn('⚠️ MetaMask não detectado - usuário precisará instalar');
    }
  </script>
</body>
</html>
