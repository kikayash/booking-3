<?php
// cs/addon-functions.php - Helper functions untuk Add-on booking

/**
 * Get booking add-ons by booking ID
 */
function getBookingAddons($conn, $bookingId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tbl_booking_addons WHERE id_booking = ? ORDER BY addon_name");
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting booking addons: " . $e->getMessage());
        return [];
    }
}

/**
 * Calculate total addon cost for a booking
 */
function calculateAddonTotal($conn, $bookingId) {
    try {
        $stmt = $conn->prepare("SELECT SUM(total_price) as total FROM tbl_booking_addons WHERE id_booking = ?");
        $stmt->execute([$bookingId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    } catch (Exception $e) {
        error_log("Error calculating addon total: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get booking details with add-ons
 */
function getBookingWithAddons($conn, $bookingId) {
    try {
        // Get booking details
        $stmt = $conn->prepare("
            SELECT b.*, u.email, u.role, r.nama_ruang, g.nama_gedung,
                   DATE_FORMAT(b.tanggal, '%d/%m/%Y') as formatted_date,
                   CONCAT(TIME_FORMAT(b.jam_mulai, '%H:%i'), ' - ', TIME_FORMAT(b.jam_selesai, '%H:%i')) as duration
            FROM tbl_booking b
            JOIN tbl_users u ON b.id_user = u.id_user
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            WHERE b.id_booking = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            return null;
        }
        
        // Get add-ons
        $booking['addons'] = getBookingAddons($conn, $bookingId);
        $booking['addon_total'] = calculateAddonTotal($conn, $bookingId);
        
        return $booking;
    } catch (Exception $e) {
        error_log("Error getting booking with addons: " . $e->getMessage());
        return null;
    }
}

/**
 * Format currency for display
 */
function formatCurrency($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/**
 * Check if booking has add-ons
 */
function hasAddons($conn, $bookingId) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_booking_addons WHERE id_booking = ?");
        $stmt->execute([$bookingId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking if booking has addons: " . $e->getMessage());
        return false;
    }
}
?>