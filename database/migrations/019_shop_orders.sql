CREATE TABLE IF NOT EXISTS shop_orders (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    order_number VARCHAR(64) NOT NULL UNIQUE,
    user_id VARCHAR(36) NOT NULL,
    status ENUM('pending','paid','shipped','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
    product_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_surcharge DECIMAL(10,2) NOT NULL DEFAULT 0,
    price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_method JSON NOT NULL DEFAULT ('{}'),
    payment_method JSON NOT NULL DEFAULT ('{}'),
    shipping_address JSON DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    cancel_reason TEXT DEFAULT NULL,
    estimated_delivery VARCHAR(64) DEFAULT NULL,
    history JSON NOT NULL DEFAULT ('[]'),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shop_orders_user (user_id),
    INDEX idx_shop_orders_status (status),
    INDEX idx_shop_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_order_items (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    order_id VARCHAR(36) NOT NULL,
    product_id VARCHAR(36) NOT NULL DEFAULT '',
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    size VARCHAR(64) DEFAULT NULL,
    color VARCHAR(64) DEFAULT NULL,
    INDEX idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
