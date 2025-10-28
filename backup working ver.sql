-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 28, 2025 at 07:16 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `eamts`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `GetAllOverdueMaintenance` ()   BEGIN
    SELECT 
        rm.id,
        rm.asset_id,
        a.asset_code,
        a.asset_name,
        rm.schedule_name,
        rm.maintenance_type,
        rm.next_due_date,
        DATEDIFF(CURDATE(), rm.next_due_date) as days_overdue,
        rm.assigned_to,
        CONCAT(u.first_name, ' ', u.last_name) as assigned_user_name,
        u.email as assigned_user_email
    FROM recurring_maintenance rm
    INNER JOIN assets a ON rm.asset_id = a.id
    LEFT JOIN users u ON rm.assigned_to = u.user_id
    WHERE rm.is_active = 1 
    AND rm.next_due_date < CURDATE()
    ORDER BY days_overdue DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetAssetMaintenanceSummary` (IN `p_asset_id` INT)   BEGIN
    -- Asset basic info
    SELECT 
        a.id,
        a.asset_code,
        a.asset_name,
        a.category,
        a.status,
        a.warranty_expiry,
        DATEDIFF(a.warranty_expiry, CURDATE()) as warranty_days_remaining
    FROM assets a
    WHERE a.id = p_asset_id;
    
    -- Maintenance history summary
    SELECT 
        COUNT(*) as total_maintenance_count,
        COALESCE(SUM(cost), 0) as total_cost,
        MAX(maintenance_date) as last_maintenance_date,
        MIN(maintenance_date) as first_maintenance_date
    FROM asset_maintenance
    WHERE asset_id = p_asset_id;
    
    -- Active recurring schedules
    SELECT 
        COUNT(*) as active_schedule_count,
        MIN(next_due_date) as next_due_date
    FROM recurring_maintenance
    WHERE asset_id = p_asset_id AND is_active = 1;
    
    -- Overdue maintenance count
    SELECT 
        COUNT(*) as overdue_count
    FROM recurring_maintenance
    WHERE asset_id = p_asset_id 
    AND is_active = 1 
    AND next_due_date < CURDATE();
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetMaintenanceDueWithinDays` (IN `p_days` INT)   BEGIN
    SELECT 
        rm.id,
        rm.asset_id,
        a.asset_code,
        a.asset_name,
        a.category,
        rm.schedule_name,
        rm.maintenance_type,
        rm.next_due_date,
        DATEDIFF(rm.next_due_date, CURDATE()) as days_until_due,
        rm.assigned_to,
        CONCAT(u.first_name, ' ', u.last_name) as assigned_user_name,
        u.email as assigned_user_email
    FROM recurring_maintenance rm
    INNER JOIN assets a ON rm.asset_id = a.id
    LEFT JOIN users u ON rm.assigned_to = u.user_id
    WHERE rm.is_active = 1 
    AND rm.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL p_days DAY)
    ORDER BY rm.next_due_date ASC;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `GetAssetMaintenanceCost` (`p_asset_id` INT) RETURNS DECIMAL(10,2) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE total_cost DECIMAL(10,2);
    
    SELECT COALESCE(SUM(cost), 0) INTO total_cost
    FROM asset_maintenance
    WHERE asset_id = p_asset_id;
    
    RETURN total_cost;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `GetDaysUntilNextMaintenance` (`p_asset_id` INT) RETURNS INT(11) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE days_until INT;
    
    SELECT DATEDIFF(MIN(next_due_date), CURDATE()) INTO days_until
    FROM recurring_maintenance
    WHERE asset_id = p_asset_id 
    AND is_active = 1;
    
    RETURN COALESCE(days_until, 9999);
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `HasOverdueMaintenance` (`p_asset_id` INT) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE has_overdue TINYINT(1);
    
    SELECT COUNT(*) > 0 INTO has_overdue
    FROM recurring_maintenance
    WHERE asset_id = p_asset_id 
    AND is_active = 1 
    AND next_due_date < CURDATE();
    
    RETURN has_overdue;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'user_created', 'Created new user account: eephin (zjenphin@gmail.com)', '::1', NULL, '2025-10-26 19:06:35'),
(2, 1, 'user_created', 'Created new user account: eephin (zjenphin@gmail.com)', '::1', NULL, '2025-10-26 19:07:33'),
(3, 11, 'password_reset', 'Password reset successfully', '::1', NULL, '2025-10-26 19:11:41'),
(4, 11, 'password_reset_requested', 'Password reset requested', '::1', NULL, '2025-10-26 19:18:18'),
(5, 11, 'password_reset_requested', 'Password reset requested', '::1', NULL, '2025-10-26 19:22:02'),
(6, 11, 'password_reset_requested', 'Password reset requested', '::1', NULL, '2025-10-26 19:22:33'),
(7, 11, 'password_reset', 'Password reset successfully', '::1', NULL, '2025-10-26 19:23:23'),
(8, 1, 'user_created', 'Created new user account: testpotato (p23015253@student.newinti.edu.my)', '::1', NULL, '2025-10-27 12:48:08');

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(11) NOT NULL,
  `asset_name` varchar(255) NOT NULL,
  `asset_code` varchar(100) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(10,2) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `last_maintenance_date` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `status` enum('available','in_use','maintenance','retired') DEFAULT 'available',
  `description` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assets`
--

INSERT INTO `assets` (`id`, `asset_name`, `asset_code`, `category`, `brand`, `model`, `serial_number`, `purchase_date`, `purchase_cost`, `supplier`, `warranty_expiry`, `last_maintenance_date`, `location`, `department`, `status`, `description`, `assigned_to`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 'dds', 'AST-003', 'Computer', 'dd', 'dd', 'dd', '2025-10-17', 123.00, 'dd', '2029-12-17', NULL, 'dd', 'IT', 'in_use', '', 3, 1, '2025-10-17 07:37:41', '2025-10-27 18:36:25'),
(3, 'aa', 'AST-002', 'Computer', 'aa', 'aa', 'aa', '2025-10-23', 123.00, 'aa', '2025-12-23', NULL, 'aa', 'IT', 'maintenance', '', 2, 1, '2025-10-23 09:20:49', '2025-10-27 13:38:58'),
(4, 'Asus rog', 'AST-001', 'Laptop', 'Asus', 'rog', 'N3NRKD00954113A', '2025-10-26', 15000.00, 'asus', '2027-10-26', NULL, 'hq', 'IT', 'in_use', 'New laptop', 1, 1, '2025-10-26 11:32:36', '2025-10-27 13:38:44');

-- --------------------------------------------------------

--
-- Table structure for table `assets_history`
--

CREATE TABLE `assets_history` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `action_type` enum('created','assigned','unassigned','maintenance','retired','updated') DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `assigned_from` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assets_history`
--

