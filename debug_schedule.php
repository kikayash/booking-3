<?php
// DEBUG SCRIPT: Tambahkan script ini untuk debug jadwal kuliah
// Buat file baru: debug_schedule.php

require_once 'config.php';
require_once 'functions.php';

echo "<h2>Debug Jadwal Kuliah</h2>";

// 1. CEK RECURRING SCHEDULES
echo "<h3>1. Data Recurring Schedules:</h3>";
$stmt = $conn->prepare("SELECT * FROM tbl_recurring_schedules WHERE status = 'active'");
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($schedules)) {
    echo "<p style='color: red;'>❌ TIDAK ADA RECURRING SCHEDULES! Buat jadwal kuliah dulu.</p>";
} else {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Mata Kuliah</th><th>Kelas</th><th>Ruang</th><th>Hari</th><th>Jam</th><th>Start Date</th><th>End Date</th></tr>";
    foreach ($schedules as $schedule) {
        echo "<tr>";
        echo "<td>{$schedule['id_schedule']}</td>";
        echo "<td>{$schedule['nama_matakuliah']}</td>";
        echo "<td>{$schedule['kelas']}</td>";
        echo "<td>{$schedule['id_ruang']}</td>";
        echo "<td>{$schedule['hari']}</td>";
        echo "<td>{$schedule['jam_mulai']} - {$schedule['jam_selesai']}</td>";
        echo "<td>{$schedule['start_date']}</td>";
        echo "<td>{$schedule['end_date']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. CEK GENERATED BOOKINGS
echo "<h3>2. Booking dari Jadwal Kuliah (booking_type = 'recurring'):</h3>";
$stmt = $conn->prepare("
    SELECT b.*, rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu 
    FROM tbl_booking b 
    LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
    WHERE b.booking_type = 'recurring' 
    ORDER BY b.tanggal DESC 
    LIMIT 10
");
$stmt->execute();
$recurringBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($recurringBookings)) {
    echo "<p style='color: red;'>❌ TIDAK ADA BOOKING DARI JADWAL KULIAH!</p>";
    echo "<p><strong>Solusi:</strong> Generate booking dari jadwal kuliah.</p>";
} else {
    echo "<table border='1'>";
    echo "<tr><th>ID Booking</th><th>Tanggal</th><th>Jam</th><th>Mata Kuliah</th><th>Kelas</th><th>Status</th><th>ID Schedule</th></tr>";
    foreach ($recurringBookings as $booking) {
        echo "<tr>";
        echo "<td>{$booking['id_booking']}</td>";
        echo "<td>{$booking['tanggal']}</td>";
        echo "<td>{$booking['jam_mulai']} - {$booking['jam_selesai']}</td>";
        echo "<td>{$booking['nama_matakuliah']}</td>";
        echo "<td>{$booking['kelas']}</td>";
        echo "<td>{$booking['status']}</td>";
        echo "<td>{$booking['id_schedule']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3. CEK QUERY CALENDAR
echo "<h3>3. Test Query Calendar untuk Bulan Ini:</h3>";
$currentMonth = date('Y-m-01');
$endMonth = date('Y-m-t');
$roomId = 3; // Ganti dengan ID ruang yang ada jadwal kuliah

$stmt = $conn->prepare("
    SELECT b.*, 
           CASE 
               WHEN b.booking_type = 'recurring' THEN rs.nama_matakuliah
               ELSE b.nama_acara
           END as display_name,
           b.booking_type,
           rs.nama_matakuliah,
           rs.kelas
    FROM tbl_booking b 
    LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
    WHERE b.id_ruang = ? 
    AND b.tanggal BETWEEN ? AND ?
    AND b.status NOT IN ('cancelled', 'rejected')
    ORDER BY b.tanggal, b.jam_mulai
");
$stmt->execute([$roomId, $currentMonth, $endMonth]);
$calendarBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p><strong>Query untuk Room ID $roomId, bulan ini:</strong></p>";
if (empty($calendarBookings)) {
    echo "<p style='color: red;'>❌ TIDAK ADA BOOKING DITEMUKAN untuk ruang $roomId!</p>";
} else {
    echo "<table border='1'>";
    echo "<tr><th>Tanggal</th><th>Jam</th><th>Display Name</th><th>Type</th><th>Status</th></tr>";
    foreach ($calendarBookings as $booking) {
        $style = $booking['booking_type'] === 'recurring' ? 'background: lightblue;' : '';
        echo "<tr style='$style'>";
        echo "<td>{$booking['tanggal']}</td>";
        echo "<td>{$booking['jam_mulai']} - {$booking['jam_selesai']}</td>";
        echo "<td>{$booking['display_name']}</td>";
        echo "<td>{$booking['booking_type']}</td>";
        echo "<td>{$booking['status']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 4. GENERATE BOOKINGS JIKA BELUM ADA
if (empty($recurringBookings) && !empty($schedules)) {
    echo "<h3>4. Auto-Generate Bookings:</h3>";
    echo "<p>Mencoba generate booking untuk jadwal kuliah...</p>";
    
    foreach ($schedules as $schedule) {
        echo "<p>Generating untuk: {$schedule['nama_matakuliah']} - {$schedule['kelas']}</p>";
        
        try {
            $result = generateBookingsForSchedule($conn, $schedule['id_schedule'], $schedule);
            echo "<p style='color: green;'>✅ Generated $result bookings</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<p><strong>Refresh halaman ini untuk melihat hasil.</strong></p>";
}

// 5. CHECK SYSTEM USER
echo "<h3>5. System User:</h3>";
$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE email = 'system@stie-mce.ac.id'");
$stmt->execute();
$systemUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$systemUser) {
    echo "<p style='color: red;'>❌ System user tidak ada! Creating...</p>";
    $systemUserId = getSystemUserId($conn);
    echo "<p style='color: green;'>✅ System user created with ID: $systemUserId</p>";
} else {
    echo "<p style='color: green;'>✅ System user exists: ID {$systemUser['id_user']}</p>";
}

echo "<hr>";
echo "<h3>KESIMPULAN:</h3>";
echo "<ol>";
echo "<li><strong>Pastikan ada recurring schedules</strong> - " . (empty($schedules) ? "❌ TIDAK ADA" : "✅ ADA") . "</li>";
echo "<li><strong>Pastikan booking ter-generate</strong> - " . (empty($recurringBookings) ? "❌ TIDAK ADA" : "✅ ADA") . "</li>";
echo "<li><strong>Pastikan query calendar benar</strong> - " . (empty($calendarBookings) ? "❌ TIDAK ADA" : "✅ ADA") . "</li>";
echo "</ol>";

if (empty($recurringBookings)) {
    echo "<p><strong style='color: red;'>MASALAH UTAMA: Tidak ada booking yang ter-generate dari jadwal kuliah!</strong></p>";
    echo "<p><strong>Solusi:</strong></p>";
    echo "<ul>";
    echo "<li>1. Buat recurring schedule dulu di admin panel</li>";
    echo "<li>2. Atau jalankan script generate manual</li>";
    echo "<li>3. Pastikan tanggal start_date dan end_date mencakup periode sekarang</li>";
    echo "</ul>";
}
?>