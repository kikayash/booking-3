-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 31, 2025 at 06:40 AM
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
-- Database: `booking`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_addon_options`
--

CREATE TABLE `tbl_addon_options` (
  `id_addon` int(11) NOT NULL,
  `addon_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'equipment',
  `price_per_unit` decimal(10,2) NOT NULL,
  `cost_per_unit` decimal(10,2) DEFAULT 0.00 COMMENT 'Biaya modal per unit',
  `unit_type` enum('jam','paket','unit','orang') DEFAULT 'jam',
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `available_for_cs` tinyint(1) DEFAULT 1 COMMENT 'Tersedia untuk sistem CS',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_addon_options`
--

INSERT INTO `tbl_addon_options` (`id_addon`, `addon_name`, `description`, `category`, `price_per_unit`, `cost_per_unit`, `unit_type`, `status`, `available_for_cs`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Meja', '5', 'equipment', 10000.00, 5000.00, 'paket', 'active', 1, 7, '2025-07-04 04:49:21', '2025-07-04 04:49:21'),
(2, 'Meja', '5', 'equipment', 10000.00, 0.00, 'paket', 'active', 1, 7, '2025-07-04 05:16:54', '2025-07-04 05:16:54');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_booking`
--

CREATE TABLE `tbl_booking` (
  `id_booking` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `nama_acara` varchar(200) NOT NULL,
  `tanggal` date NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `keterangan` text NOT NULL,
  `nama` varchar(100) NOT NULL,
  `no_penanggungjawab` int(15) NOT NULL,
  `status` enum('pending','approve','rejected','cancelled','active','done') NOT NULL DEFAULT 'pending',
  `checked_out_by` varchar(50) DEFAULT NULL,
  `checkout_status` enum('pending','manual_checkout','auto_completed','force_checkout') NOT NULL DEFAULT 'pending',
  `checkout_time` datetime DEFAULT NULL,
  `completion_note` varchar(255) DEFAULT NULL,
  `is_external` tinyint(1) NOT NULL DEFAULT 0,
  `activated_at` datetime DEFAULT NULL,
  `activated_by` varchar(50) DEFAULT NULL,
  `activation_note` text DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `approval_reason` text DEFAULT NULL,
  `cancelled_by` varchar(50) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `auto_approved` tinyint(1) DEFAULT 0 COMMENT 'Was this booking auto-approved?',
  `auto_approval_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for auto-approval',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_schedule` int(11) DEFAULT NULL COMMENT 'Jika booking ini dari jadwal kuliah berulang',
  `booking_type` enum('manual','recurring','external') DEFAULT 'manual' COMMENT 'Jenis booking',
  `auto_generated` tinyint(1) DEFAULT 0,
  `created_by_cs` tinyint(1) DEFAULT 0 COMMENT 'Dibuat oleh CS',
  `cs_user_id` int(11) DEFAULT NULL COMMENT 'ID user CS yang membuat booking',
  `addon_total_cost` decimal(10,2) DEFAULT 0.00 COMMENT 'Total biaya add-on',
  `base_price` decimal(10,2) DEFAULT 0.00 COMMENT 'Harga dasar ruangan',
  `addon_total` decimal(10,2) DEFAULT 0.00 COMMENT 'Total harga add-on',
  `total_amount` decimal(10,2) GENERATED ALWAYS AS (`base_price` + `addon_total`) STORED COMMENT 'Total pembayaran',
  `payment_status` enum('pending','paid','partial','refund') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `karyawan_id` int(11) DEFAULT NULL COMMENT 'Direct reference ke dbIRIS.tblKaryawan',
  `user_type` enum('local','dosen_iris','external') DEFAULT 'local' COMMENT 'Tipe user',
  `nik_dosen` varchar(20) DEFAULT NULL COMMENT 'NIK dosen dari dbIRIS',
  `nama_dosen` varchar(200) DEFAULT NULL COMMENT 'Nama dosen dari dbIRIS',
  `email_dosen` varchar(100) DEFAULT NULL COMMENT 'Email dosen dari dbIRIS',
  `tahun_akademik_info` varchar(20) DEFAULT NULL COMMENT 'Tahun akademik (2024/2025)',
  `periode_info` varchar(20) DEFAULT NULL COMMENT 'Ganjil/Genap/Pendek',
  `catatan_dosen` text DEFAULT NULL COMMENT 'Catatan tambahan dari dosen'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_booking`
--

INSERT INTO `tbl_booking` (`id_booking`, `id_user`, `id_ruang`, `nama_acara`, `tanggal`, `jam_mulai`, `jam_selesai`, `keterangan`, `nama`, `no_penanggungjawab`, `status`, `checked_out_by`, `checkout_status`, `checkout_time`, `completion_note`, `is_external`, `activated_at`, `activated_by`, `activation_note`, `approved_at`, `approved_by`, `approval_reason`, `cancelled_by`, `cancelled_at`, `cancellation_reason`, `auto_approved`, `auto_approval_reason`, `created_at`, `id_schedule`, `booking_type`, `auto_generated`, `created_by_cs`, `cs_user_id`, `addon_total_cost`, `base_price`, `addon_total`, `payment_status`, `payment_method`, `payment_date`, `karyawan_id`, `user_type`, `nik_dosen`, `nama_dosen`, `email_dosen`, `tahun_akademik_info`, `periode_info`, `catatan_dosen`) VALUES
(1, 1, 1, 'tentir', '2025-05-22', '09:00:00', '12:00:00', 'tentir rutin ukm wappim', 'kikan', 2147483647, 'cancelled', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 1, 'rapat', '2025-05-22', '12:00:00', '14:00:00', 'rapat ukm', 'kikan', 12933823, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 1, 2, 'rapat', '2025-05-22', '10:00:00', '11:00:00', 'rapat ukm', 'kikan', 12345, '', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 1, 1, 'tentir', '2025-05-23', '15:30:00', '16:30:00', 'ukm wappim', 'kikan', 12345654, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 2, 1, 'seminar', '2025-05-26', '12:30:00', '13:00:00', 'seminar satgas', 'pak agus', 85363723, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 1, 1, 'tentir', '2025-05-26', '13:00:00', '14:00:00', 'ukm', 'kina', 3374, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 1, 2, 'rapat', '2025-05-27', '08:00:00', '09:00:00', 'rapat ukm', 'kina', 846383, 'done', 'SYSTEM_AUTO', '', '2025-05-28 20:59:52', 'Ruangan selesai dipakai tanpa checkout dari mahasiswa', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 1, 1, 'rapat', '2025-05-30', '12:00:00', '13:00:00', 'rapat ukm wappim', 'ayasha', 547834875, 'done', 'SYSTEM_AUTO', '', '2025-05-31 17:09:09', 'Ruangan selesai dipakai tanpa checkout dari mahasiswa', 0, '2025-05-30 12:03:14', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:14:30', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 1, 1, 'Demo Checkout Manual', '2025-05-30', '10:00:00', '11:00:00', 'Demo booking dengan checkout manual', 'Demo User', 2147483647, 'done', 'USER_MANUAL', 'manual_checkout', '2025-05-30 11:00:00', 'Ruangan sudah selesai dipakai dengan checkout mahasiswa', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-31 16:20:12', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 1, 2, 'Demo Auto Checkout', '2025-05-30', '14:00:00', '15:00:00', 'Demo booking dengan auto checkout', 'Demo User', 2147483647, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-05-30 15:05:00', 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-31 16:20:12', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 2, 1, 'diesn', '2025-06-01', '08:30:00', '09:30:00', 'diesnatalis ukm kesenian', 'kinoy', 847549374, 'rejected', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'salah input', 0, NULL, '2025-06-01 01:09:33', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 1, 1, 'rapat', '2025-06-02', '15:30:00', '16:30:00', 'ukm wappim', 'ayasha', 2147483647, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-02 17:32:30', 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa', 0, '2025-06-02 15:30:04', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', '2025-06-02 15:15:36', 'admin@stie-mce.ac.id', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-02 08:13:50', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 1, 3, 'tentir', '2025-06-03', '12:00:00', '13:00:00', 'tentir ki', 'kina', 99587485, 'done', 'USER_MANUAL', 'manual_checkout', '2025-06-03 12:32:54', 'Checkout manual oleh mahasiswa: kina', 0, '2025-06-03 12:26:27', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', '2025-06-03 12:26:24', 'admin@stie-mce.ac.id', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-03 01:39:21', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 2, 2, 'Sempro', '2025-06-05', '09:00:00', '10:00:00', 'fjhdkrnbdm', 'pak aa', 8574764, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-05 11:01:24', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-06-05 01:23:50', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(36, 5, 3, 'Fundamental Accounting 2 - B', '2025-06-16', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-18 15:17:50', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, '2025-06-08 21:10:14', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-08 14:10:14', 2, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(62, 5, 3, 'Fundamental Accounting 2 - B', '2025-06-16', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-18 15:17:50', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, '2025-06-09 05:15:57', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-08 22:15:57', 2, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(66, 2, 1, 'rapat', '2025-06-11', '15:00:00', '16:00:00', 'rapat ukm', 'kikan', 38642, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-11 16:13:17', 'Ruangan selesai dipakai tanpa checkout dari mahasiswa', 0, '2025-06-11 15:10:47', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', '2025-06-11 14:38:33', 'admin@stie-mce.ac.id', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-11 07:35:07', NULL, 'manual', 0, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(67, 5, 3, 'MACRO ECONOMICS - B', '2025-06-23', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-23 14:41:47', 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa', 0, '2025-06-23 14:26:34', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(68, 5, 3, 'MACRO ECONOMICS - B', '2025-06-30', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-07-02 10:18:01', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(69, 5, 3, 'MACRO ECONOMICS - B', '2025-07-07', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-07-08 12:52:20', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(70, 5, 3, 'MACRO ECONOMICS - B', '2025-07-14', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(71, 5, 3, 'MACRO ECONOMICS - B', '2025-07-21', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(72, 5, 3, 'MACRO ECONOMICS - B', '2025-07-28', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(73, 5, 3, 'MACRO ECONOMICS - B', '2025-08-04', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(74, 5, 3, 'MACRO ECONOMICS - B', '2025-08-11', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(75, 5, 3, 'MACRO ECONOMICS - B', '2025-08-18', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(76, 5, 3, 'MACRO ECONOMICS - B', '2025-08-25', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(77, 5, 3, 'MACRO ECONOMICS - B', '2025-09-01', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(78, 5, 3, 'MACRO ECONOMICS - B', '2025-09-08', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(79, 5, 3, 'MACRO ECONOMICS - B', '2025-09-15', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(80, 5, 3, 'MACRO ECONOMICS - B', '2025-09-22', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(81, 5, 3, 'MACRO ECONOMICS - B', '2025-09-29', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(82, 5, 3, 'MACRO ECONOMICS - B', '2025-10-06', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(83, 5, 3, 'MACRO ECONOMICS - B', '2025-10-13', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(84, 5, 3, 'MACRO ECONOMICS - B', '2025-10-20', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(85, 5, 3, 'MACRO ECONOMICS - B', '2025-10-27', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(86, 5, 3, 'MACRO ECONOMICS - B', '2025-11-03', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(87, 5, 3, 'MACRO ECONOMICS - B', '2025-11-10', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(88, 5, 3, 'MACRO ECONOMICS - B', '2025-11-17', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(89, 5, 3, 'MACRO ECONOMICS - B', '2025-11-24', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(90, 5, 3, 'MACRO ECONOMICS - B', '2025-12-01', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(91, 5, 3, 'MACRO ECONOMICS - B', '2025-12-08', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(92, 5, 3, 'MACRO ECONOMICS - B', '2025-12-15', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Didik', 'Pak Didik', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-06-21 21:49:05', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-21 14:49:05', 3, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(93, 5, 2, 'Business Statistic I - B1', '2025-06-26', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Samsul', 'Pak Samsul', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-30 07:57:04', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-06-24 07:16:49', 4, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(94, 5, 2, 'Business Statistic I - B1', '2025-07-03', '12:00:00', '14:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Samsul', 'Pak Samsul', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-07-04 09:04:03', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-06-24 07:16:49', 4, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(119, 5, 4, 'Computer Practicum - B', '2025-07-01', '13:00:00', '15:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Agus', 'Pak Agus', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-07-09 12:51:58', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 02:34:52', 6, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(120, 5, 4, 'Computer Practicum - B', '2025-07-08', '13:00:00', '15:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Pak Agus', 'Pak Agus', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-07-09 12:51:58', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 02:34:52', 6, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(133, 5, 3, 'Fundamental Accounting 2 - B', '2025-07-07', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-07-09 12:51:58', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(134, 5, 3, 'Fundamental Accounting 2 - B', '2025-07-14', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(135, 5, 3, 'Fundamental Accounting 2 - B', '2025-07-21', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(136, 5, 3, 'Fundamental Accounting 2 - B', '2025-07-28', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(137, 5, 3, 'Fundamental Accounting 2 - B', '2025-08-04', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(138, 5, 3, 'Fundamental Accounting 2 - B', '2025-08-11', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(139, 5, 3, 'Fundamental Accounting 2 - B', '2025-08-18', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(140, 5, 3, 'Fundamental Accounting 2 - B', '2025-08-25', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(141, 5, 3, 'Fundamental Accounting 2 - B', '2025-09-01', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(142, 5, 3, 'Fundamental Accounting 2 - B', '2025-09-08', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(143, 5, 3, 'Fundamental Accounting 2 - B', '2025-09-15', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(144, 5, 3, 'Fundamental Accounting 2 - B', '2025-09-22', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL),
(145, 5, 3, 'Fundamental Accounting 2 - B', '2025-09-29', '09:00:00', '11:30:00', 'Jadwal Perkuliahan Genap 2024/2025 - Dosen: Bu Dyah', 'Bu Dyah', 0, 'approve', NULL, 'pending', NULL, NULL, 0, NULL, NULL, NULL, '2025-07-09 12:02:37', 'SYSTEM_AUTO', NULL, NULL, NULL, NULL, 0, NULL, '2025-07-09 05:02:37', 7, 'recurring', 1, 0, NULL, 0.00, 0.00, 0.00, 'pending', NULL, NULL, NULL, 'local', NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Triggers `tbl_booking`
--
DELIMITER $$
CREATE TRIGGER `tr_booking_status_change` AFTER UPDATE ON `tbl_booking` FOR EACH ROW BEGIN
    -- Log when booking is cancelled or completed
    IF (OLD.status != NEW.status) AND NEW.status IN ('cancelled', 'done') THEN
        INSERT INTO tbl_room_availability_log (
            id_ruang, 
            date, 
            start_time, 
            end_time, 
            reason, 
            original_booking_id
        ) VALUES (
            NEW.id_ruang,
            NEW.tanggal,
            NEW.jam_mulai,
            NEW.jam_selesai,
            CASE 
                WHEN NEW.status = 'cancelled' THEN 'cancellation'
                WHEN NEW.status = 'done' THEN 'completion'
                ELSE 'completion'
            END,
            NEW.id_booking
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_booking_addons`
--

CREATE TABLE `tbl_booking_addons` (
  `id_addon` int(11) NOT NULL,
  `id_booking` int(11) NOT NULL,
  `addon_key` varchar(50) NOT NULL,
  `addon_name` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `unit_type` varchar(20) DEFAULT 'unit',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_booking_notifications`
--

CREATE TABLE `tbl_booking_notifications` (
  `id_notification` int(11) NOT NULL,
  `id_booking` int(11) NOT NULL,
  `recipient_email` varchar(100) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_cs_actions`
--

CREATE TABLE `tbl_cs_actions` (
  `id` int(11) NOT NULL,
  `cs_user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_dosen_sessions`
--

CREATE TABLE `tbl_dosen_sessions` (
  `session_id` varchar(128) NOT NULL,
  `karyawan_id` int(11) NOT NULL,
  `nik` varchar(20) NOT NULL,
  `nama_lengkap` varchar(200) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_expenses`
--

CREATE TABLE `tbl_expenses` (
  `id_expense` int(11) NOT NULL,
  `category` varchar(50) NOT NULL COMMENT 'maintenance, utilities, marketing, dll',
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expense_date` date NOT NULL,
  `vendor` varchar(100) DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_gedung`
--

CREATE TABLE `tbl_gedung` (
  `id_gedung` int(11) NOT NULL,
  `nama_gedung` varchar(100) NOT NULL,
  `deskripsi` varchar(200) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_gedung`
--

INSERT INTO `tbl_gedung` (`id_gedung`, `nama_gedung`, `deskripsi`, `created_at`, `updated_at`) VALUES
(1, 'Gedung K', '', '2025-06-08 22:05:41', '2025-06-08 23:20:03'),
(2, 'Gedung L', '', '2025-06-08 22:05:41', '2025-06-08 22:05:41'),
(3, 'Gedung M', '', '2025-06-08 22:05:41', '2025-06-08 22:05:41'),
(6, 'Kompleks H', '', '2025-06-08 22:05:41', '2025-06-08 22:05:41');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_harilibur`
--

CREATE TABLE `tbl_harilibur` (
  `tanggal` date NOT NULL,
  `keterangan` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_harilibur`
--

INSERT INTO `tbl_harilibur` (`tanggal`, `keterangan`) VALUES
('2025-06-06', 'Hari Raya Idul Adha'),
('2025-06-09', 'Cuti Bersama Idul Adha');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_recurring_schedules`
--

CREATE TABLE `tbl_recurring_schedules` (
  `id_schedule` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `nama_matakuliah` varchar(200) NOT NULL,
  `kelas` varchar(50) NOT NULL,
  `dosen_pengampu` varchar(200) NOT NULL,
  `hari` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `semester` varchar(20) NOT NULL,
  `tahun_akademik` varchar(20) NOT NULL,
  `start_date` date NOT NULL COMMENT 'Tanggal mulai perkuliahan',
  `end_date` date NOT NULL COMMENT 'Tanggal selesai perkuliahan',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_recurring_schedules`
--

INSERT INTO `tbl_recurring_schedules` (`id_schedule`, `id_ruang`, `nama_matakuliah`, `kelas`, `dosen_pengampu`, `hari`, `jam_mulai`, `jam_selesai`, `semester`, `tahun_akademik`, `start_date`, `end_date`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(3, 3, 'MACRO ECONOMICS', 'B', 'Pak Didik', 'monday', '12:00:00', '14:30:00', 'Genap', '2024/2025', '2025-06-21', '2025-12-21', 'active', 2, '2025-06-21 14:49:05', '2025-06-21 14:49:05'),
(7, 3, 'Fundamental Accounting 2', 'B', 'Bu Dyah', 'monday', '09:00:00', '11:30:00', 'Genap', '2024/2025', '2025-07-01', '2025-09-30', 'active', 2, '2025-07-09 05:02:37', '2025-07-09 05:02:37');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_room_availability_log`
--

CREATE TABLE `tbl_room_availability_log` (
  `id_log` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `became_available_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` enum('cancellation','completion','rejection') NOT NULL,
  `original_booking_id` int(11) DEFAULT NULL,
  `notified_users` text DEFAULT NULL COMMENT 'JSON array of notified user IDs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_room_availability_log`
--

INSERT INTO `tbl_room_availability_log` (`id_log`, `id_ruang`, `date`, `start_time`, `end_time`, `became_available_at`, `reason`, `original_booking_id`, `notified_users`) VALUES
(1, 1, '2025-06-02', '15:30:00', '16:30:00', '2025-06-02 10:32:30', 'completion', 12, NULL),
(2, 3, '2025-06-03', '12:00:00', '13:00:00', '2025-06-03 05:32:54', 'completion', 13, NULL),
(3, 2, '2025-06-05', '09:00:00', '10:00:00', '2025-06-05 04:01:24', 'completion', 14, NULL),
(4, 1, '2025-06-11', '15:00:00', '16:00:00', '2025-06-11 09:13:17', 'completion', 66, NULL),
(5, 3, '2025-06-16', '09:00:00', '11:30:00', '2025-06-18 08:17:50', 'completion', 62, NULL),
(6, 3, '2025-06-16', '09:00:00', '11:30:00', '2025-06-18 08:17:50', 'completion', 36, NULL),
(7, 3, '2025-06-23', '12:00:00', '14:30:00', '2025-06-23 07:41:47', 'completion', 67, NULL),
(8, 2, '2025-06-26', '12:00:00', '14:30:00', '2025-06-30 00:57:04', 'completion', 93, NULL),
(9, 3, '2025-06-30', '12:00:00', '14:30:00', '2025-07-02 03:18:01', 'completion', 68, NULL),
(10, 2, '2025-07-03', '12:00:00', '14:30:00', '2025-07-04 02:04:04', 'completion', 94, NULL),
(11, 3, '2025-07-07', '12:00:00', '14:30:00', '2025-07-08 05:52:20', 'completion', 69, NULL),
(12, 4, '2025-07-08', '13:00:00', '15:30:00', '2025-07-09 05:51:58', 'completion', 120, NULL),
(13, 3, '2025-07-07', '09:00:00', '11:30:00', '2025-07-09 05:51:58', 'completion', 133, NULL),
(14, 4, '2025-07-01', '13:00:00', '15:30:00', '2025-07-09 05:51:58', 'completion', 119, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_room_locks`
--

CREATE TABLE `tbl_room_locks` (
  `id` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `locked_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'active',
  `unlocked_by` int(11) DEFAULT NULL,
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlock_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_room_locks`
--

INSERT INTO `tbl_room_locks` (`id`, `id_ruang`, `start_date`, `end_date`, `reason`, `locked_by`, `created_at`, `status`, `unlocked_by`, `unlocked_at`, `unlock_reason`) VALUES
(1, 2, '2025-05-23', '2025-05-24', 'UTS', 2, '2025-05-23 09:12:18', 'unlocked', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_room_pricing`
--

CREATE TABLE `tbl_room_pricing` (
  `id_pricing` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `price_per_hour` decimal(10,2) NOT NULL,
  `weekend_multiplier` decimal(3,2) DEFAULT 1.00 COMMENT 'Pengali harga weekend',
  `holiday_multiplier` decimal(3,2) DEFAULT 1.50 COMMENT 'Pengali harga hari libur',
  `effective_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_ruang`
--

CREATE TABLE `tbl_ruang` (
  `id_ruang` int(11) NOT NULL,
  `id_gedung` int(11) NOT NULL,
  `nama_ruang` varchar(100) NOT NULL,
  `kapasitas` int(11) NOT NULL,
  `lokasi` varchar(50) NOT NULL,
  `fasilitas` text DEFAULT NULL COMMENT 'Daftar fasilitas ruangan',
  `allowed_roles` set('admin','mahasiswa','dosen','karyawan') DEFAULT 'admin,mahasiswa,dosen,karyawan' COMMENT 'Role yang boleh booking ruangan ini',
  `description` text DEFAULT NULL COMMENT 'Deskripsi ruangan'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_ruang`
--

INSERT INTO `tbl_ruang` (`id_ruang`, `id_gedung`, `nama_ruang`, `kapasitas`, `lokasi`, `fasilitas`, `allowed_roles`, `description`) VALUES
(1, 1, 'K-1', 40, 'Lantai 1', '[\"AC\",\"Proyektor\",\"WiFi\"]', 'admin,mahasiswa,dosen,karyawan', ''),
(2, 3, 'M1-8', 40, 'Lantai 1', NULL, 'admin,mahasiswa,dosen,karyawan', NULL),
(3, 1, 'K-4', 40, 'Lantai 2', '[\"AC\",\"Whiteboard\",\"Meja\",\"LCD TV\"]', 'mahasiswa,dosen,karyawan', ''),
(4, 3, 'M-1', 40, 'Lantai 1', '[\"AC\",\"WiFi\",\"LCD TV\"]', 'admin,mahasiswa,dosen,karyawan', ''),
(5, 1, 'K-3', 40, 'Lantai 2', '[\"AC\",\"Meja\"]', 'admin,mahasiswa,dosen,karyawan', 'Untuk pembelajaran, dll');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_schedule_exceptions`
--

CREATE TABLE `tbl_schedule_exceptions` (
  `id_exception` int(11) NOT NULL,
  `id_schedule` int(11) NOT NULL,
  `exception_date` date NOT NULL,
  `exception_type` enum('holiday','cancelled','moved','special') NOT NULL,
  `new_date` date DEFAULT NULL COMMENT 'Jika jadwal dipindah ke tanggal lain',
  `new_time_start` time DEFAULT NULL,
  `new_time_end` time DEFAULT NULL,
  `new_room_id` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_users`
--

CREATE TABLE `tbl_users` (
  `id_user` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `nik` varchar(50) DEFAULT NULL,
  `nama` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','dosen','cs','mahasiswa') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_users`
--

INSERT INTO `tbl_users` (`id_user`, `email`, `nik`, `nama`, `password`, `role`) VALUES
(1, '36288@mhs.stie-mce.ac.id', NULL, NULL, '20051029', 'mahasiswa'),
(2, 'admin@stie-mce.ac.id', NULL, NULL, '12345678', 'admin'),
(3, 'cs@stie-mce.ac.id', NULL, NULL, '12345678', 'cs'),
(5, 'system@stie-mce.ac.id', NULL, NULL, '12345678', 'admin'),
(6, 'dosen@stie-mce.ac.id', NULL, NULL, '12345678', 'dosen'),
(7, 'keuangan@stie-mce.ac.id', NULL, NULL, '12345678', ''),
(9, 'danesty@stie-mce.ac.id', '202710209', 'DWI DANESTY DECCASARI', '$2y$10$5tES6qB.g6/VwPmzc.yzAeb4HgjWjlcxN3R68zQwXEvERslfx.VOG', 'dosen');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_user_preferences`
--

CREATE TABLE `tbl_user_preferences` (
  `id_preference` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `preferred_rooms` text DEFAULT NULL COMMENT 'JSON array of preferred room IDs',
  `notification_preferences` text DEFAULT NULL COMMENT 'JSON object with notification settings',
  `waitlist_slots` text DEFAULT NULL COMMENT 'JSON array of desired time slots',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_booking_with_user_details`
-- (See below for the actual view)
--
CREATE TABLE `vw_booking_with_user_details` (
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_booking_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_booking_summary` (
`id_booking` int(11)
,`nama_acara` varchar(200)
,`tanggal` date
,`jam_mulai` time
,`jam_selesai` time
,`status` enum('pending','approve','rejected','cancelled','active','done')
,`checkout_status` enum('pending','manual_checkout','auto_completed','force_checkout')
,`email` varchar(255)
,`user_role` enum('admin','dosen','cs','mahasiswa')
,`nama_ruang` varchar(100)
,`nama_gedung` varchar(100)
,`slot_available` int(1)
,`checkout_description` varchar(30)
);

-- --------------------------------------------------------

--
-- Structure for view `vw_booking_with_user_details`
--
DROP TABLE IF EXISTS `vw_booking_with_user_details`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_booking_with_user_details`  AS SELECT `b`.`id_booking` AS `id_booking`, `b`.`id_user` AS `id_user`, `b`.`id_ruang` AS `id_ruang`, `b`.`nama_acara` AS `nama_acara`, `b`.`tanggal` AS `tanggal`, `b`.`jam_mulai` AS `jam_mulai`, `b`.`jam_selesai` AS `jam_selesai`, `b`.`keterangan` AS `keterangan`, `b`.`nama` AS `nama`, `b`.`no_penanggungjawab` AS `no_penanggungjawab`, `b`.`status` AS `status`, `b`.`checked_out_by` AS `checked_out_by`, `b`.`checkout_status` AS `checkout_status`, `b`.`checkout_time` AS `checkout_time`, `b`.`completion_note` AS `completion_note`, `b`.`is_external` AS `is_external`, `b`.`user_can_activate` AS `user_can_activate`, `b`.`activated_by_user` AS `activated_by_user`, `b`.`activated_at` AS `activated_at`, `b`.`activated_by` AS `activated_by`, `b`.`activation_note` AS `activation_note`, `b`.`approved_at` AS `approved_at`, `b`.`approved_by` AS `approved_by`, `b`.`approval_reason` AS `approval_reason`, `b`.`cancelled_by` AS `cancelled_by`, `b`.`cancelled_at` AS `cancelled_at`, `b`.`cancellation_reason` AS `cancellation_reason`, `b`.`auto_approved` AS `auto_approved`, `b`.`auto_approval_reason` AS `auto_approval_reason`, `b`.`created_at` AS `created_at`, `b`.`id_schedule` AS `id_schedule`, `b`.`booking_type` AS `booking_type`, `b`.`auto_generated` AS `auto_generated`, `b`.`created_by_cs` AS `created_by_cs`, `b`.`cs_user_id` AS `cs_user_id`, `b`.`addon_total_cost` AS `addon_total_cost`, `b`.`base_price` AS `base_price`, `b`.`addon_total` AS `addon_total`, `b`.`total_amount` AS `total_amount`, `b`.`payment_status` AS `payment_status`, `b`.`payment_method` AS `payment_method`, `b`.`payment_date` AS `payment_date`, `b`.`karyawan_id` AS `karyawan_id`, `b`.`user_type` AS `user_type`, `b`.`nik_dosen` AS `nik_dosen`, `b`.`nama_dosen` AS `nama_dosen`, `b`.`email_dosen` AS `email_dosen`, `u`.`email` AS `local_email`, `u`.`role` AS `local_role`, CASE WHEN `b`.`user_type` = 'dosen_iris' AND `b`.`nama_dosen` is not null THEN `b`.`nama_dosen` ELSE coalesce(`u`.`email`,'Unknown User') END AS `display_name`, CASE WHEN `b`.`user_type` = 'dosen_iris' AND `b`.`email_dosen` is not null THEN `b`.`email_dosen` ELSE `u`.`email` END AS `display_email`, CASE WHEN `b`.`user_type` = 'dosen_iris' THEN 'Dosen' ELSE coalesce(`u`.`role`,'user') END AS `display_role`, `r`.`nama_ruang` AS `nama_ruang`, `r`.`kapasitas` AS `kapasitas`, `r`.`lokasi` AS `lokasi`, `r`.`fasilitas` AS `fasilitas`, `g`.`nama_gedung` AS `nama_gedung` FROM (((`tbl_booking` `b` left join `tbl_users` `u` on(`b`.`id_user` = `u`.`id_user`)) left join `tbl_ruang` `r` on(`b`.`id_ruang` = `r`.`id_ruang`)) left join `tbl_gedung` `g` on(`r`.`id_gedung` = `g`.`id_gedung`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_booking_summary`
--
DROP TABLE IF EXISTS `v_booking_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_booking_summary`  AS SELECT `b`.`id_booking` AS `id_booking`, `b`.`nama_acara` AS `nama_acara`, `b`.`tanggal` AS `tanggal`, `b`.`jam_mulai` AS `jam_mulai`, `b`.`jam_selesai` AS `jam_selesai`, `b`.`status` AS `status`, `b`.`checkout_status` AS `checkout_status`, `u`.`email` AS `email`, `u`.`role` AS `user_role`, `r`.`nama_ruang` AS `nama_ruang`, `g`.`nama_gedung` AS `nama_gedung`, CASE WHEN `b`.`status` = 'done' THEN 1 WHEN `b`.`status` = 'cancelled' THEN 1 WHEN `b`.`status` = 'rejected' THEN 1 ELSE 0 END AS `slot_available`, CASE WHEN `b`.`checkout_status` = 'manual_checkout' THEN 'Manual Checkout oleh Mahasiswa' WHEN `b`.`checkout_status` = 'auto_completed' THEN 'Auto-Completed oleh Sistem' WHEN `b`.`checkout_status` = 'force_checkout' THEN 'Force Checkout oleh Admin' ELSE 'Belum Checkout' END AS `checkout_description` FROM (((`tbl_booking` `b` join `tbl_users` `u` on(`b`.`id_user` = `u`.`id_user`)) join `tbl_ruang` `r` on(`b`.`id_ruang` = `r`.`id_ruang`)) left join `tbl_gedung` `g` on(`r`.`id_gedung` = `g`.`id_gedung`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_addon_options`
--
ALTER TABLE `tbl_addon_options`
  ADD PRIMARY KEY (`id_addon`),
  ADD KEY `idx_addon_status` (`status`),
  ADD KEY `idx_addon_category` (`category`),
  ADD KEY `fk_addon_created_by` (`created_by`);

--
-- Indexes for table `tbl_booking`
--
ALTER TABLE `tbl_booking`
  ADD PRIMARY KEY (`id_booking`),
  ADD KEY `id_user` (`id_user`),
  ADD KEY `id_ruang` (`id_ruang`),
  ADD KEY `id_recurring_schedule` (`id_schedule`),
  ADD KEY `idx_booking_status_date` (`status`,`tanggal`),
  ADD KEY `idx_booking_room_date` (`id_ruang`,`tanggal`),
  ADD KEY `idx_booking_checkout_status` (`checkout_status`),
  ADD KEY `idx_booking_user_status` (`id_user`,`status`),
  ADD KEY `idx_booking_cs` (`created_by_cs`,`cs_user_id`),
  ADD KEY `idx_booking_karyawan_id` (`karyawan_id`),
  ADD KEY `idx_booking_nik_dosen` (`nik_dosen`),
  ADD KEY `idx_booking_user_type` (`user_type`);

--
-- Indexes for table `tbl_booking_addons`
--
ALTER TABLE `tbl_booking_addons`
  ADD PRIMARY KEY (`id_addon`),
  ADD KEY `idx_booking_addons_booking` (`id_booking`);

--
-- Indexes for table `tbl_booking_notifications`
--
ALTER TABLE `tbl_booking_notifications`
  ADD PRIMARY KEY (`id_notification`),
  ADD KEY `idx_booking_notifications_booking` (`id_booking`),
  ADD KEY `idx_booking_notifications_email` (`recipient_email`),
  ADD KEY `idx_booking_notifications_type` (`notification_type`);

--
-- Indexes for table `tbl_cs_actions`
--
ALTER TABLE `tbl_cs_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cs_user_id` (`cs_user_id`);

--
-- Indexes for table `tbl_dosen_sessions`
--
ALTER TABLE `tbl_dosen_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_session_karyawan` (`karyawan_id`),
  ADD KEY `idx_session_nik` (`nik`);

--
-- Indexes for table `tbl_expenses`
--
ALTER TABLE `tbl_expenses`
  ADD PRIMARY KEY (`id_expense`),
  ADD KEY `idx_expense_category` (`category`),
  ADD KEY `idx_expense_date` (`expense_date`),
  ADD KEY `idx_expense_status` (`status`),
  ADD KEY `fk_expense_created_by` (`created_by`),
  ADD KEY `fk_expense_approved_by` (`approved_by`);

--
-- Indexes for table `tbl_gedung`
--
ALTER TABLE `tbl_gedung`
  ADD PRIMARY KEY (`id_gedung`);

--
-- Indexes for table `tbl_harilibur`
--
ALTER TABLE `tbl_harilibur`
  ADD PRIMARY KEY (`tanggal`);

--
-- Indexes for table `tbl_recurring_schedules`
--
ALTER TABLE `tbl_recurring_schedules`
  ADD PRIMARY KEY (`id_schedule`),
  ADD KEY `id_ruang` (`id_ruang`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `tbl_room_availability_log`
--
ALTER TABLE `tbl_room_availability_log`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_room_availability_room_date` (`id_ruang`,`date`),
  ADD KEY `idx_room_availability_time` (`date`,`start_time`,`end_time`);

--
-- Indexes for table `tbl_room_locks`
--
ALTER TABLE `tbl_room_locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_ruang` (`id_ruang`),
  ADD KEY `locked_by` (`locked_by`);

--
-- Indexes for table `tbl_room_pricing`
--
ALTER TABLE `tbl_room_pricing`
  ADD PRIMARY KEY (`id_pricing`),
  ADD KEY `idx_pricing_room` (`id_ruang`),
  ADD KEY `idx_pricing_effective` (`effective_date`),
  ADD KEY `idx_pricing_status` (`status`),
  ADD KEY `fk_pricing_created_by` (`created_by`);

--
-- Indexes for table `tbl_ruang`
--
ALTER TABLE `tbl_ruang`
  ADD PRIMARY KEY (`id_ruang`),
  ADD KEY `id_gedung` (`id_gedung`);

--
-- Indexes for table `tbl_schedule_exceptions`
--
ALTER TABLE `tbl_schedule_exceptions`
  ADD PRIMARY KEY (`id_exception`),
  ADD KEY `id_schedule` (`id_schedule`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `tbl_users`
--
ALTER TABLE `tbl_users`
  ADD PRIMARY KEY (`id_user`),
  ADD KEY `idx_nik` (`nik`);

--
-- Indexes for table `tbl_user_preferences`
--
ALTER TABLE `tbl_user_preferences`
  ADD PRIMARY KEY (`id_preference`),
  ADD UNIQUE KEY `idx_user_preferences_user` (`id_user`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_addon_options`
--
ALTER TABLE `tbl_addon_options`
  MODIFY `id_addon` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_booking`
--
ALTER TABLE `tbl_booking`
  MODIFY `id_booking` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=148;

--
-- AUTO_INCREMENT for table `tbl_booking_addons`
--
ALTER TABLE `tbl_booking_addons`
  MODIFY `id_addon` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_booking_notifications`
--
ALTER TABLE `tbl_booking_notifications`
  MODIFY `id_notification` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_cs_actions`
--
ALTER TABLE `tbl_cs_actions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_expenses`
--
ALTER TABLE `tbl_expenses`
  MODIFY `id_expense` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_gedung`
--
ALTER TABLE `tbl_gedung`
  MODIFY `id_gedung` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tbl_recurring_schedules`
--
ALTER TABLE `tbl_recurring_schedules`
  MODIFY `id_schedule` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tbl_room_availability_log`
--
ALTER TABLE `tbl_room_availability_log`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `tbl_room_locks`
--
ALTER TABLE `tbl_room_locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_room_pricing`
--
ALTER TABLE `tbl_room_pricing`
  MODIFY `id_pricing` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_ruang`
--
ALTER TABLE `tbl_ruang`
  MODIFY `id_ruang` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_schedule_exceptions`
--
ALTER TABLE `tbl_schedule_exceptions`
  MODIFY `id_exception` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_users`
--
ALTER TABLE `tbl_users`
  MODIFY `id_user` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `tbl_user_preferences`
--
ALTER TABLE `tbl_user_preferences`
  MODIFY `id_preference` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_addon_options`
--
ALTER TABLE `tbl_addon_options`
  ADD CONSTRAINT `fk_addon_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_booking`
--
ALTER TABLE `tbl_booking`
  ADD CONSTRAINT `tbl_booking_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `tbl_users` (`id_user`),
  ADD CONSTRAINT `tbl_booking_ibfk_2` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`);

--
-- Constraints for table `tbl_booking_addons`
--
ALTER TABLE `tbl_booking_addons`
  ADD CONSTRAINT `tbl_booking_addons_ibfk_1` FOREIGN KEY (`id_booking`) REFERENCES `tbl_booking` (`id_booking`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_booking_notifications`
--
ALTER TABLE `tbl_booking_notifications`
  ADD CONSTRAINT `fk_booking_notifications_booking` FOREIGN KEY (`id_booking`) REFERENCES `tbl_booking` (`id_booking`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_cs_actions`
--
ALTER TABLE `tbl_cs_actions`
  ADD CONSTRAINT `tbl_cs_actions_ibfk_1` FOREIGN KEY (`cs_user_id`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_expenses`
--
ALTER TABLE `tbl_expenses`
  ADD CONSTRAINT `fk_expense_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `tbl_users` (`id_user`),
  ADD CONSTRAINT `fk_expense_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_recurring_schedules`
--
ALTER TABLE `tbl_recurring_schedules`
  ADD CONSTRAINT `tbl_recurring_schedules_ibfk_1` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`),
  ADD CONSTRAINT `tbl_recurring_schedules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_room_availability_log`
--
ALTER TABLE `tbl_room_availability_log`
  ADD CONSTRAINT `fk_room_availability_room` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_room_locks`
--
ALTER TABLE `tbl_room_locks`
  ADD CONSTRAINT `tbl_room_locks_ibfk_1` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`),
  ADD CONSTRAINT `tbl_room_locks_ibfk_2` FOREIGN KEY (`locked_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_room_pricing`
--
ALTER TABLE `tbl_room_pricing`
  ADD CONSTRAINT `fk_pricing_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`),
  ADD CONSTRAINT `fk_pricing_room` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`);

--
-- Constraints for table `tbl_ruang`
--
ALTER TABLE `tbl_ruang`
  ADD CONSTRAINT `tbl_ruang_ibfk_1` FOREIGN KEY (`id_gedung`) REFERENCES `tbl_gedung` (`id_gedung`);

--
-- Constraints for table `tbl_schedule_exceptions`
--
ALTER TABLE `tbl_schedule_exceptions`
  ADD CONSTRAINT `tbl_schedule_exceptions_ibfk_1` FOREIGN KEY (`id_schedule`) REFERENCES `tbl_recurring_schedules` (`id_schedule`),
  ADD CONSTRAINT `tbl_schedule_exceptions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_user_preferences`
--
ALTER TABLE `tbl_user_preferences`
  ADD CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`id_user`) REFERENCES `tbl_users` (`id_user`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
