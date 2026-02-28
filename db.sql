-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 28, 2026 at 05:01 PM
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
-- Database: `college_assets`
--

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(11) NOT NULL,
  `asset_code` varchar(80) NOT NULL,
  `asset_name_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `parent_asset_id` int(11) DEFAULT NULL,
  `status` enum('Good','Needs Repair') NOT NULL DEFAULT 'Good',
  `warranty_end` date DEFAULT NULL,
  `dealer_id` int(11) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_names`
--

CREATE TABLE `asset_names` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`) VALUES
(17, 'Carpentry'),
(13, 'Electrical'),
(15, 'Furniture & Fixtures'),
(18, 'General Maintenance'),
(16, 'Networking/IT'),
(14, 'Plumbing');

-- --------------------------------------------------------

--
-- Table structure for table `damage_reports`
--

CREATE TABLE `damage_reports` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `issue_type` enum('Damage','Missing Sticker','Other') NOT NULL DEFAULT 'Damage',
  `description` text DEFAULT NULL,
  `suggested_real_code` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `urgency_priority` enum('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
  `status` enum('pending','assigned','in_progress','completed','resolved') NOT NULL DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dealers`
--

CREATE TABLE `dealers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dealers`
--

INSERT INTO `dealers` (`id`, `name`, `contact`) VALUES
(1, 'Tech Solutions Pvt Ltd', 'Rajesh Kumar - 9876543210'),
(2, 'Office Furniture Co', 'Priya Sharma - 9876543211'),
(3, 'Lab Equipment India', 'Dr. Amit Singh - 9876543212'),
(4, 'Digital Systems Ltd', 'Sarah Johnson - 9876543213'),
(12, 'CampusCare Ltd', 'YaduKrishna -9856874598');

-- --------------------------------------------------------

--
-- Table structure for table `exam_rooms`
--

CREATE TABLE `exam_rooms` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `status_exam_ready` enum('Yes','No') NOT NULL DEFAULT 'Yes',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `building` varchar(100) NOT NULL,
  `floor` varchar(50) DEFAULT NULL,
  `room_no` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `room_type` varchar(50) DEFAULT 'classroom',
  `capacity` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_exam_eligible` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `room_assignments`
--

CREATE TABLE `room_assignments` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_cron_logs`
--

CREATE TABLE `system_cron_logs` (
  `id` int(11) NOT NULL,
  `last_run_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_cron_logs`
--

INSERT INTO `system_cron_logs` (`id`, `last_run_date`) VALUES
(1, '2026-02-28');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `register_number` varchar(50) DEFAULT NULL,
  `email` varchar(200) NOT NULL,
  `telegram_chat_id` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','faculty','admin') NOT NULL DEFAULT 'student',
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `register_number`, `email`, `telegram_chat_id`, `password`, `role`, `is_verified`, `created_at`) VALUES
(1, 'Admin', NULL, 'admin@example.com', '2141223422', '$2y$10$05TfffsPl5f/y.K3TqRBMuLx99XRHP2d8rCEFArD0Aw3ylEPKfQWG', 'admin', 1, '2025-08-28 04:16:12'),
(14, 'faculty1', NULL, 'faculty@example.com', NULL, '$2y$10$crri2qLm/Lp.MP.dwj/68.rHnyR45URVu4RAIirV5JXWUe0mFRM0q', 'faculty', 1, '2025-08-28 04:41:21'),
(15, 'faculty2', NULL, 'faculty2@example.com', NULL, '$2y$10$crri2qLm/Lp.MP.dwj/68.rHnyR45URVu4RAIirV5JXWUe0mFRM0q', 'faculty', 1, '2025-08-28 04:41:37'),
(16, 'student1', NULL, 'student@example.com', '', '$2y$10$crri2qLm/Lp.MP.dwj/68.rHnyR45URVu4RAIirV5JXWUe0mFRM0q', 'student', 1, '2025-08-28 04:42:02'),
(17, 'student2', NULL, 'student2@example.com', '6338672616', '$2y$10$crri2qLm/Lp.MP.dwj/68.rHnyR45URVu4RAIirV5JXWUe0mFRM0q', 'student', 1, '2025-08-28 04:42:21');

