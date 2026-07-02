-- MVP Backoffice schema
-- Generated from a production database dump with all data stripped.
-- Customer records, pricing, OAuth tokens, push subscriptions, and
-- company-specific settings have all been removed. Seed rows at the
-- end provide a bootable admin account and a generic equipment catalogue.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `service_type_id` int(11) NOT NULL,
  `requested_date` date NOT NULL,
  `requested_window` enum('Morning','Afternoon','Either') NOT NULL DEFAULT 'Either',
  `customer_notes` text DEFAULT NULL,
  `confirmed_date` date DEFAULT NULL,
  `confirmed_time` time DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `booking_source` enum('customer_app','phone') NOT NULL DEFAULT 'customer_app',
  `status` enum('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `office_notes` text DEFAULT NULL,
  `tech_notes` text DEFAULT NULL,
  `services_performed` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `salt_bags` int(11) DEFAULT NULL,
  `salt_delivery` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Customer requested salt delivery with this appointment',
  `oxyblast` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Customer requested Hydrogen Peroxide / OxyBlast service'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointment_equipment` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointment_technicians` (
  `id` int(10) UNSIGNED NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `role` enum('lead','technician') NOT NULL DEFAULT 'technician'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `company_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email2` varchar(255) DEFAULT NULL,
  `service_address` varchar(255) DEFAULT NULL,
  `service_city` varchar(100) DEFAULT NULL,
  `service_state` varchar(50) DEFAULT NULL,
  `service_zip` varchar(20) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `billing_city` varchar(100) DEFAULT NULL,
  `billing_state` varchar(50) DEFAULT NULL,
  `billing_zip` varchar(20) DEFAULT NULL,
  `has_separate_billing` tinyint(1) NOT NULL DEFAULT 0,
  `qbo_override_customer_id` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `do_not_service` tinyint(1) NOT NULL DEFAULT 0,
  `auto_service_reminder` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `override_pin` varchar(10) DEFAULT NULL,
  `override_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_platform` enum('ios','android','ubuntu_touch') DEFAULT NULL,
  `location_label` varchar(100) DEFAULT NULL COMMENT 'Short name for this location, e.g. "North Ranch"',
  `location_contact` varchar(100) DEFAULT NULL COMMENT 'On-site contact name for this location',
  `push_notifications_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Customer opt-in for browser push notifications'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customer_device_tokens` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `device_token` varchar(255) NOT NULL,
  `platform` varchar(10) NOT NULL DEFAULT 'ios',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customer_images` (
  `image_id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customer_notes` (
  `note_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `equipment_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `author_id` int(11) NOT NULL,
  `author_role_context` varchar(20) DEFAULT NULL,
  `note_text` text NOT NULL,
  `is_visible_to_customer` tinyint(1) NOT NULL DEFAULT 0,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pinned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `email_log` (
  `log_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `email_type` varchar(50) DEFAULT NULL,
  `to_email` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `appointment_id` int(11) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `equipment` (
  `equipment_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `model` varchar(150) DEFAULT NULL,
  `install_date` date DEFAULT NULL,
  `service_interval_days` int(11) DEFAULT NULL,
  `last_service_date` date DEFAULT NULL,
  `last_filter_date` date DEFAULT NULL COMMENT 'RO only: date filters were last replaced',
  `last_membrane_date` date DEFAULT NULL COMMENT 'RO only: date membrane was last replaced',
  `next_service_due` date DEFAULT NULL,
  `assigned_technician` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `self_service` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = customer services this themselves, exclude from due tracking',
  `part_id` int(11) DEFAULT NULL COMMENT 'parts_catalog.part_id override for this specific equipment item; NULL = use equipment_types.default_part_id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `equipment_types` (
  `type_id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `default_interval_days` int(11) DEFAULT NULL,
  `category` enum('water','air') NOT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_tracked` tinyint(1) NOT NULL DEFAULT 1,
  `show_to_customer` tinyint(1) NOT NULL DEFAULT 1,
  `no_service_schedule` tinyint(1) NOT NULL DEFAULT 0,
  `default_part_id` int(11) DEFAULT NULL COMMENT 'parts_catalog.part_id used as default invoice line item for this equipment type'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(20) NOT NULL,
  `status` enum('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `qbo_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `card_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `card_fee_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `card_fee_source` enum('manual','customer_pending') DEFAULT NULL,
  `card_fee_pending_since` datetime DEFAULT NULL,
  `qbo_sync_status` enum('pending','synced','error','skipped') DEFAULT NULL,
  `qbo_synced_at` timestamp NULL DEFAULT NULL,
  `qbo_sync_error` text DEFAULT NULL,
  `auto_created` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `invoice_counter` (
  `id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `sequence` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `invoice_lines` (
  `line_id` int(11) NOT NULL,
  `line_name` varchar(255) DEFAULT NULL,
  `invoice_id` int(11) NOT NULL,
  `part_id` int(11) DEFAULT NULL,
  `line_type` enum('labor','service_call','parts','filter','equipment','salt','warranty','discount','custom') NOT NULL DEFAULT 'custom',
  `description` varchar(255) NOT NULL,
  `quantity` decimal(8,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
  `h2o2_prorate` decimal(5,2) DEFAULT NULL,
  `discount_note` varchar(255) DEFAULT NULL,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `lead_requests` (
  `lead_id` int(11) NOT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(10) DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_window` varchar(40) DEFAULT NULL,
  `referral` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `converted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `notification_logs` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `device_token` varchar(255) DEFAULT NULL,
  `notification_type` varchar(50) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` varchar(20) DEFAULT NULL,
  `response_code` int(11) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `on_my_way_log` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pending_notifications` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parts_catalog` (
  `part_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `customer_description` varchar(255) NOT NULL,
  `tech_description` text DEFAULT NULL,
  `unit` varchar(50) NOT NULL DEFAULT 'each',
  `cost_price` decimal(10,2) DEFAULT NULL,
  `sell_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_h2o2` tinyint(1) NOT NULL DEFAULT 0,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `part_categories` (
  `category_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','check','card_office','card_field','card_online','warranty','gift_certificate','other') NOT NULL,
  `check_number` varchar(20) DEFAULT NULL,
  `payment_notes` text DEFAULT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `deposit_account_id` varchar(50) DEFAULT NULL,
  `qbo_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qbo_payment_id` varchar(50) DEFAULT NULL,
  `qbo_sync_status` enum('pending','synced','error','skipped') DEFAULT NULL,
  `qbo_synced_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `push_log` (
  `log_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `event_type` varchar(40) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` varchar(500) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `success_count` int(11) NOT NULL DEFAULT 0,
  `failure_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `push_subscriptions` (
  `subscription_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `p256dh_key` varchar(255) NOT NULL,
  `auth_key` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `qbo_customers` (
  `customer_id` int(11) NOT NULL,
  `qbo_customer_id` varchar(50) NOT NULL,
  `qbo_display_name` varchar(255) DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `qbo_items` (
  `item_key` varchar(100) NOT NULL,
  `qbo_item_id` varchar(50) NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `refresh_tokens` (
  `token_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_call_types` (
  `sc_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_records` (
  `record_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `service_date` date NOT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `next_service_due` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `filter_replaced` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'RO only: filters were replaced on this visit',
  `membrane_replaced` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'RO only: membrane was replaced on this visit',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `logged_by` enum('technician','customer') NOT NULL DEFAULT 'technician',
  `service_type_label` varchar(100) DEFAULT NULL,
  `materials_used` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_types` (
  `type_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `min_days_out` int(11) NOT NULL DEFAULT 3,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `default_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_overridable` tinyint(1) NOT NULL DEFAULT 1,
  `is_salt_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `min_bags_required` int(11) NOT NULL DEFAULT 0,
  `extended_reminders` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = send 7/3/1-day reminder cadence in addition to the 24h reminder',
  `customer_requestable` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = office/tech only, do not show in customer booking form',
  `skip_auto_invoice_lines` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = autoCreateInvoiceForAppointment creates draft shell but skips autoPopulateLines'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tax_rates` (
  `rate_id` int(11) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(2) NOT NULL DEFAULT 'IL',
  `rate` decimal(5,4) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tech_clock_time` (
  `entry_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `clocked_in_at` datetime NOT NULL DEFAULT current_timestamp(),
  `clocked_out_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `force_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tech_clock_time_audit` (
  `audit_id` bigint(20) UNSIGNED NOT NULL,
  `entry_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `action` enum('create','update','delete') NOT NULL,
  `changed_by` int(11) NOT NULL,
  `old_clocked_in_at` datetime DEFAULT NULL,
  `old_clocked_out_at` datetime DEFAULT NULL,
  `old_notes` text DEFAULT NULL,
  `new_clocked_in_at` datetime DEFAULT NULL,
  `new_clocked_out_at` datetime DEFAULT NULL,
  `new_notes` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tech_time_entries` (
  `entry_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `time_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_type` enum('CLOCK_IN','CLOCK_OUT') NOT NULL,
  `event_timestamp` datetime NOT NULL,
  `server_timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','technician','admin') NOT NULL DEFAULT 'customer',
  `is_field_tech` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `device_token` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `v_equipment_overview` (
`equipment_id` int(11)
,`customer_id` int(11)
,`customer_name` varchar(201)
,`phone` varchar(20)
,`service_address` varchar(255)
,`service_city` varchar(100)
,`service_state` varchar(50)
,`service_zip` varchar(20)
,`type_name` varchar(100)
,`category` enum('water','air')
,`model` varchar(150)
,`install_date` date
,`effective_interval_days` int(11)
,`last_service_date` date
,`next_service_due` date
,`days_until_due` int(8)
,`technician_email` varchar(255)
);

CREATE TABLE `v_service_due` (
`equipment_id` int(11)
,`customer_id` int(11)
,`customer_name` varchar(201)
,`phone` varchar(20)
,`service_address` varchar(255)
,`service_city` varchar(100)
,`service_state` varchar(50)
,`service_zip` varchar(20)
,`type_name` varchar(100)
,`category` enum('water','air')
,`model` varchar(150)
,`install_date` date
,`effective_interval_days` int(11)
,`last_service_date` date
,`next_service_due` date
,`days_until_due` int(8)
,`technician_email` varchar(255)
);

CREATE TABLE `water_tests` (
  `test_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `label` varchar(150) NOT NULL,
  `test_date` date NOT NULL,
  `filename` varchar(255) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `v_equipment_overview`;

CREATE ALGORITHM=UNDEFINED VIEW `v_equipment_overview`  AS SELECT `e`.`equipment_id` AS `equipment_id`, `e`.`customer_id` AS `customer_id`, concat(`c`.`first_name`,' ',`c`.`last_name`) AS `customer_name`, `c`.`phone` AS `phone`, `c`.`service_address` AS `service_address`, `c`.`service_city` AS `service_city`, `c`.`service_state` AS `service_state`, `c`.`service_zip` AS `service_zip`, `et`.`type_name` AS `type_name`, `et`.`category` AS `category`, `e`.`model` AS `model`, `e`.`install_date` AS `install_date`, coalesce(`e`.`service_interval_days`,`et`.`default_interval_days`) AS `effective_interval_days`, `e`.`last_service_date` AS `last_service_date`, `e`.`next_service_due` AS `next_service_due`, to_days(`e`.`next_service_due`) - to_days(curdate()) AS `days_until_due`, concat(`u`.`email`) AS `technician_email` FROM (((`equipment` `e` join `customers` `c` on(`e`.`customer_id` = `c`.`customer_id`)) join `equipment_types` `et` on(`e`.`type_id` = `et`.`type_id`)) left join `users` `u` on(`e`.`assigned_technician` = `u`.`user_id`)) WHERE `e`.`is_active` = 1;

DROP TABLE IF EXISTS `v_service_due`;

CREATE ALGORITHM=UNDEFINED VIEW `v_service_due`  AS SELECT `v_equipment_overview`.`equipment_id` AS `equipment_id`, `v_equipment_overview`.`customer_id` AS `customer_id`, `v_equipment_overview`.`customer_name` AS `customer_name`, `v_equipment_overview`.`phone` AS `phone`, `v_equipment_overview`.`service_address` AS `service_address`, `v_equipment_overview`.`service_city` AS `service_city`, `v_equipment_overview`.`service_state` AS `service_state`, `v_equipment_overview`.`service_zip` AS `service_zip`, `v_equipment_overview`.`type_name` AS `type_name`, `v_equipment_overview`.`category` AS `category`, `v_equipment_overview`.`model` AS `model`, `v_equipment_overview`.`install_date` AS `install_date`, `v_equipment_overview`.`effective_interval_days` AS `effective_interval_days`, `v_equipment_overview`.`last_service_date` AS `last_service_date`, `v_equipment_overview`.`next_service_due` AS `next_service_due`, `v_equipment_overview`.`days_until_due` AS `days_until_due`, `v_equipment_overview`.`technician_email` AS `technician_email` FROM `v_equipment_overview` WHERE `v_equipment_overview`.`next_service_due` <= curdate() + interval 30 day ORDER BY `v_equipment_overview`.`next_service_due` ASC;

ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `service_type_id` (`service_type_id`),
  ADD KEY `technician_id` (`technician_id`),
  ADD KEY `assigned_by` (`assigned_by`);

ALTER TABLE `appointment_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `equipment_id` (`equipment_id`);

ALTER TABLE `appointment_technicians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_appt_tech` (`appointment_id`,`technician_id`),
  ADD KEY `technician_id` (`technician_id`);

ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

ALTER TABLE `customer_device_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device_token` (`device_token`),
  ADD KEY `idx_customer_active` (`customer_id`,`is_active`);

ALTER TABLE `customer_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_appt` (`appointment_id`);

ALTER TABLE `customer_notes`
  ADD PRIMARY KEY (`note_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `author_id` (`author_id`),
  ADD KEY `idx_notes_appointment` (`appointment_id`);

ALTER TABLE `email_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `idx_email_log_appt_type` (`appointment_id`,`email_type`);

ALTER TABLE `equipment`
  ADD PRIMARY KEY (`equipment_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `type_id` (`type_id`),
  ADD KEY `assigned_technician` (`assigned_technician`),
  ADD KEY `idx_equipment_part` (`part_id`);

ALTER TABLE `equipment_types`
  ADD PRIMARY KEY (`type_id`),
  ADD KEY `idx_equipment_types_default_part` (`default_part_id`);

ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_invoices_qbo_sync` (`qbo_sync_status`);

ALTER TABLE `invoice_counter`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year_seq` (`year`);

ALTER TABLE `invoice_lines`
  ADD PRIMARY KEY (`line_id`),
  ADD KEY `invoice_id` (`invoice_id`);

ALTER TABLE `lead_requests`
  ADD PRIMARY KEY (`lead_id`);

ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer_sent` (`customer_id`,`sent_at`),
  ADD KEY `idx_type_sent` (`notification_type`,`sent_at`);

ALTER TABLE `on_my_way_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_appt_tech` (`appointment_id`,`technician_id`),
  ADD KEY `technician_id` (`technician_id`);

ALTER TABLE `parts_catalog`
  ADD PRIMARY KEY (`part_id`),
  ADD KEY `category_id` (`category_id`);

ALTER TABLE `part_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD KEY `parent_id` (`parent_id`);

ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_payments_qbo_sync` (`qbo_sync_status`);

ALTER TABLE `push_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_sent_at` (`sent_at`);

ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`subscription_id`),
  ADD UNIQUE KEY `uniq_endpoint` (`endpoint`),
  ADD KEY `idx_customer` (`customer_id`);

ALTER TABLE `pending_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_unread` (`customer_id`, `read_at`);

ALTER TABLE `qbo_customers`
  ADD PRIMARY KEY (`customer_id`);

ALTER TABLE `qbo_items`
  ADD PRIMARY KEY (`item_key`);

ALTER TABLE `refresh_tokens`
  ADD PRIMARY KEY (`token_id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `service_call_types`
  ADD PRIMARY KEY (`sc_id`);

ALTER TABLE `service_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `technician_id` (`technician_id`);

ALTER TABLE `service_types`
  ADD PRIMARY KEY (`type_id`);

ALTER TABLE `tax_rates`
  ADD PRIMARY KEY (`rate_id`),
  ADD UNIQUE KEY `city_state` (`city`,`state`);

ALTER TABLE `tech_clock_time`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `idx_tech_open` (`technician_id`,`clocked_out_at`,`force_closed`),
  ADD KEY `idx_tech_date` (`technician_id`,`clocked_in_at`);

ALTER TABLE `tech_clock_time_audit`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_entry` (`entry_id`),
  ADD KEY `idx_technician` (`technician_id`),
  ADD KEY `idx_changed_at` (`changed_at`);

ALTER TABLE `tech_time_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `idx_tech_open` (`technician_id`,`clock_out`);

ALTER TABLE `time_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_event` (`user_id`,`event_timestamp`),
  ADD KEY `idx_user_session` (`user_id`,`session_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `water_tests`
  ADD PRIMARY KEY (`test_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

ALTER TABLE `appointment_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=129;

ALTER TABLE `appointment_technicians`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

ALTER TABLE `customer_device_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `customer_images`
  MODIFY `image_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

ALTER TABLE `customer_notes`
  MODIFY `note_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

ALTER TABLE `email_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=176;

ALTER TABLE `equipment`
  MODIFY `equipment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=349;

ALTER TABLE `equipment_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

ALTER TABLE `invoice_counter`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

ALTER TABLE `invoice_lines`
  MODIFY `line_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=530;

ALTER TABLE `lead_requests`
  MODIFY `lead_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `on_my_way_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

ALTER TABLE `pending_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `parts_catalog`
  MODIFY `part_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

ALTER TABLE `part_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

ALTER TABLE `push_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

ALTER TABLE `push_subscriptions`
  MODIFY `subscription_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `refresh_tokens`
  MODIFY `token_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2204;

ALTER TABLE `service_call_types`
  MODIFY `sc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `service_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

ALTER TABLE `service_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `tax_rates`
  MODIFY `rate_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

ALTER TABLE `tech_clock_time`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

ALTER TABLE `tech_clock_time_audit`
  MODIFY `audit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `tech_time_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `time_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

ALTER TABLE `water_tests`
  MODIFY `test_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`service_type_id`) REFERENCES `service_types` (`type_id`),
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `appointments_ibfk_4` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `appointment_equipment`
  ADD CONSTRAINT `appointment_equipment_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointment_equipment_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE;

ALTER TABLE `appointment_technicians`
  ADD CONSTRAINT `appointment_technicians_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointment_technicians_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `customer_device_tokens`
  ADD CONSTRAINT `customer_device_tokens_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE;

ALTER TABLE `customer_notes`
  ADD CONSTRAINT `customer_notes_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_notes_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `customer_notes_ibfk_3` FOREIGN KEY (`author_id`) REFERENCES `users` (`user_id`);

ALTER TABLE `email_log`
  ADD CONSTRAINT `email_log_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_email_log_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL;

ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `equipment_ibfk_2` FOREIGN KEY (`type_id`) REFERENCES `equipment_types` (`type_id`),
  ADD CONSTRAINT `equipment_ibfk_3` FOREIGN KEY (`assigned_technician`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `invoice_lines`
  ADD CONSTRAINT `invoice_lines_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE;

ALTER TABLE `on_my_way_log`
  ADD CONSTRAINT `on_my_way_log_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `on_my_way_log_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `pending_notifications`
  ADD CONSTRAINT `pending_notifications_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE;

ALTER TABLE `parts_catalog`
  ADD CONSTRAINT `parts_catalog_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `part_categories` (`category_id`);

ALTER TABLE `part_categories`
  ADD CONSTRAINT `part_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `part_categories` (`category_id`) ON DELETE CASCADE;

ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `qbo_customers`
  ADD CONSTRAINT `qbo_customers_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE;

ALTER TABLE `refresh_tokens`
  ADD CONSTRAINT `refresh_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `service_records`
  ADD CONSTRAINT `service_records_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `service_records_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`);

ALTER TABLE `tech_clock_time`
  ADD CONSTRAINT `fk_clt_tech` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `tech_time_entries`
  ADD CONSTRAINT `tech_time_entries_ibfk_1` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tech_time_entries_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL;

ALTER TABLE `time_logs`
  ADD CONSTRAINT `time_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `water_tests`
  ADD CONSTRAINT `water_tests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `water_tests_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);


-- Seed data ----------------------------------------------------------------
-- Bootable defaults. The three accounts share the password "changeme"
-- (bcrypt hash below). Rotate them on first login.

INSERT INTO `users` (`user_id`, `email`, `password_hash`, `role`, `is_active`) VALUES
  (1, 'admin@example.com', '$2y$10$N0Q1uH4kK0M.Yq3eS/qNk.Ojk1OUjB1ZRzN5oQuS9jH6YBeC1pXWa', 'admin',      1),
  (2, 'tech@example.com',  '$2y$10$N0Q1uH4kK0M.Yq3eS/qNk.Ojk1OUjB1ZRzN5oQuS9jH6YBeC1pXWa', 'technician', 1),
  (3, 'cust@example.com',  '$2y$10$N0Q1uH4kK0M.Yq3eS/qNk.Ojk1OUjB1ZRzN5oQuS9jH6YBeC1pXWa', 'customer',   1);

INSERT INTO `customers`
  (`customer_id`, `user_id`, `first_name`, `last_name`, `phone`, `email`,
   `service_address`, `service_city`, `service_state`, `service_zip`)
VALUES
  (1, 3, 'Demo', 'Customer', '555-555-0101', 'cust@example.com',
   '123 Main Street', 'Anytown', 'ST', '00000');

INSERT INTO `equipment_types` (`type_name`, `default_interval_days`, `category`) VALUES
  ('Reverse Osmosis / Drinking Water', 365, 'water'),
  ('Whole-House Sediment Prefilter',   180, 'water'),
  ('Hydrogen Peroxide System',         180, 'water'),
  ('Water Softener',                   365, 'water'),
  ('Pump Tube',                        365, 'water'),
  ('UV Sterilizer',                    365, 'water'),
  ('Air Purifier / UV System',         365, 'air'),
  ('HVAC Filter',                       90, 'air'),
  ('Room Air Filter',                   90, 'air');

INSERT INTO `company_settings` (`setting_key`, `setting_value`) VALUES
  ('company_name',     'Acme Water Service'),
  ('company_phone',    '555-555-5555'),
  ('company_email',    'info@example.com'),
  ('company_website',  'https://example.com'),
  ('default_tax_rate', '0.0625');

INSERT INTO `invoice_counter` (`id`, `year`, `sequence`) VALUES (1, YEAR(CURDATE()), 0);
