-- Migration 005: Estimates/Quotes and Service Contracts
-- Adds estimate/quote workflow and recurring service contract functionality.

-- ESTIMATES (quotes sent to customers before work is approved)
CREATE TABLE `estimates` (
  `estimate_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL COMMENT 'Linked appointment if estimate came from a service call',
  `contract_id` int(11) DEFAULT NULL COMMENT 'Linked contract if estimate is for contract-related work',
  `estimate_number` varchar(20) NOT NULL,
  `status` enum('draft','sent','approved','rejected','expired','converted') NOT NULL DEFAULT 'draft',
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `customer_response_notes` text DEFAULT NULL COMMENT 'Customer notes when approving/rejecting',
  `created_by` int(11) NOT NULL,
  `converted_invoice_id` int(11) DEFAULT NULL COMMENT 'Invoice created when estimate was approved/converted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ESTIMATE LINE ITEMS (mirrors invoice_lines structure)
CREATE TABLE `estimate_lines` (
  `line_id` int(11) NOT NULL,
  `estimate_id` int(11) NOT NULL,
  `part_id` int(11) DEFAULT NULL,
  `line_type` enum('labor','service_call','parts','filter','equipment','salt','warranty','discount','custom') NOT NULL DEFAULT 'custom',
  `line_name` varchar(255) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(8,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
  `h2o2_prorate` decimal(5,2) DEFAULT NULL,
  `discount_note` varchar(255) DEFAULT NULL,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ESTIMATE COUNTER (for numbering, mirrors invoice_counter)
CREATE TABLE `estimate_counter` (
  `id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `sequence` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SERVICE CONTRACTS (recurring service agreements)
CREATE TABLE `service_contracts` (
  `contract_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `contract_number` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Descriptive name, e.g. "Annual Water Softener Maintenance"',
  `status` enum('draft','active','expired','cancelled') NOT NULL DEFAULT 'draft',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'NULL = ongoing / auto-renewing',
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = automatically renew for another term when end_date is reached',
  `renew_term_months` int(11) DEFAULT 12 COMMENT 'How many months to add on auto-renew',
  `frequency` enum('monthly','quarterly','semi_annual','annual','custom') NOT NULL DEFAULT 'annual',
  `custom_interval_days` int(11) DEFAULT NULL COMMENT 'Used when frequency = custom',
  `visits_per_cycle` int(11) NOT NULL DEFAULT 1 COMMENT 'How many service visits per billing cycle',
  `billing_cycle` enum('monthly','quarterly','semi_annual','annual','per_visit') NOT NULL DEFAULT 'annual',
  `cycle_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Price per billing cycle',
  `per_visit_price` decimal(10,2) DEFAULT NULL COMMENT 'Override price per individual visit (if billing = per_visit)',
  `discount_percent` decimal(5,2) DEFAULT NULL COMMENT 'Discount % applied to standard service rates',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- CONTRACT EQUIPMENT (which equipment items are covered by a contract)
CREATE TABLE `contract_equipment` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- CONTRACT SERVICE TYPES (which service types are included in the contract)
CREATE TABLE `contract_service_types` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `service_type_id` int(11) NOT NULL,
  `included_visits` int(11) NOT NULL DEFAULT 1 COMMENT 'How many of this service type per cycle'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- CONTRACT INVOICE SCHEDULE (tracks which invoices were generated for which contract cycle)
CREATE TABLE `contract_invoice_log` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `cycle_start` date NOT NULL,
  `cycle_end` date NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- CONTRACT APPOINTMENT LOG (tracks appointments generated from contracts)
CREATE TABLE `contract_appointment_log` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `scheduled_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- INDEXES -------------------------------------------------------------------

ALTER TABLE `estimates`
  ADD PRIMARY KEY (`estimate_id`),
  ADD UNIQUE KEY `estimate_number` (`estimate_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_estimates_status` (`status`),
  ADD KEY `idx_estimates_expiry` (`expiry_date`);

ALTER TABLE `estimate_lines`
  ADD PRIMARY KEY (`line_id`),
  ADD KEY `estimate_id` (`estimate_id`);

ALTER TABLE `estimate_counter`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year_seq` (`year`);

ALTER TABLE `service_contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD UNIQUE KEY `contract_number` (`contract_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_contracts_status` (`status`),
  ADD KEY `idx_contracts_end_date` (`end_date`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `contract_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `equipment_id` (`equipment_id`);

ALTER TABLE `contract_service_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `service_type_id` (`service_type_id`);

ALTER TABLE `contract_invoice_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `invoice_id` (`invoice_id`);

ALTER TABLE `contract_appointment_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `appointment_id` (`appointment_id`);

-- AUTO INCREMENT ------------------------------------------------------------

ALTER TABLE `estimates`
  MODIFY `estimate_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `estimate_lines`
  MODIFY `line_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `estimate_counter`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `service_contracts`
  MODIFY `contract_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `contract_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `contract_service_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `contract_invoice_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `contract_appointment_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- SEED: estimate counter for current year -----------------------------------
INSERT INTO `estimate_counter` (`id`, `year`, `sequence`) VALUES (1, YEAR(CURDATE()), 0);
-- Migration 006: IoT Sensor Tracking
-- Adds device registry, time-series readings, alert thresholds, and device type catalog.

-- IoT DEVICE TYPES (catalog of supported sensor types)
CREATE TABLE `iot_device_types` (
  `type_id` int(11) NOT NULL,
  `type_slug` varchar(50) NOT NULL COMMENT 'Machine-readable identifier, e.g. salt_monitor, tds_sensor',
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `metrics` json DEFAULT NULL COMMENT 'Array of metric objects: [{name, unit, min, max, alert_threshold_low, alert_threshold_high}]',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- IoT DEVICES (registered sensors linked to customer + equipment)
CREATE TABLE `iot_devices` (
  `device_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `equipment_id` int(11) DEFAULT NULL COMMENT 'Linked equipment this sensor monitors',
  `type_id` int(11) NOT NULL,
  `device_name` varchar(150) NOT NULL COMMENT 'Human-readable name, e.g. "Kitchen Softener Salt Monitor"',
  `device_key` varchar(64) NOT NULL COMMENT 'API key for device authentication',
  `mac_address` varchar(20) DEFAULT NULL,
  `firmware_version` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','offline','error') NOT NULL DEFAULT 'active',
  `last_seen` datetime DEFAULT NULL COMMENT 'Timestamp of last telemetry upload',
  `last_reading_summary` json DEFAULT NULL COMMENT 'Cached last reading values for quick dashboard display',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- IoT READINGS (time-series sensor data)
CREATE TABLE `iot_readings` (
  `reading_id` bigint(20) UNSIGNED NOT NULL,
  `device_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `metric_name` varchar(50) NOT NULL COMMENT 'e.g. salt_level, tds, ph, pressure, flow_rate',
  `value` decimal(12,4) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT '' COMMENT 'e.g. percent, ppm, psi, gpm',
  `recorded_at` datetime NOT NULL COMMENT 'When the sensor took the reading',
  `server_received_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the server received the upload',
  `metadata` json DEFAULT NULL COMMENT 'Optional device-specific metadata (battery %, signal strength, etc.)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- IoT ALERT THRESHOLDS (rules for when to notify)
CREATE TABLE `iot_alert_rules` (
  `rule_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `metric_name` varchar(50) NOT NULL,
  `rule_name` varchar(150) NOT NULL COMMENT 'e.g. "Low Salt Warning"',
  `condition` enum('below','above','equals','below_or_equals','above_or_equals') NOT NULL DEFAULT 'below',
  `threshold_value` decimal(12,4) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notification_channels` json DEFAULT NULL COMMENT '["push","email"] - where to send alerts',
  `cooldown_minutes` int(11) NOT NULL DEFAULT 60 COMMENT 'Minimum time between repeated alerts for same rule',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- IoT ALERT LOG (fired alerts)
CREATE TABLE `iot_alert_log` (
  `alert_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `rule_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `metric_name` varchar(50) NOT NULL,
  `triggered_value` decimal(12,4) NOT NULL,
  `threshold_value` decimal(12,4) NOT NULL,
  `condition` varchar(20) NOT NULL,
  `message` text DEFAULT NULL,
  `triggered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(11) DEFAULT NULL,
  `notification_sent` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- INDEXES -------------------------------------------------------------------

ALTER TABLE `iot_device_types`
  ADD PRIMARY KEY (`type_id`),
  ADD UNIQUE KEY `type_slug` (`type_slug`);

ALTER TABLE `iot_devices`
  ADD PRIMARY KEY (`device_id`),
  ADD UNIQUE KEY `device_key` (`device_key`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `type_id` (`type_id`),
  ADD KEY `idx_devices_status` (`status`),
  ADD KEY `idx_devices_last_seen` (`last_seen`);

ALTER TABLE `iot_readings`
  ADD PRIMARY KEY (`reading_id`),
  ADD KEY `device_id` (`device_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_readings_metric_time` (`metric_name`, `recorded_at`),
  ADD KEY `idx_readings_recorded_at` (`recorded_at`),
  ADD KEY `idx_readings_device_time` (`device_id`, `recorded_at`);

ALTER TABLE `iot_alert_rules`
  ADD PRIMARY KEY (`rule_id`),
  ADD KEY `device_id` (`device_id`),
  ADD KEY `idx_rules_enabled` (`enabled`);

ALTER TABLE `iot_alert_log`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `device_id` (`device_id`),
  ADD KEY `rule_id` (`rule_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_alerts_unacked` (`acknowledged_at`, `triggered_at`),
  ADD KEY `idx_alerts_triggered_at` (`triggered_at`);

-- AUTO INCREMENT ------------------------------------------------------------

ALTER TABLE `iot_device_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `iot_devices`
  MODIFY `device_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `iot_readings`
  MODIFY `reading_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `iot_alert_rules`
  MODIFY `rule_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `iot_alert_log`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

-- SEED DATA -----------------------------------------------------------------

INSERT INTO `iot_device_types` (`type_slug`, `type_name`, `description`, `metrics`) VALUES
('salt_monitor', 'Salt Level Monitor', 'Ultrasonic or load-cell sensor measuring salt level in water softener brine tank',
 '[{"name":"salt_level","unit":"percent","min":0,"max":100,"alert_threshold_low":20,"alert_threshold_high":null},{"name":"battery_percent","unit":"percent","min":0,"max":100,"alert_threshold_low":15,"alert_threshold_high":null}]'),
('tds_sensor', 'TDS Sensor', 'Total Dissolved Solids sensor for water quality monitoring',
 '[{"name":"tds","unit":"ppm","min":0,"max":2000,"alert_threshold_low":null,"alert_threshold_high":500},{"name":"temperature","unit":"celsius","min":0,"max":100,"alert_threshold_low":null,"alert_threshold_high":null}]'),
('ph_sensor', 'pH Sensor', 'Water pH level monitoring',
 '[{"name":"ph","unit":"pH","min":0,"max":14,"alert_threshold_low":6.5,"alert_threshold_high":8.5}]'),
('pressure_sensor', 'Pressure Monitor', 'Water pressure monitoring for system diagnostics',
 '[{"name":"pressure","unit":"psi","min":0,"max":150,"alert_threshold_low":30,"alert_threshold_high":80}]'),
('flow_meter', 'Flow Meter', 'Water flow rate monitoring',
 '[{"name":"flow_rate","unit":"gpm","min":0,"max":50,"alert_threshold_low":null,"alert_threshold_high":null},{"name":"total_gallons","unit":"gallons","min":0,"max":999999,"alert_threshold_low":null,"alert_threshold_high":null}]'),
('temp_humidity', 'Temperature & Humidity', 'Ambient conditions monitoring for equipment rooms',
 '[{"name":"temperature","unit":"celsius","min":-20,"max":60,"alert_threshold_low":0,"alert_threshold_high":40},{"name":"humidity","unit":"percent","min":0,"max":100,"alert_threshold_low":null,"alert_threshold_high":80}]');