INSERT INTO `assets_history` (`id`, `asset_id`, `action_type`, `old_value`, `new_value`, `assigned_from`, `assigned_to`, `performed_by`, `notes`, `created_at`) VALUES
(1, 3, 'assigned', NULL, NULL, 2, 2, 1, NULL, '2025-10-26 11:30:29'),
(2, 3, 'assigned', NULL, NULL, 2, 8, 1, NULL, '2025-10-26 11:30:34'),
(3, 3, 'assigned', NULL, NULL, 8, 3, 1, NULL, '2025-10-26 11:30:38'),
(4, 3, 'assigned', NULL, NULL, 3, 2, 1, NULL, '2025-10-26 13:18:22'),
(5, 3, 'assigned', NULL, NULL, 2, 8, 1, NULL, '2025-10-26 13:18:28'),
(6, 2, 'assigned', NULL, NULL, 1, 2, 1, NULL, '2025-10-26 13:22:14'),
(7, 3, 'assigned', NULL, NULL, 8, 1, 1, NULL, '2025-10-27 07:23:31'),
(8, 3, 'assigned', NULL, NULL, 1, 3, 1, NULL, '2025-10-27 07:26:24'),
(9, 4, 'unassigned', NULL, NULL, 11, NULL, 1, NULL, '2025-10-27 07:43:49'),
(10, 4, 'assigned', NULL, NULL, NULL, 1, 1, NULL, '2025-10-27 07:44:21'),
(11, 3, 'assigned', NULL, NULL, 3, 2, 1, NULL, '2025-10-27 13:19:05'),
(12, 2, 'assigned', NULL, NULL, 2, 3, 1, NULL, '2025-10-27 13:19:11');

-- --------------------------------------------------------

