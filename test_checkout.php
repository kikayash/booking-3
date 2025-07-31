<?php
// File: test_checkout.php
// Testing checkout functionality

session_start();
require_once 'config.php';
require_once 'functions.php';

// Test specific booking ID (ganti dengan ID yang sedang active)
$testBookingId = 7; // Sesuaikan dengan ID booking yang active

echo "=== TESTING CHECKOUT ===\n";
echo "Testing Booking ID: {$testBookingId}\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";

// Get booking details
$booking = getBookingById($conn, $testBookingId);

if ($booking) {
    echo "\n=== BOOKING DETAILS ===\n";
    echo "ID: {$booking['id_booking']}\n";
    echo "Nama Acara: {$booking['nama_acara']}\n";
    echo "Status: {$booking['status']}\n";
    echo "Tanggal: {$booking['tanggal']}\n";
    echo "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    echo "User ID: {$booking['id_user']}\n";
    
    // Check if can checkout
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    
    echo "\n=== CHECKOUT VALIDATION ===\n";
    echo "Current Date: {$currentDate}\n";
    echo "Booking Date: {$booking['tanggal']}\n";
    echo "Current Time: {$currentTime}\n";
    echo "Booking End: {$booking['jam_selesai']}\n";
    
    if ($booking['status'] === 'active') {
        echo "✅ Status is ACTIVE - can checkout\n";
    } else {
        echo "❌ Status is {$booking['status']} - cannot checkout\n";
    }
    
    // Test checkout query
    echo "\n=== TESTING CHECKOUT QUERY ===\n";
    $stmt = $conn->prepare("UPDATE tbl_booking 
                           SET status = 'done', 
                               checkout_status = 'done', 
                               checkout_time = NOW(),
                               completion_note = 'Test checkout'
                           WHERE id_booking = ? AND status = 'active'");
    
    echo "Query prepared successfully\n";
    
} else {
    echo "❌ Booking not found!\n";
}

echo "\n=== DONE ===\n";
?>