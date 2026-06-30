CREATE TABLE IF NOT EXISTS `production_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `progress_no` varchar(30) NOT NULL UNIQUE,
  `warehouse_in_id` int(11) NOT NULL,
  `product_code_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `qty_total` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_done` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_defect` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_remaining` decimal(15,3) NOT NULL DEFAULT 0.000,
  `status` enum('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_progress_warehouse_in` (`warehouse_in_id`),
  KEY `idx_progress_customer` (`customer_id`),
  KEY `idx_progress_product` (`product_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_progress_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `progress_id` int(11) NOT NULL,
  `log_date` date NOT NULL,
  `qty_done` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_defect` decimal(15,3) NOT NULL DEFAULT 0.000,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_progress_logs_progress` (`progress_id`),
  KEY `idx_progress_logs_date` (`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finished_goods_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fgs_no` varchar(30) NOT NULL UNIQUE,
  `progress_id` int(11) NOT NULL,
  `progress_log_id` int(11) DEFAULT NULL,
  `product_code_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `type` enum('normal','defect') NOT NULL DEFAULT 'normal',
  `qty_in` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_exported` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_remaining` decimal(15,3) NOT NULL DEFAULT 0.000,
  `status` enum('pending_export','partial_export','exported','delivered') NOT NULL DEFAULT 'pending_export',
  `source_date` date NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fgs_progress` (`progress_id`),
  KEY `idx_fgs_customer` (`customer_id`),
  KEY `idx_fgs_product` (`product_code_id`),
  KEY `idx_fgs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_exports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `export_no` varchar(30) NOT NULL UNIQUE,
  `export_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `status` enum('draft','confirmed') NOT NULL DEFAULT 'draft',
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stock_exports_customer` (`customer_id`),
  KEY `idx_stock_exports_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_export_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `export_id` int(11) NOT NULL,
  `fgs_id` int(11) NOT NULL,
  `product_code_id` int(11) NOT NULL,
  `qty_export` decimal(15,3) NOT NULL DEFAULT 0.000,
  `note` varchar(255) DEFAULT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_export_items_export` (`export_id`),
  KEY `idx_stock_export_items_fgs` (`fgs_id`),
  KEY `idx_stock_export_items_delivery` (`delivery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `export_id` int(11) DEFAULT NULL AFTER `warehouse_out_id`,
  ADD CONSTRAINT `fk_deliveries_export`
    FOREIGN KEY (`export_id`) REFERENCES `stock_exports` (`id`)
    ON DELETE SET NULL;

ALTER TABLE `delivery_items`
  ADD COLUMN IF NOT EXISTS `export_item_id` int(11) DEFAULT NULL AFTER `product_code_id`,
  ADD CONSTRAINT `fk_delivery_items_export_item`
    FOREIGN KEY (`export_item_id`) REFERENCES `stock_export_items` (`id`)
    ON DELETE SET NULL;