--
-- Stand-in structure for view `assets_in_use`
-- (See below for the actual view)
--
CREATE TABLE `assets_in_use` (
`id` int(11)
,`asset_name` varchar(255)
,`asset_code` varchar(100)
,`category` varchar(100)
,`brand` varchar(100)
,`model` varchar(100)
,`assigned_user_name` varchar(201)
,`user_email` varchar(255)
,`user_department` varchar(100)
,`assigned_to` int(11)
,`location` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `assets_summary`
-- (See below for the actual view)
--
CREATE TABLE `assets_summary` (
`category` varchar(100)
,`total_assets` bigint(21)
,`available` decimal(22,0)
,`in_use` decimal(22,0)
,`maintenance` decimal(22,0)
,`retired` decimal(22,0)
,`total_value` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `asset_maintenance`
--

CREATE TABLE `asset_maintenance` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `maintenance_type` varchar(100) NOT NULL,
  `maintenance_date` date NOT NULL,
  `performed_by` varchar(255) NOT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `next_maintenance_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created this record',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `asset_maintenance`
--

INSERT INTO `asset_maintenance` (`id`, `asset_id`, `maintenance_type`, `maintenance_date`, `performed_by`, `cost`, `notes`, `next_maintenance_date`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'Preventive Maintenance', '2025-10-24', 'ASUS', NULL, '', '2025-11-08', 1, '2025-10-24 05:10:35', '2025-10-24 05:10:35'),
(2, 3, 'Preventive Maintenance', '2025-10-24', 'me', NULL, 'done do', NULL, 1, '2025-10-24 05:21:25', '2025-10-24 05:21:25'),
(3, 3, 'Preventive Maintenance', '2025-10-24', 'me', NULL, 'done do', NULL, 1, '2025-10-24 05:21:30', '2025-10-24 05:21:30');

-- --------------------------------------------------------

--
-- Stand-in structure for view `asset_maintenance_stats`
-- (See below for the actual view)
--
CREATE TABLE `asset_maintenance_stats` (
`asset_id` int(11)
,`asset_code` varchar(100)
,`asset_name` varchar(255)
,`category` varchar(100)
,`status` enum('available','in_use','maintenance','retired')
,`total_maintenance_count` bigint(21)
,`total_maintenance_cost` decimal(32,2)
,`last_maintenance_date` date
,`first_maintenance_date` date
,`active_schedules_count` bigint(21)
,`overdue_maintenance_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversations`
--

CREATE TABLE `chat_conversations` (
  `id` int(11) NOT NULL,
  `type` enum('direct','group') DEFAULT 'direct',
  `name` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_conversations`
--

INSERT INTO `chat_conversations` (`id`, `type`, `name`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'direct', NULL, 1, '2025-10-23 18:05:14', '2025-10-27 07:17:51'),
(2, 'direct', NULL, 1, '2025-10-23 18:05:15', '2025-10-27 04:49:39'),
(3, 'direct', NULL, 3, '2025-10-23 18:12:49', '2025-10-23 18:12:49'),
(4, 'direct', NULL, 1, '2025-10-25 11:11:43', '2025-10-25 11:11:43'),
(5, 'direct', NULL, 1, '2025-10-27 04:49:33', '2025-10-27 07:17:45'),
(6, 'direct', NULL, 1, '2025-10-27 07:17:34', '2025-10-27 07:17:34');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `message_type` enum('text','file','image','system') DEFAULT 'text',
  `file_path` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_edited` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `conversation_id`, `sender_id`, `message`, `message_type`, `file_path`, `is_read`, `is_edited`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 'hey', 'text', NULL, 1, 0, '2025-10-23 18:05:29', '2025-10-23 18:12:48'),
(2, 2, 3, 'ya', 'text', NULL, 1, 0, '2025-10-23 18:13:08', '2025-10-23 18:31:27'),
(3, 2, 3, 'wuiwuiwui', 'text', NULL, 1, 0, '2025-10-24 02:39:53', '2025-10-24 02:39:54'),
(4, 2, 1, 'hami', 'text', NULL, 1, 0, '2025-10-24 02:40:00', '2025-10-24 02:40:01'),
(5, 2, 1, 'hey', 'text', NULL, 1, 0, '2025-10-25 11:11:50', '2025-10-25 15:08:03'),
(6, 2, 1, 'hello', 'text', NULL, 1, 0, '2025-10-27 04:49:39', '2025-10-27 04:50:10'),
(7, 5, 1, 'hihi', 'text', NULL, 0, 0, '2025-10-27 07:17:45', '2025-10-27 07:17:45'),
(8, 1, 1, 'hihihiih', 'text', NULL, 1, 0, '2025-10-27 07:17:51', '2025-10-27 07:28:47');

-- --------------------------------------------------------

--
-- Table structure for table `chat_message_reads`
--

CREATE TABLE `chat_message_reads` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_participants`
--

CREATE TABLE `chat_participants` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_read_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_participants`
--

INSERT INTO `chat_participants` (`id`, `conversation_id`, `user_id`, `joined_at`, `last_read_at`, `is_active`) VALUES
(1, 1, 1, '2025-10-23 18:05:14', '2025-10-27 07:17:55', 1),
(2, 1, 2, '2025-10-23 18:05:14', '2025-10-27 07:28:55', 1),
(3, 2, 1, '2025-10-23 18:05:15', '2025-10-27 19:53:56', 1),
(4, 2, 3, '2025-10-23 18:05:15', '2025-10-27 04:50:10', 1),
(5, 3, 3, '2025-10-23 18:12:49', '2025-10-24 02:15:21', 1),
(6, 3, 2, '2025-10-23 18:12:49', NULL, 1),
(7, 4, 1, '2025-10-25 11:11:43', '2025-10-27 07:17:39', 1),
(8, 4, 8, '2025-10-25 11:11:43', NULL, 1),
(9, 5, 1, '2025-10-27 04:49:33', '2025-10-27 19:53:52', 1),
(10, 5, 11, '2025-10-27 04:49:33', NULL, 1),
(11, 6, 1, '2025-10-27 07:17:34', '2025-10-27 19:54:03', 1),
(12, 6, 12, '2025-10-27 07:17:34', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `chat_typing`
--

CREATE TABLE `chat_typing` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_users`
--

CREATE TABLE `chat_users` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('online','away','busy','offline') DEFAULT 'offline',
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_status` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_users`
--

INSERT INTO `chat_users` (`id`, `user_id`, `status`, `last_activity`, `custom_status`) VALUES
(1, 1, 'offline', '2025-10-27 19:54:04', NULL),
(23, 3, 'offline', '2025-10-27 07:24:51', NULL),
(236, 2, 'offline', '2025-10-27 07:28:57', NULL),
(239, 11, 'offline', '2025-10-26 11:11:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `dept_id` int(11) NOT NULL,
  `dept_name` varchar(100) NOT NULL,
  `dept_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`dept_id`, `dept_name`, `dept_code`, `description`, `manager_id`, `location`, `budget`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'IT', 'IT', 'Its it', 2, 'west', 10000000.00, 1, '2025-10-24 14:11:49', '2025-10-27 07:18:48'),
(2, 'Human Resources', 'HR', 'Human Resources Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 10:58:44'),
(3, 'Finance', 'FIN', 'Finance and Accounting Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 10:58:44'),
(4, 'Operations', 'OPS', 'Operations Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 10:58:44'),
(5, 'Sales', 'SALES', 'Sales Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 10:58:44'),
(6, 'Marketing', 'MKT', 'Marketing Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 19:30:09'),
(7, 'Engineering', 'ENG', 'Engineering Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 10:58:44'),
(8, 'Support', 'SUP', 'Customer Support Department', NULL, NULL, NULL, 1, '2025-10-26 10:58:44', '2025-10-26 10:58:44');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email_type` enum('account_created','password_reset','account_verified') NOT NULL,
  `sent_to` varchar(255) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed') DEFAULT 'sent',
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `user_id`, `ip_address`, `attempted_at`, `success`) VALUES
(1, 1, '::1', '2025-10-14 11:56:04', 0),
(2, 3, '::1', '2025-10-21 17:44:08', 0),
(3, 3, '::1', '2025-10-23 18:05:39', 0),
(4, 11, '::1', '2025-10-26 11:22:51', 0),
(5, 11, '::1', '2025-10-26 11:22:56', 0),
(6, 11, '::1', '2025-10-26 11:23:01', 0),
(7, 2, '::1', '2025-10-27 04:33:56', 0);

-- --------------------------------------------------------

--
-- Stand-in structure for view `maintenance_cost_by_category`
-- (See below for the actual view)
--
CREATE TABLE `maintenance_cost_by_category` (
`category` varchar(100)
,`asset_count` bigint(21)
,`maintenance_count` bigint(21)
,`total_cost` decimal(32,2)
,`avg_cost_per_maintenance` decimal(14,6)
,`last_maintenance` date
);

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_notifications`
--

CREATE TABLE `maintenance_notifications` (
  `id` int(11) NOT NULL,
  `recurring_maintenance_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_date` date NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maintenance_notifications`
--

INSERT INTO `maintenance_notifications` (`id`, `recurring_maintenance_id`, `user_id`, `notification_date`, `is_read`, `created_at`) VALUES
(1, 1, 1, '2025-11-23', 0, '2025-10-24 05:11:16'),
(4, 2, 1, '2025-11-04', 0, '2025-10-24 05:21:30'),
(5, 3, 1, '2025-11-25', 0, '2025-10-26 11:33:03');

-- --------------------------------------------------------

--
-- Stand-in structure for view `open_tickets_summary`
-- (See below for the actual view)
--
CREATE TABLE `open_tickets_summary` (
`status` enum('open','in_progress','pending','resolved','closed','cancelled')
,`priority` enum('low','medium','high','urgent')
,`ticket_type` enum('repair','maintenance','request_item','request_replacement','inquiry','other')
,`ticket_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_reset_tokens`
--

INSERT INTO `password_reset_tokens` (`id`, `user_id`, `token`, `expires_at`, `used`, `created_at`) VALUES
(1, 11, '99fbdd4f14dd4ec532345dbae50c752b95519d5d7aaca6c4020ffe1b77702126', '2025-10-26 13:14:04', 0, '2025-10-26 11:14:04'),
(2, 11, '29c4493959b260b0b9cb3636f68e403e36eba82eb991b5fcf63203d0548db8f9', '2025-10-26 13:15:52', 0, '2025-10-26 11:15:52');

-- --------------------------------------------------------

--
-- Table structure for table `recurring_maintenance`
--

CREATE TABLE `recurring_maintenance` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `schedule_name` varchar(255) NOT NULL,
  `maintenance_type` varchar(100) NOT NULL,
  `frequency_days` int(11) NOT NULL COMMENT 'Frequency in days (e.g., 30 for monthly)',
  `start_date` date NOT NULL,
  `next_due_date` date NOT NULL,
  `last_completed_date` datetime DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `notify_days_before` int(11) DEFAULT 7 COMMENT 'Show notification X days before due date',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '1=Active, 0=Inactive',
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created this schedule',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `recurring_maintenance`
--

INSERT INTO `recurring_maintenance` (`id`, `asset_id`, `schedule_name`, `maintenance_type`, `frequency_days`, `start_date`, `next_due_date`, `last_completed_date`, `assigned_to`, `notify_days_before`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'checking up', 'Preventive Maintenance', 30, '2025-10-24', '2025-11-23', NULL, 1, 7, 1, 1, '2025-10-24 05:11:16', '2025-10-24 05:11:16'),
(2, 3, 'checking up', 'Preventive Maintenance', 7, '2025-10-14', '2025-11-04', '2025-10-24 13:21:30', 1, 3, 1, 1, '2025-10-24 05:20:59', '2025-10-24 05:21:30'),
(3, 4, 'checking up', 'Preventive Maintenance', 30, '2025-10-26', '2025-11-25', NULL, 1, 7, 1, 1, '2025-10-26 11:33:03', '2025-10-26 11:33:03');

--
-- Triggers `recurring_maintenance`
--
DELIMITER $$
CREATE TRIGGER `create_maintenance_notification` AFTER INSERT ON `recurring_maintenance` FOR EACH ROW BEGIN
    -- If assigned to a user and is active, create initial notification entry
    IF NEW.assigned_to IS NOT NULL AND NEW.is_active = 1 THEN
        INSERT INTO maintenance_notifications (recurring_maintenance_id, user_id, notification_date, is_read)
        VALUES (NEW.id, NEW.assigned_to, NEW.next_due_date, 0);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_maintenance_notification` AFTER UPDATE ON `recurring_maintenance` FOR EACH ROW BEGIN
    -- If next_due_date changed and assigned to someone, update notification
    IF NEW.next_due_date != OLD.next_due_date AND NEW.assigned_to IS NOT NULL AND NEW.is_active = 1 THEN
        -- Delete old notifications for this recurring maintenance
        DELETE FROM maintenance_notifications 
        WHERE recurring_maintenance_id = NEW.id 
        AND is_read = 0;
        
        -- Create new notification
        INSERT INTO maintenance_notifications (recurring_maintenance_id, user_id, notification_date, is_read)
        VALUES (NEW.id, NEW.assigned_to, NEW.next_due_date, 0);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `ticket_id` int(11) NOT NULL,
  `ticket_number` varchar(50) NOT NULL,
  `ticket_type` enum('repair','maintenance','request_item','request_replacement','inquiry','other') NOT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('open','in_progress','pending','resolved','closed','cancelled') DEFAULT 'open',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `requester_id` int(11) NOT NULL,
  `requester_department` varchar(100) DEFAULT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `resolution` text DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `due_date` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`ticket_id`, `ticket_number`, `ticket_type`, `subject`, `description`, `priority`, `status`, `approval_status`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `rejection_reason`, `manager_notes`, `requester_id`, `requester_department`, `asset_id`, `assigned_to`, `assigned_at`, `resolution`, `resolved_by`, `resolved_at`, `resolution_notes`, `closed_by`, `closed_at`, `created_at`, `updated_at`, `due_date`) VALUES
(1, 'TKT-202510-00001', 'repair', 'laptop blackscreen', 'the laptop screen has some problem', 'medium', 'open', 'approved', 2, '2025-10-25 12:41:25', NULL, NULL, NULL, 'yes', 1, 'IT', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-17 02:15:08', '2025-10-25 04:41:25', NULL),
(2, 'TKT-202510-00002', 'repair', 'laptop blackscreen', 'the laptop screen has some problem', 'medium', 'open', 'approved', 2, '2025-10-25 12:41:00', NULL, NULL, NULL, 'yes', 1, 'IT', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-17 02:15:43', '2025-10-25 04:41:00', NULL),
(3, 'TKT-202510-00003', 'maintenance', 'laptop blackscreen', 'dasdasdasdasdasdasdasdasdasd', 'urgent', 'resolved', 'approved', 2, '2025-10-25 12:38:29', NULL, NULL, NULL, '', 1, 'IT', 2, 1, '2025-10-17 16:14:40', 'done repiar', 1, '2025-10-24 02:12:29', NULL, NULL, NULL, '2025-10-17 16:14:08', '2025-10-25 04:38:29', NULL),
(4, 'TKT-202510-00004', 'repair', 'laptop blackscreen', 'cannot use', 'urgent', 'closed', 'rejected', 2, '2025-10-25 11:49:07', NULL, NULL, NULL, 'no need', 3, 'IT', NULL, 1, '2025-10-24 02:30:54', NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-24 02:16:00', '2025-10-25 03:49:07', NULL),
(5, 'TKT-202510-00005', 'repair', 'laptop blackscreen', '121212121212', 'medium', 'open', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 3, 'IT', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-26 19:31:02', '2025-10-26 19:31:02', NULL),
(6, 'TKT-202510-00006', 'request_item', 'laptop blackscreen', '12312312312312', 'urgent', 'open', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 3, 'IT', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-26 19:34:53', '2025-10-26 19:34:53', NULL),
(7, 'TKT-202510-00007', 'request_item', 'laptop blackscreen', '12312312312312', 'urgent', 'open', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 3, 'IT', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-26 19:36:11', '2025-10-26 19:45:35', NULL);

--
-- Triggers `tickets`
--
DELIMITER $$
CREATE TRIGGER `after_ticket_update` AFTER UPDATE ON `tickets` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO ticket_history (ticket_id, action_type, old_value, new_value, performed_by)
        VALUES (NEW.ticket_id, 'status_changed', OLD.status, NEW.status, NEW.assigned_to);
    END IF;
    
    IF OLD.priority != NEW.priority THEN
        INSERT INTO ticket_history (ticket_id, action_type, old_value, new_value, performed_by)
        VALUES (NEW.ticket_id, 'priority_changed', OLD.priority, NEW.priority, NEW.assigned_to);
    END IF;
    
    IF (OLD.assigned_to IS NULL AND NEW.assigned_to IS NOT NULL) OR (OLD.assigned_to != NEW.assigned_to) THEN
        INSERT INTO ticket_history (ticket_id, action_type, old_value, new_value, performed_by)
        VALUES (NEW.ticket_id, 'reassigned', OLD.assigned_to, NEW.assigned_to, NEW.assigned_to);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_ticket_insert` BEFORE INSERT ON `tickets` FOR EACH ROW BEGIN
    IF NEW.ticket_number IS NULL OR NEW.ticket_number = '' THEN
        SET NEW.ticket_number = CONCAT('TKT-', YEAR(NOW()), LPAD(MONTH(NOW()), 2, '0'), '-', LPAD((SELECT COALESCE(MAX(ticket_id), 0) + 1 FROM tickets), 5, '0'));
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `tickets_overview`
-- (See below for the actual view)
--
CREATE TABLE `tickets_overview` (
`ticket_id` int(11)
,`ticket_number` varchar(50)
,`ticket_type` enum('repair','maintenance','request_item','request_replacement','inquiry','other')
,`subject` varchar(255)
,`priority` enum('low','medium','high','urgent')
,`status` enum('open','in_progress','pending','resolved','closed','cancelled')
,`requester_name` varchar(201)
,`requester_email` varchar(255)
,`requester_department` varchar(100)
,`assigned_to_name` varchar(201)
,`asset_name` varchar(255)
,`asset_code` varchar(100)
,`created_at` timestamp
,`due_date` timestamp
,`resolved_at` timestamp
,`timeline_status` varchar(11)
);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `attachment_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_attachments`
--

INSERT INTO `ticket_attachments` (`attachment_id`, `ticket_id`, `uploaded_by`, `file_name`, `file_path`, `file_type`, `file_size`, `created_at`) VALUES
(2, 7, 3, 'Screenshot 2024-03-10 144141.png', '../uploads/tickets/7_1761507371_68fe782b2fb2f.png', 'image/png', 1207437, '2025-10-26 19:36:11');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_comments`
--

CREATE TABLE `ticket_comments` (
  `comment_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `is_internal` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_comments`
--

INSERT INTO `ticket_comments` (`comment_id`, `ticket_id`, `user_id`, `comment`, `is_internal`, `created_at`, `updated_at`) VALUES
(1, 4, 1, 'HI', 0, '2025-10-24 02:56:58', '2025-10-24 02:56:58'),
(2, 7, 3, 'Hi please help admin', 0, '2025-10-26 19:45:35', '2025-10-26 19:45:35'),
(3, 3, 1, 'already fix please dont change', 1, '2025-10-28 06:02:15', '2025-10-28 06:02:15');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_history`
--

CREATE TABLE `ticket_history` (
  `history_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `action_type` enum('created','status_changed','assigned','reassigned','priority_changed','commented','resolved','closed','reopened','updated') NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_history`
--

INSERT INTO `ticket_history` (`history_id`, `ticket_id`, `action_type`, `old_value`, `new_value`, `performed_by`, `notes`, `created_at`) VALUES
(1, 1, 'created', NULL, 'TKT-202510-00001', 1, NULL, '2025-10-17 02:15:08'),
(2, 2, 'created', NULL, 'TKT-202510-00002', 1, NULL, '2025-10-17 02:15:43'),
(3, 3, 'created', NULL, 'TKT-202510-00003', 1, NULL, '2025-10-17 16:14:08'),
(4, 3, 'status_changed', 'open', 'in_progress', 1, NULL, '2025-10-17 16:14:40'),
(5, 3, 'reassigned', NULL, '1', 1, NULL, '2025-10-17 16:14:40'),
(6, 3, 'assigned', NULL, '1', 1, NULL, '2025-10-17 16:14:40'),
(7, 3, 'status_changed', 'in_progress', 'open', 1, NULL, '2025-10-18 11:07:00'),
(8, 3, 'status_changed', NULL, 'open', 1, NULL, '2025-10-18 11:07:00'),
(9, 3, 'status_changed', 'open', 'resolved', 1, NULL, '2025-10-18 11:21:07'),
(10, 3, 'status_changed', NULL, 'resolved', 1, NULL, '2025-10-18 11:21:07'),
(11, 3, 'status_changed', 'resolved', 'pending', 1, NULL, '2025-10-18 11:53:39'),
(12, 3, 'status_changed', NULL, 'pending', 1, NULL, '2025-10-18 11:53:39'),
(13, 3, 'status_changed', 'pending', 'resolved', 1, NULL, '2025-10-24 02:12:29'),
(14, 3, 'status_changed', NULL, 'resolved', 1, NULL, '2025-10-24 02:12:29'),
(15, 4, 'created', NULL, 'Ticket created: TKT-202510-00004', 3, NULL, '2025-10-24 02:16:00'),
(16, 4, 'status_changed', 'open', 'in_progress', 1, NULL, '2025-10-24 02:30:54'),
(17, 4, 'reassigned', NULL, '1', 1, NULL, '2025-10-24 02:30:54'),
(18, 4, 'assigned', NULL, '1', 1, NULL, '2025-10-24 02:30:54'),
(19, 4, 'commented', NULL, NULL, 1, 'HI', '2025-10-24 02:56:58'),
(20, 4, 'status_changed', 'in_progress', 'closed', 1, NULL, '2025-10-25 03:49:07'),
(21, 4, '', NULL, 'rejected', 2, 'Manager rejected: no need', '2025-10-25 03:49:07'),
(22, 3, '', NULL, 'approved', 2, 'Manager approved: ', '2025-10-25 04:38:29'),
(23, 2, '', NULL, 'approved', 2, 'Manager approved: yes', '2025-10-25 04:41:00'),
(24, 1, '', NULL, 'approved', 2, 'Manager approved: yes', '2025-10-25 04:41:25'),
(25, 5, 'created', NULL, 'Ticket created: TKT-202510-00005', 3, NULL, '2025-10-26 19:31:02'),
(26, 6, 'created', NULL, 'Ticket created: TKT-202510-00006', 3, NULL, '2025-10-26 19:34:53'),
(27, 7, 'created', NULL, 'Ticket created: TKT-202510-00007', 3, NULL, '2025-10-26 19:36:11'),
(28, 7, '', NULL, 'Comment added by requester', 3, NULL, '2025-10-26 19:45:35'),
(29, 3, 'commented', NULL, NULL, 1, 'already fix please dont change', '2025-10-28 06:02:15');

-- --------------------------------------------------------

--
-- Stand-in structure for view `upcoming_maintenance_view`
-- (See below for the actual view)
--
CREATE TABLE `upcoming_maintenance_view` (
`id` int(11)
,`asset_id` int(11)
,`asset_code` varchar(100)
,`asset_name` varchar(255)
,`category` varchar(100)
,`schedule_name` varchar(255)
,`maintenance_type` varchar(100)
,`next_due_date` date
,`notify_days_before` int(11)
,`days_until_due` int(7)
,`assigned_to` int(11)
,`assigned_user_name` varchar(201)
,`assigned_user_email` varchar(255)
,`status` varchar(9)
);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `temp_password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `role` enum('admin','manager','employee') DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `force_password_reset` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `last_device_sn` varchar(255) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  `password_reset_expiry` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) DEFAULT 0,
  `password_changed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `status` enum('active','assigned','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `email`, `username`, `password_hash`, `temp_password`, `phone`, `department`, `role`, `employee_id`, `is_active`, `is_verified`, `force_password_reset`, `created_at`, `updated_at`, `last_login`, `verified_at`, `verified_by`, `rejected_at`, `rejected_by`, `rejection_reason`, `last_ip`, `last_device_sn`, `last_login_at`, `password_reset_token`, `password_reset_expiry`, `must_change_password`, `password_changed_at`, `created_by`, `status`) VALUES
(1, 'test', '1', 'test01@test.com', 'test1', '$2y$10$s5lEP8lK9qUJ4XSziatHcemORu.dNACepsYPNt8TVnS.lNHliDoEC', NULL, '0123456789', 'IT', 'admin', 'EMP-01', 1, 1, 0, '2025-10-13 07:37:01', '2025-10-28 06:03:13', '2025-10-28 06:03:13', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 'active'),
(2, 'test', '2', 'test2@test.com', 'test2', '$2y$10$hrDlVCP0C4nBXE1YJvVL0eJ7U4fa0YrXmz4R6rbKgIzxmT860hNgq', NULL, '01234567899', 'IT', 'manager', 'EMP-02', 1, 1, 0, '2025-10-18 11:05:07', '2025-10-27 07:27:00', '2025-10-27 07:27:00', '2025-10-20 17:03:18', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 'active'),
(3, 'test', '3', 'test3@test.com', 'test3', '$2y$10$3TLin8GQlyNAw/n9X.bDL.NLJMXNkTMV4D3Ynfiiq1s9DIPUMQYsa', NULL, '0123456789', 'IT', 'employee', 'EMP-03', 1, 1, 0, '2025-10-21 16:48:41', '2025-10-28 06:02:30', '2025-10-28 06:02:30', '2025-10-21 16:50:33', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 'active'),
(8, 'test', '4', 'test4@test.com', 'test4', '$2y$10$4Yoc13qk4SFHubHk8XdRfOCAsQ5KQv.tsenmC1uGYcvoEBaHCbnjG', NULL, '01124230109', 'IT', 'employee', 'EMP-04', 0, 0, 0, '2025-10-24 14:13:39', '2025-10-27 18:40:16', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 'active'),
(11, 'Ee', 'phin', 'zjenphin@gmail.com', 'eephin', '$argon2id$v=19$m=65536,t=4,p=1$Um94c0I1WHcvNVJoL3h6OQ$y89mtaomasyzDNtdBRESZKxSMuRGoYWHkvWWBSpTzIE', NULL, '0123456789', 'Marketing', 'employee', 'EMP-05', 1, 1, 0, '2025-10-26 11:07:29', '2025-10-26 11:23:53', '2025-10-26 11:23:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, '2025-10-26 19:23:23', 1, 'active'),
(12, 'test', 'potato', 'p23015253@student.newinti.edu.my', 'testpotato', '$argon2id$v=19$m=65536,t=4,p=1$S0RzQW9xb3I0a3VVY0tCSg$8ytnAiBEgdn9kRv96C01PSFJFcPGas31CIwxqArQBps', NULL, '01124230109', 'IT', 'admin', 'EMP-06', 1, 0, 0, '2025-10-27 04:48:04', '2025-10-27 04:48:04', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '80bd264330783d1b90b869c7d91f5b19e93423eaa3ef1c187bc3074587ce71a2', '2025-10-28 05:48:04', 1, NULL, 1, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `user_rejections`
--

CREATE TABLE `user_rejections` (
  `rejection_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rejected_by` int(11) NOT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rejected_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `device_serial` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` datetime DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `ip_address`, `device_serial`, `user_agent`, `login_time`, `last_activity`, `is_active`) VALUES
(2, 3, '::1 (localhost)', 'DEV-EC88B3BD2E984242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-23 23:58:11', '2025-10-25 12:38:13', 0),
(3, 1, '::1 (localhost)', 'DEV-47E1153F10ED8BFA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-24 00:15:56', '2025-10-25 18:50:05', 0),
(4, 1, '::1 (localhost)', 'DEV-EC88B3BD2E984242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-24 02:05:10', '2025-10-25 14:27:41', 0),
(5, 3, '::1 (localhost)', 'DEV-47E1153F10ED8BFA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-24 10:39:23', '2025-10-25 20:08:25', 0),
(6, 1, '::1 (localhost)', 'DEV-EC88B3BD2E984242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-25 18:50:05', '2025-10-28 14:16:09', 1),
(7, 3, '::1 (localhost)', 'DEV-EC88B3BD2E984242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-25 23:07:58', '2025-10-26 23:09:52', 0),
(8, 11, '::1 (localhost)', 'DEV-47E1153F10ED8BFA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-26 19:11:54', '2025-10-28 03:22:33', 0),
(9, 2, '::1 (localhost)', 'DEV-EC88B3BD2E984242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-27 03:19:58', '2025-10-27 15:29:24', 1),
(10, 3, '::1 (localhost)', 'DEV-EC88B3BD2E984242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-27 03:30:32', '2025-10-28 14:02:51', 1);

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_ticket_stats`
-- (See below for the actual view)
--
CREATE TABLE `user_ticket_stats` (
`user_id` int(11)
,`user_name` varchar(201)
,`department` varchar(100)
,`tickets_created` bigint(21)
,`tickets_assigned` bigint(21)
,`tickets_resolved` bigint(21)
);

-- --------------------------------------------------------

--
-- Structure for view `assets_in_use`
--
DROP TABLE IF EXISTS `assets_in_use`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `assets_in_use`  AS SELECT `a`.`id` AS `id`, `a`.`asset_name` AS `asset_name`, `a`.`asset_code` AS `asset_code`, `a`.`category` AS `category`, `a`.`brand` AS `brand`, `a`.`model` AS `model`, concat(`u`.`first_name`,' ',`u`.`last_name`) AS `assigned_user_name`, `u`.`email` AS `user_email`, `u`.`department` AS `user_department`, `a`.`assigned_to` AS `assigned_to`, `a`.`location` AS `location` FROM (`assets` `a` join `users` `u` on(`a`.`assigned_to` = `u`.`user_id`)) WHERE `a`.`status` = 'in_use' ;

-- --------------------------------------------------------

--
-- Structure for view `assets_summary`
--
DROP TABLE IF EXISTS `assets_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `assets_summary`  AS SELECT `assets`.`category` AS `category`, count(0) AS `total_assets`, sum(case when `assets`.`status` = 'available' then 1 else 0 end) AS `available`, sum(case when `assets`.`status` = 'in_use' then 1 else 0 end) AS `in_use`, sum(case when `assets`.`status` = 'maintenance' then 1 else 0 end) AS `maintenance`, sum(case when `assets`.`status` = 'retired' then 1 else 0 end) AS `retired`, sum(`assets`.`purchase_cost`) AS `total_value` FROM `assets` GROUP BY `assets`.`category` ;

-- --------------------------------------------------------

--
-- Structure for view `asset_maintenance_stats`
--
DROP TABLE IF EXISTS `asset_maintenance_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `asset_maintenance_stats`  AS SELECT `a`.`id` AS `asset_id`, `a`.`asset_code` AS `asset_code`, `a`.`asset_name` AS `asset_name`, `a`.`category` AS `category`, `a`.`status` AS `status`, count(distinct `am`.`id`) AS `total_maintenance_count`, coalesce(sum(`am`.`cost`),0) AS `total_maintenance_cost`, max(`am`.`maintenance_date`) AS `last_maintenance_date`, min(`am`.`maintenance_date`) AS `first_maintenance_date`, count(distinct `rm`.`id`) AS `active_schedules_count`, (select count(0) from `recurring_maintenance` where `recurring_maintenance`.`asset_id` = `a`.`id` and `recurring_maintenance`.`is_active` = 1 and `recurring_maintenance`.`next_due_date` < curdate()) AS `overdue_maintenance_count` FROM ((`assets` `a` left join `asset_maintenance` `am` on(`a`.`id` = `am`.`asset_id`)) left join `recurring_maintenance` `rm` on(`a`.`id` = `rm`.`asset_id` and `rm`.`is_active` = 1)) GROUP BY `a`.`id`, `a`.`asset_code`, `a`.`asset_name`, `a`.`category`, `a`.`status` ;

-- --------------------------------------------------------

--
-- Structure for view `maintenance_cost_by_category`
--
DROP TABLE IF EXISTS `maintenance_cost_by_category`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `maintenance_cost_by_category`  AS SELECT `a`.`category` AS `category`, count(distinct `a`.`id`) AS `asset_count`, count(`am`.`id`) AS `maintenance_count`, coalesce(sum(`am`.`cost`),0) AS `total_cost`, coalesce(avg(`am`.`cost`),0) AS `avg_cost_per_maintenance`, max(`am`.`maintenance_date`) AS `last_maintenance` FROM (`assets` `a` left join `asset_maintenance` `am` on(`a`.`id` = `am`.`asset_id`)) WHERE `a`.`category` is not null AND `a`.`category` <> '' GROUP BY `a`.`category` ORDER BY coalesce(sum(`am`.`cost`),0) DESC ;

-- --------------------------------------------------------

--
-- Structure for view `open_tickets_summary`
--
DROP TABLE IF EXISTS `open_tickets_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `open_tickets_summary`  AS SELECT `tickets`.`status` AS `status`, `tickets`.`priority` AS `priority`, `tickets`.`ticket_type` AS `ticket_type`, count(0) AS `ticket_count` FROM `tickets` WHERE `tickets`.`status` in ('open','in_progress','pending') GROUP BY `tickets`.`status`, `tickets`.`priority`, `tickets`.`ticket_type` ;

-- --------------------------------------------------------

--
-- Structure for view `tickets_overview`
--
DROP TABLE IF EXISTS `tickets_overview`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `tickets_overview`  AS SELECT `t`.`ticket_id` AS `ticket_id`, `t`.`ticket_number` AS `ticket_number`, `t`.`ticket_type` AS `ticket_type`, `t`.`subject` AS `subject`, `t`.`priority` AS `priority`, `t`.`status` AS `status`, concat(`requester`.`first_name`,' ',`requester`.`last_name`) AS `requester_name`, `requester`.`email` AS `requester_email`, `requester`.`department` AS `requester_department`, concat(`assigned`.`first_name`,' ',`assigned`.`last_name`) AS `assigned_to_name`, `a`.`asset_name` AS `asset_name`, `a`.`asset_code` AS `asset_code`, `t`.`created_at` AS `created_at`, `t`.`due_date` AS `due_date`, `t`.`resolved_at` AS `resolved_at`, CASE WHEN `t`.`status` in ('resolved','closed') THEN 'Completed' WHEN `t`.`due_date` < current_timestamp() AND `t`.`status` not in ('resolved','closed') THEN 'Overdue' WHEN `t`.`due_date` is not null THEN 'On Track' ELSE 'No Due Date' END AS `timeline_status` FROM (((`tickets` `t` join `users` `requester` on(`t`.`requester_id` = `requester`.`user_id`)) left join `users` `assigned` on(`t`.`assigned_to` = `assigned`.`user_id`)) left join `assets` `a` on(`t`.`asset_id` = `a`.`id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `upcoming_maintenance_view`
--
DROP TABLE IF EXISTS `upcoming_maintenance_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `upcoming_maintenance_view`  AS SELECT `rm`.`id` AS `id`, `rm`.`asset_id` AS `asset_id`, `a`.`asset_code` AS `asset_code`, `a`.`asset_name` AS `asset_name`, `a`.`category` AS `category`, `rm`.`schedule_name` AS `schedule_name`, `rm`.`maintenance_type` AS `maintenance_type`, `rm`.`next_due_date` AS `next_due_date`, `rm`.`notify_days_before` AS `notify_days_before`, to_days(`rm`.`next_due_date`) - to_days(curdate()) AS `days_until_due`, `rm`.`assigned_to` AS `assigned_to`, concat(`u`.`first_name`,' ',`u`.`last_name`) AS `assigned_user_name`, `u`.`email` AS `assigned_user_email`, CASE WHEN to_days(`rm`.`next_due_date`) - to_days(curdate()) < 0 THEN 'overdue' WHEN to_days(`rm`.`next_due_date`) - to_days(curdate()) <= `rm`.`notify_days_before` THEN 'due_soon' ELSE 'scheduled' END AS `status` FROM ((`recurring_maintenance` `rm` join `assets` `a` on(`rm`.`asset_id` = `a`.`id`)) left join `users` `u` on(`rm`.`assigned_to` = `u`.`user_id`)) WHERE `rm`.`is_active` = 1 ORDER BY `rm`.`next_due_date` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `user_ticket_stats`
--
DROP TABLE IF EXISTS `user_ticket_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `user_ticket_stats`  AS SELECT `u`.`user_id` AS `user_id`, concat(`u`.`first_name`,' ',`u`.`last_name`) AS `user_name`, `u`.`department` AS `department`, count(distinct case when `t`.`requester_id` = `u`.`user_id` then `t`.`ticket_id` end) AS `tickets_created`, count(distinct case when `t`.`assigned_to` = `u`.`user_id` and `t`.`status` not in ('resolved','closed') then `t`.`ticket_id` end) AS `tickets_assigned`, count(distinct case when `t`.`resolved_by` = `u`.`user_id` then `t`.`ticket_id` end) AS `tickets_resolved` FROM (`users` `u` left join `tickets` `t` on(`u`.`user_id` = `t`.`requester_id` or `u`.`user_id` = `t`.`assigned_to` or `u`.`user_id` = `t`.`resolved_by`)) GROUP BY `u`.`user_id`, `u`.`first_name`, `u`.`last_name`, `u`.`department` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `asset_code` (`asset_code`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_asset_code` (`asset_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_assigned_to` (`assigned_to`);

--
-- Indexes for table `assets_history`
--
ALTER TABLE `assets_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_from` (`assigned_from`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `performed_by` (`performed_by`),
  ADD KEY `idx_asset_history` (`asset_id`,`created_at`);

--
-- Indexes for table `asset_maintenance`
--
ALTER TABLE `asset_maintenance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_asset_id` (`asset_id`),
  ADD KEY `idx_maintenance_date` (`maintenance_date`),
  ADD KEY `idx_next_maintenance` (`next_maintenance_date`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_maintenance_type` (`maintenance_type`),
  ADD KEY `idx_performed_by` (`performed_by`(100)),
  ADD KEY `idx_cost` (`cost`);

--
-- Indexes for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_updated_at` (`updated_at`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conversation` (`conversation_id`,`created_at`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_message_created` (`created_at`),
  ADD KEY `idx_unread_messages` (`conversation_id`,`is_read`);

--
-- Indexes for table `chat_message_reads`
--
ALTER TABLE `chat_message_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_read` (`message_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chat_participants`
--
ALTER TABLE `chat_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_participant` (`conversation_id`,`user_id`),
  ADD KEY `idx_user_conversations` (`user_id`,`conversation_id`);

--
-- Indexes for table `chat_typing`
--
ALTER TABLE `chat_typing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_typing` (`conversation_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chat_users`
--
ALTER TABLE `chat_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user` (`user_id`),
  ADD KEY `idx_last_activity` (`last_activity`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`dept_id`),
  ADD UNIQUE KEY `dept_name` (`dept_name`),
  ADD UNIQUE KEY `dept_code` (`dept_code`),
  ADD KEY `manager_id` (`manager_id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_attempts` (`user_id`,`attempted_at`);

--
-- Indexes for table `maintenance_notifications`
--
ALTER TABLE `maintenance_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recurring_maintenance_id` (`recurring_maintenance_id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_notification_date` (`notification_date`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `recurring_maintenance`
--
ALTER TABLE `recurring_maintenance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_asset_id` (`asset_id`),
  ADD KEY `idx_next_due_date` (`next_due_date`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_maintenance_type` (`maintenance_type`),
  ADD KEY `idx_frequency` (`frequency_days`),
  ADD KEY `idx_active_next_due` (`is_active`,`next_due_date`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token_hash`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`ticket_id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `resolved_by` (`resolved_by`),
  ADD KEY `closed_by` (`closed_by`),
  ADD KEY `idx_ticket_number` (`ticket_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_requester` (`requester_id`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_asset` (`asset_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_requester_department` (`requester_department`),
  ADD KEY `idx_approved_by` (`approved_by`),
  ADD KEY `fk_tickets_rejected_by` (`rejected_by`),
  ADD KEY `idx_tickets_approval_status` (`approval_status`),
  ADD KEY `idx_tickets_rejected_at` (`rejected_at`);

--
-- Indexes for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_ticket_attachments` (`ticket_id`);

--
-- Indexes for table `ticket_comments`
--
ALTER TABLE `ticket_comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_ticket_comments` (`ticket_id`,`created_at`);

--
-- Indexes for table `ticket_history`
--
ALTER TABLE `ticket_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `performed_by` (`performed_by`),
  ADD KEY `idx_ticket_history` (`ticket_id`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD KEY `verified_by` (`verified_by`),
  ADD KEY `rejected_by` (`rejected_by`),
  ADD KEY `idx_password_reset_token` (`password_reset_token`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `fk_created_by` (`created_by`);

--
-- Indexes for table `user_rejections`
--
ALTER TABLE `user_rejections`
  ADD PRIMARY KEY (`rejection_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `rejected_by` (`rejected_by`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`),
  ADD KEY `idx_login_time` (`login_time`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `assets_history`
--
ALTER TABLE `assets_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `asset_maintenance`
--
ALTER TABLE `asset_maintenance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `chat_message_reads`
--
ALTER TABLE `chat_message_reads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_participants`
--
ALTER TABLE `chat_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `chat_typing`
--
ALTER TABLE `chat_typing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_users`
--
ALTER TABLE `chat_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=352;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `dept_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `maintenance_notifications`
--
ALTER TABLE `maintenance_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `recurring_maintenance`
--
ALTER TABLE `recurring_maintenance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `ticket_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ticket_comments`
--
ALTER TABLE `ticket_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `ticket_history`
--
ALTER TABLE `ticket_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_rejections`
--
ALTER TABLE `user_rejections`
  MODIFY `rejection_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `assets_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `assets_history`
--
ALTER TABLE `assets_history`
  ADD CONSTRAINT `assets_history_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assets_history_ibfk_2` FOREIGN KEY (`assigned_from`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `assets_history_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `assets_history_ibfk_4` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `asset_maintenance`
--
ALTER TABLE `asset_maintenance`
  ADD CONSTRAINT `asset_maintenance_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `asset_maintenance_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD CONSTRAINT `chat_conversations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_message_reads`
--
ALTER TABLE `chat_message_reads`
  ADD CONSTRAINT `chat_message_reads_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_message_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_participants`
--
ALTER TABLE `chat_participants`
  ADD CONSTRAINT `chat_participants_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_typing`
--
ALTER TABLE `chat_typing`
  ADD CONSTRAINT `chat_typing_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_typing_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_users`
--
ALTER TABLE `chat_users`
  ADD CONSTRAINT `chat_users_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_dept_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD CONSTRAINT `login_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_notifications`
--
ALTER TABLE `maintenance_notifications`
  ADD CONSTRAINT `maintenance_notifications_ibfk_1` FOREIGN KEY (`recurring_maintenance_id`) REFERENCES `recurring_maintenance` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `recurring_maintenance`
--
ALTER TABLE `recurring_maintenance`
  ADD CONSTRAINT `recurring_maintenance_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `recurring_maintenance_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `recurring_maintenance_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_tickets_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_ibfk_4` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `tickets_ibfk_5` FOREIGN KEY (`closed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `ticket_attachments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `ticket_comments`
--
ALTER TABLE `ticket_comments`
  ADD CONSTRAINT `ticket_comments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `ticket_history`
--
ALTER TABLE `ticket_history`
  ADD CONSTRAINT `ticket_history_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_history_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `user_rejections`
--
ALTER TABLE `user_rejections`
  ADD CONSTRAINT `user_rejections_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `user_rejections_ibfk_2` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
