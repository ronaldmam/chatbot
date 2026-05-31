-- 1. Table: users (Platform administrators and agents)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'agent') NOT NULL DEFAULT 'agent',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_username (username)
) ENGINE=InnoDB;

-- 2. Table: customers (Clients connecting via social channels)
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    psid VARCHAR(150) UNIQUE NOT NULL, -- Platform Sender ID (e.g., Phone number, FB Messenger ID, TikTok ID)
    name VARCHAR(150) DEFAULT 'Cliente Anónimo',
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    platform ENUM('whatsapp', 'messenger', 'tiktok', 'web') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_psid (psid),
    INDEX idx_customer_platform (platform)
) ENGINE=InnoDB;

-- 3. Table: chat_conversations (Top-level conversation meta and flow states)
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    flow_state ENUM('bot', 'ia', 'human') NOT NULL DEFAULT 'bot', -- Options-based / AI / Wasapi Agent
    wasapi_ticket_id VARCHAR(150) DEFAULT NULL,                 -- Null until Handover
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_conv_flow_state (flow_state)
) ENGINE=InnoDB;

-- 4. Table: chat_messages (Thread history linked to conversations)
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender ENUM('customer', 'bot', 'agent') NOT NULL,
    message_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    INDEX idx_msg_conv (conversation_id)
) ENGINE=InnoDB;

-- 5. Table: knowledge_base (For RAG content: scraped pages, uploaded PDFs, WooCommerce meta)
CREATE TABLE IF NOT EXISTS knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('pdf', 'url', 'woocommerce') NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,                                   -- Processed plain text for search ingestion
    source_url VARCHAR(255) DEFAULT NULL,                       -- Original file path or web link
    meta_info JSON DEFAULT NULL,                                -- Additional structured data (e.g. WC credentials, PDF pages)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY idx_kb_content_fulltext (content),             -- Full-text index for simple hybrid RAG search
    INDEX idx_kb_type (type)
) ENGINE=InnoDB;

-- Insert a default Administrator user (Password: AdminNaldike2026!)
INSERT INTO users (username, email, password_hash, role) VALUES 
('admin', 'admin@naldike.com', '$2y$10$n67mDSs4KXN9Jwf267xsb.A8iqJ2mDkzoIx2Ofu0ewDiJvP6nqjSu', 'admin')
ON DUPLICATE KEY UPDATE id=id;
