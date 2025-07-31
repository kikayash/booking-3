<?php
// File: test_auto_checkout.php
// Testing auto-checkout functionality

require_once 'config.php';
require_once 'functions.php';
require_once 'auto_checkout.php';

echo "=== TESTING AUTO-CHECKOUT ===\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";

// Cek booking active yang expired
$sql = "SELECT b.*, r.nama_ruang FROM tbl_booking b 
        JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
        WHERE b.status = 'active'";
$stmt = $conn->prepare($sql);
$stmt->execute();
$activeBookings = $stmt->fetchAll();

echo "\n=== ACTIVE BOOKINGS ===\n";
foreach ($activeBookings as $booking) {
    $endDateTime = $booking['tanggal'] . ' ' . $booking['jam_selesai'];
    $isExpired = (strtotime($endDateTime) < time()) ? 'YES' : 'NO';
    
    echo "ID: {$booking['id_booking']} | {$booking['nama_acara']} | ";
    echo "End: {$booking['tanggal']} {$booking['jam_selesai']} | ";
    echo "Expired: {$isExpired}\n";
}

// Jalankan auto-checkout
echo "\n=== RUNNING AUTO-CHECKOUT ===\n";
$count = autoCheckoutExpiredBookings($conn);
echo "Auto-checked out: {$count} booking(s)\n";

echo "\n=== DONE ===\n";
?>