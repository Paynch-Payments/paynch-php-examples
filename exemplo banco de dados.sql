-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Tempo de geração: 17/02/2026 às 14:48
-- Versão do servidor: 11.8.3-MariaDB-log
-- Versão do PHP: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `u173816985_cuzi`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `estatisticas_pedidos`
--

CREATE TABLE `estatisticas_pedidos` (
  `total_pedidos` bigint(21) DEFAULT NULL,
  `confirmados` bigint(21) DEFAULT NULL,
  `pendentes` bigint(21) DEFAULT NULL,
  `cancelados` bigint(21) DEFAULT NULL,
  `total_recebido` decimal(40,8) DEFAULT NULL,
  `ticket_medio` decimal(22,12) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `logs_pagamento`
--

CREATE TABLE `logs_pagamento` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` varchar(20) DEFAULT NULL,
  `tipo` enum('pedido_criado','pagamento_detectado','pagamento_confirmado','erro','tentativa_fraude') NOT NULL,
  `mensagem` text DEFAULT NULL,
  `dados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Dados adicionais em formato JSON' CHECK (json_valid(`dados`)),
  `ip` varchar(45) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `lojas_config`
--

CREATE TABLE `lojas_config` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome_loja` varchar(100) NOT NULL,
  `contract_address` varchar(42) NOT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `email_notificacao` varchar(255) DEFAULT NULL,
  `webhook_url` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pedidos`
--

CREATE TABLE `pedidos` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` varchar(20) NOT NULL,
  `produto` varchar(255) NOT NULL,
  `valor_usdt` decimal(18,8) NOT NULL,
  `status` enum('pendente','confirmado','cancelado','expirado') DEFAULT 'pendente',
  `tx_code` varchar(100) DEFAULT NULL COMMENT 'Hash da transação na blockchain',
  `payer` varchar(50) DEFAULT NULL COMMENT 'Endereço da carteira que pagou',
  `amount_recebido` decimal(18,8) DEFAULT NULL COMMENT 'Valor efetivamente recebido',
  `contract_loja` varchar(42) NOT NULL COMMENT 'Endereço do contrato da loja',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `pago_em` timestamp NULL DEFAULT NULL,
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_cliente` varchar(45) DEFAULT NULL COMMENT 'IP do cliente (IPv4 ou IPv6)',
  `user_agent` text DEFAULT NULL COMMENT 'User agent do navegador'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Acionadores `pedidos`
--
DELIMITER $$
CREATE TRIGGER `after_pedido_confirmado` AFTER UPDATE ON `pedidos` FOR EACH ROW BEGIN
    IF NEW.status = 'confirmado' AND OLD.status != 'confirmado' THEN
        INSERT INTO logs_pagamento (order_id, tipo, mensagem, dados)
        VALUES (
            NEW.order_id,
            'pagamento_confirmado',
            CONCAT('Pedido confirmado: ', NEW.produto),
            JSON_OBJECT(
                'valor', NEW.valor_usdt,
                'valor_recebido', NEW.amount_recebido,
                'tx_code', NEW.tx_code,
                'payer', NEW.payer
            )
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pedidos_hoje`
--

CREATE TABLE `pedidos_hoje` (
  `order_id` varchar(20) DEFAULT NULL,
  `produto` varchar(255) DEFAULT NULL,
  `valor_usdt` decimal(18,8) DEFAULT NULL,
  `amount_recebido` decimal(18,8) DEFAULT NULL,
  `tx_code` varchar(100) DEFAULT NULL,
  `pago_em` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
