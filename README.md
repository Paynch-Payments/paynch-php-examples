# Paynch PHP Examples

Practical, secure PHP integration examples for **Paynch**. non-custodial crypto payments on EVM chains (BSC, opBNB).

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

The repository contains two ready-to-use integration examples:

- **button integration/**  
  Simple and fast setup using the official Paynch Button embed.  
  - `checkout.php` → Generates secure order ID, displays product info and embeds the Paynch Button.  
  - `confirmacao.php` → Handles payment confirmation with automatic reload polling and server-side API validation.

- **manual integration/**  
  Advanced setup with full control, Web3 wallet connection and custom polling.  
  - `atualizar-status.php` → Secure backend endpoint (JSON) that verifies payment via Paynch API, applies tolerance check and updates the database.  
  - `checkout.php` → Custom checkout page with Paynch JS SDK, connect/pay buttons and automatic/manual verification.  
  - `config.php` → Database connection, security helpers, constants, logging and validation functions.  
  - `index.php` → Demo storefront listing multiple products with "Buy Now" links.

- Shared files:  
  - `exemplo banco de dados.sql` → SQL schema for the `pedidos` table (used by both integrations).  
  - `README.md`

Both approaches share the same database schema and follow the same security principles (server-side validation mandatory).
└── README.md



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
## Integration Options

### 1. Button Integration (Quick & Recommended for Most Sites)
**Folder:** `button integration/`

- `checkout.php`: Generates secure `order_id`, stores it in DB and session, displays product details and embeds the Paynch Button via  
  `<script src="https://pay.paynch.io/button/button-connect.js">` with all required data attributes.  
  On success, redirects to `confirmacao.php?orderId=...`
- `confirmacao.php`: Confirmation page that polls itself (auto-reload every ~5s), queries the Paynch API, applies 1.1–1.2% tolerance, updates the database transactionally and shows success/failure status.

**Best for:** Simple e-commerce, digital products, fast setup with minimal custom code.

### 2. Manual / Custom Integration (Full Control & Web3 Experience)
**Folder:** `manual integration/`

- `index.php`: Demo storefront listing multiple products with "Buy Now" links that point to `checkout.php?produto=...&amount=...&shop=...`
- `checkout.php`: Custom checkout page that generates/reuses `order_id`, shows "Connect Wallet" and "Pay" buttons using the Paynch JS SDK (`paynch-connect-en.js`), and implements automatic polling + manual verification fallback via `atualizar-status.php`.
- `atualizar-status.php`: Secure JSON backend endpoint – rate-limited, uses PDO row locking (`FOR UPDATE`), makes cURL calls to Paynch API, checks tolerance, logs events and updates the order status.
- `config.php`: Central configuration file with PDO connection, security helpers (CSRF, sanitization, validation), constants (tolerance %, retry limits), logging function and secure headers.

**Best for:** Custom UI, SPA-like behavior, advanced logic, multi-product stores, full Web3 integration.

### Common Security Features in Both Approaches
- Cryptographically secure `order_id` generation
- Session + database checks to prevent replay attacks and duplicates
- Tolerance check: received amount ≥ expected × 0.988 (covers platform fees)
- HTTPS mandatory
- Detailed event logging to `logs/paynch_YYYY-MM-DD.log`
- Rate limiting, secure headers, input validation and CSRF protection (in manual flow)

## Prerequisites
- PHP 7.4+ with PDO and cURL extensions
- MySQL or MariaDB database
- Paynch account with a deployed store contract (generated in the dashboard at https://pay.paynch.io)
- No Composer or external dependencies required (all vanilla PHP)

## License
MIT License – free to use, modify and deploy in any project.  
**Important:** Always validate payments **server-side** to prevent financial losses.

Built with a strong focus on security and simplicity for PHP developers integrating Paynch.  
Questions? Use the AI support at https://pay.paynch.io/ai or reach out on X: @paynch.io 🚀
