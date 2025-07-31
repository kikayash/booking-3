<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'Anda harus login terlebih dahulu'
    ]);
    exit;
}

try {
    // Get booking ID from GET parameter
    $bookingId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if (!$bookingId) {
        echo json_encode([
            'success' => false,
            'message' => 'ID booking tidak valid'
        ]);
        exit;
    }
    
    // Get booking details with enhanced information
    $booking = getBookingById($conn, $bookingId);
    
    if (!$booking) {
        echo json_encode([
            'success' => false,
            'message' => 'Booking tidak ditemukan'
        ]);
        exit;
    }
    
    // Get current user info
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'];
    $isOwner = ($booking['id_user'] == $currentUserId);
    $isAdmin = ($userRole === 'admin');
    
    // Get detailed status information
    $statusInfo = getDetailedBookingStatus($booking);
    
    // Calculate duration
    $startTime = new DateTime($booking['jam_mulai']);
    $endTime = new DateTime($booking['jam_selesai']);
    $duration = $startTime->diff($endTime);
    $durationText = '';
    if ($duration->h > 0) {
        $durationText .= $duration->h . ' jam ';
    }
    if ($duration->i > 0) {
        $durationText .= $duration->i . ' menit';
    }
    $durationText = trim($durationText) ?: '0 menit';
    
    // Check current time and booking status for actions
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    $bookingDate = $booking['tanggal'];
    $bookingStartTime = $booking['jam_mulai'];
    $bookingEndTime = $booking['jam_selesai'];
    
    // Determine available actions
    $availableActions = [];
    
    // Check if user can activate booking
    $canActivate = false;
    if ($booking['status'] === 'approve' && $isOwner) {
        if ($bookingDate === $currentDate) {
            $timeDiff = strtotime($bookingStartTime) - strtotime($currentTime);
            // Can activate 30 minutes before start time
            if ($timeDiff <= 1800 && $timeDiff >= -300) { // 30 min before to 5 min after
                $canActivate = true;
                $availableActions[] = 'activate';
            }
        }
    }
    
    // Check if user can cancel booking
    $canCancel = false;
    if (in_array($booking['status'], ['pending', 'approve']) && ($isOwner || $isAdmin)) {
        $bookingDateTime = new DateTime($bookingDate . ' ' . $bookingStartTime);
        if ($currentDateTime < $bookingDateTime || $isAdmin) {
            $canCancel = true;
            $availableActions[] = 'cancel';
        }
    }
    
    // Check if user can checkout booking
    $canCheckout = false;
    if ($booking['status'] === 'active' && ($isOwner || $isAdmin)) {
        $canCheckout = true;
        $availableActions[] = 'checkout';
    }
    
    // Get checkout information if available
    $checkoutInfo = null;
    if (!empty($booking['checkout_time'])) {
        $checkoutType = getCheckoutTypeText($booking['checkout_status'], $booking['checked_out_by']);
        $checkoutInfo = [
            'checkout_time' => $booking['checkout_time'],
            'formatted_checkout_time' => date('d/m/Y H:i:s', strtotime($booking['checkout_time'])),
            'checked_out_by' => $booking['checked_out_by'],
            'checkout_status' => $booking['checkout_status'],
            'completion_note' => $booking['completion_note'],
            'checkout_type' => $checkoutType
        ];
    }
    
    // Get room facilities if available
    $facilities = [];
    if (!empty($booking['fasilitas'])) {
        $facilitiesData = json_decode($booking['fasilitas'], true);
        if (is_array($facilitiesData)) {
            $facilities = $facilitiesData;
        } else {
            $facilities = explode(',', $booking['fasilitas']);
        }
    }
    
    // Prepare comprehensive booking information
    $response = [
        'success' => true,
        'booking' => [
            'id_booking' => $booking['id_booking'],
            'id_user' => $booking['id_user'],
            'nama_acara' => $booking['nama_acara'],
            'tanggal' => $booking['tanggal'],
            'formatted_date' => formatDate($booking['tanggal'], 'l, d F Y'),
            'jam_mulai' => $booking['jam_mulai'],
            'jam_selesai' => $booking['jam_selesai'],
            'duration' => $durationText,
            'keterangan' => $booking['keterangan'],
            'nama_penanggungjawab' => $booking['nama_penanggungjawab'],
            'no_penanggungjawab' => $booking['no_penanggungjawab'],
            'status' => $booking['status'],
            'email' => $booking['email'],
            'nama_ruang' => $booking['nama_ruang'],
            'nama_gedung' => $booking['nama_gedung'] ?? 'Tidak diketahui',
            'kapasitas' => $booking['kapasitas'] ?? 'Tidak diketahui',
            'lokasi' => $booking['lokasi'] ?? 'Tidak diketahui',
            'facilities' => $facilities,
            'created_at' => $booking['created_at'],
            'formatted_created_at' => date('d/m/Y H:i', strtotime($booking['created_at']))
        ],
        'status_info' => $statusInfo,
        'checkout_info' => $checkoutInfo,
        'user_permissions' => [
            'is_owner' => $isOwner,
            'is_admin' => $isAdmin,
            'can_activate' => $canActivate,
            'can_cancel' => $canCancel,
            'can_checkout' => $canCheckout
        ],
        'available_actions' => $availableActions,
        'status_badge' => getBookingStatusBadge($booking['status']),
        'time_info' => [
            'current_date' => $currentDate,
            'current_time' => $currentTime,
            'booking_date' => $bookingDate,
            'booking_start_time' => $bookingStartTime,
            'booking_end_time' => $bookingEndTime,
            'is_past' => ($currentDateTime > new DateTime($bookingDate . ' ' . $bookingEndTime)),
            'is_current' => ($bookingDate === $currentDate && $currentTime >= $bookingStartTime && $currentTime <= $bookingEndTime),
            'is_future' => ($currentDateTime < new DateTime($bookingDate . ' ' . $bookingStartTime))
        ]
    ];
    
    // Add slot availability information for completed/cancelled bookings
    if (in_array($booking['status'], ['done', 'cancelled', 'rejected'])) {
        $response['slot_available'] = true;
        $response['availability_message'] = getSlotAvailabilityMessage($booking);
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Get booking detail error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>