-- --------------------------------------------------------

--
-- Table structure for table `workers`
--

CREATE TABLE `workers` (
  `worker_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `workers`
--

INSERT INTO `workers` (`worker_id`, `name`, `contact`, `category_id`, `created_at`) VALUES
(11, 'Ananthu', '9856874598', 14, '2025-09-20 11:52:42'),
(12, 'Shahil', '8856874598', 17, '2025-09-20 11:53:18'),
(13, 'Govind', '9830474598', 18, '2025-09-20 11:54:22');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `asset_code` (`asset_code`),
  ADD KEY `parent_asset_id` (`parent_asset_id`),
  ADD KEY `idx_assets_warranty_end` (`warranty_end`),
  ADD KEY `idx_assets_room_id` (`room_id`),
  ADD KEY `idx_assets_status` (`status`),
  ADD KEY `idx_assets_dealer_id` (`dealer_id`),
  ADD KEY `fk_assets_asset_name` (`asset_name_id`),
  ADD KEY `idx_assets_category` (`category_id`);

--
-- Indexes for table `asset_names`
--
ALTER TABLE `asset_names`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `unique_asset_name` (`name`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_category_name` (`name`);

--
-- Indexes for table `damage_reports`
--
ALTER TABLE `damage_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `reported_by` (`reported_by`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_damage_reports_status` (`status`),
  ADD KEY `idx_damage_reports_urgency` (`urgency_priority`),
  ADD KEY `idx_damage_reports_created_at` (`created_at`);

--
-- Indexes for table `dealers`
--
ALTER TABLE `dealers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_dealer_name` (`name`);

--
-- Indexes for table `exam_rooms`
--
ALTER TABLE `exam_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_room_id` (`room_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_notifications_asset` (`asset_id`),
  ADD KEY `idx_notifications_created_at` (`created_at`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_room` (`building`,`floor`,`room_no`),
  ADD KEY `idx_rooms_type` (`room_type`);

--
-- Indexes for table `room_assignments`
--
ALTER TABLE `room_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_room_faculty` (`room_id`,`faculty_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `system_cron_logs`
--
ALTER TABLE `system_cron_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `telegram_chat_id` (`telegram_chat_id`),
  ADD KEY `idx_users_role` (`role`);

--
-- Indexes for table `workers`
--
ALTER TABLE `workers`
  ADD PRIMARY KEY (`worker_id`),
  ADD KEY `fk_workers_category` (`category_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `asset_names`
--
ALTER TABLE `asset_names`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `damage_reports`
--
ALTER TABLE `damage_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=160;

--
-- AUTO_INCREMENT for table `dealers`
--
ALTER TABLE `dealers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `exam_rooms`
--
ALTER TABLE `exam_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=578;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `room_assignments`
--
ALTER TABLE `room_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `system_cron_logs`
--
ALTER TABLE `system_cron_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `workers`
--
ALTER TABLE `workers`
  MODIFY `worker_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `assets_ibfk_2` FOREIGN KEY (`parent_asset_id`) REFERENCES `assets` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `assets_ibfk_3` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  ADD CONSTRAINT `fk_assets_asset_name` FOREIGN KEY (`asset_name_id`) REFERENCES `asset_names` (`id`),
  ADD CONSTRAINT `fk_assets_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_assets_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`);

--
-- Constraints for table `damage_reports`
--
ALTER TABLE `damage_reports`
  ADD CONSTRAINT `damage_reports_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `damage_reports_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `damage_reports_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `exam_rooms`
--
ALTER TABLE `exam_rooms`
  ADD CONSTRAINT `exam_rooms_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_assignments`
--
ALTER TABLE `room_assignments`
  ADD CONSTRAINT `room_assignments_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `room_assignments_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workers`
--
ALTER TABLE `workers`
  ADD CONSTRAINT `fk_workers_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
