# Paynch PHP Examples

Practical, secure PHP integration examples for **Paynch** – non-custodial crypto payments on EVM chains (BSC, Polygon, Arbitrum, Base).

Accept **USDT** (or custom ERC-20 tokens) 100% on-chain, no KYC, no custody of funds, with mandatory server-side validation.

**Core security features demonstrated:**
- Unique, collision-resistant `order_id` generation
- Session-based order reuse (prevents duplicates on refresh)
- PDO row locking (`FOR UPDATE`) to avoid race conditions
- Rate limiting on verification endpoint
- 1.2% tolerance on received amount (covers platform fees)
- CSRF protection, input sanitization, secure headers
- cURL-based API calls with timeout & SSL verification
- Detailed event logging (file-based)
- Automatic polling + manual verification fallback

**Critical rule:** **Always validate server-side** via `https://api.paynch.io/paynch.php`. Never trust frontend signals alone!

## Official Paynch Resources

- Dashboard (connect wallet): [https://pay.paynch.io](https://pay.paynch.io)
- How-to guide: [https://pay.paynch.io/how](https://pay.paynch.io/how)
- API docs (verification): [https://pay.paynch.io/api](https://pay.paynch.io/api)
- Button integration: [https://pay.paynch.io/botao](https://pay.paynch.io/botao)
- AI support: [https://pay.paynch.io/ai](https://pay.paynch.io/ai)

## Repository Structure

Two complete, production-ready integration approaches:
paynch-php-examples/
├── button integration/                    # Easy: Paynch Button embed + redirect confirmation
│   ├── checkout.php                       # Product page → generates order → embeds Paynch Button
│   └── confirmacao.php                    # Confirmation page (polling via reload + API check)
├── manual integration/                    # Advanced: Full control with Web3 connect + custom polling
│   ├── atualizar-status.php               # Secure JSON endpoint (verify payment, update DB)
│   ├── checkout.php                       # Custom checkout with Paynch JS SDK + auto/manual verify
│   ├── config.php                         # PDO connection, security helpers, constants, logging
│   └── index.php                          # Demo storefront with multiple products
├── exemplo banco de dados.sql             # SQL schema for pedidos table (common to both)
└── README.md


This structure keeps both integrations self-contained while sharing the database schema.


## Database Setup (Required for Both)

Import `exemplo banco de dados.sql` (or use this schema):

```sql
CREATE TABLE pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(32) UNIQUE NOT NULL,
    produto VARCHAR(255) NOT NULL,
    valor_usdt DECIMAL(18,6) NOT NULL,
    contract_loja VARCHAR(42) NOT NULL,
    status ENUM('pendente', 'confirmado', 'failed') DEFAULT 'pendente',
    tx_code VARCHAR(100) DEFAULT NULL,
    payer VARCHAR(50) DEFAULT NULL,
    amount_recebido DECIMAL(18,6) DEFAULT NULL,
    pago_em DATETIME DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_cliente VARCHAR(45) DEFAULT NULL,
    user_agent TEXT
);

CREATE INDEX idx_order_id ON pedidos(order_id);
CREATE INDEX idx_contract_status ON pedidos(contract_loja, status);
```
Integration Options
1. Button Integration (Quick & Recommended for Most Sites)
Folder: button integration/

checkout.php: Generates secure order_id, stores in DB & session, embeds Paynch Button via <script src="https://pay.paynch.io/button/button-connect.js">
Redirects to confirmacao.php?orderId=... on success
confirmacao.php: Polls itself (auto-reload every 5s), checks API, applies 1.1–1.2% tolerance, updates DB transactionally

Best for: Simple e-commerce, digital products, fast setup.
2. Manual / Custom Integration (Full Control & Web3 Experience)
Folder: manual integration/

index.php: Demo store → links to checkout.php?produto=...&amount=...&shop=...
checkout.php: Generates/reuses order_id, shows connect/pay buttons using Paynch JS SDK (paynch-connect-en.js), auto-polling via atualizar-status.php
atualizar-status.php: Secure JSON API – rate-limited, locked query, cURL to Paynch API, tolerance check, logs everything
config.php: Centralizes DB, helpers (CSRF, sanitization, validation, logging), constants

Best for: Custom UI, SPA-like behavior, advanced logic, multi-product stores.
Common security in both:

Cryptographically secure order_id
Session + DB check to prevent replay/duplicates
Tolerance: received ≥ expected × 0.988
HTTPS mandatory
Detailed logging (logs/paynch_YYYY-MM-DD.log)

Prerequisites

PHP 7.4+ with PDO + cURL
MySQL/MariaDB
Paynch account + deployed store contract (from dashboard)
Composer not required (all vanilla PHP)

License
MIT – free to use, modify, deploy.
But always validate payments server-side to avoid losses.
Built with strong focus on security and simplicity for PHP + Paynch integrations.
Questions? Use AI support: https://pay.paynch.io/ai or DM @paynch.io on instagram
