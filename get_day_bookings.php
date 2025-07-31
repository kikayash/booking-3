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
    // Get parameters
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $roomId = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode([
            'success' => false,
            'message' => 'Format tanggal tidak valid'
        ]);
        exit;
    }
    
    // Build query
    $sql = "SELECT b.*, u.email, r.nama_ruang, r.kapasitas, g.nama_gedung,
                   b.checkout_status, b.checkout_time, b.checked_out_by, b.completion_note
            FROM tbl_booking b 
            JOIN tbl_users u ON b.id_user = u.id_user 
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            WHERE b.tanggal = ?";
    
    $params = [$date];
    
    // Add room filter if specified
    if ($roomId > 0) {
        $sql .= " AND b.id_ruang = ?";
        $params[] = $roomId;
    }
    
    $sql .= " ORDER BY b.jam_mulai ASC, b.nama_acara ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current user info
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'];
    
    // Process each booking
    $processedBookings = [];
    foreach ($bookings as $booking) {
        // Get detailed status information
        $statusInfo = getDetailedBookingStatus($booking);
        
        // Calculate duration
        $startTime = new DateTime($booking['jam_mulai']);
        $endTime = new DateTime($booking['jam_selesai']);
        $duration = $startTime->diff($endTime);
        $durationText = '';
        if ($duration->h > 0) {
            $durationText .= $duration->h . 'j ';
        }
        if ($duration->i > 0) {
            $durationText .= $duration->i . 'm';
        }
        $durationText = trim($durationText) ?: '0m';
        
        // Check user permissions
        $isOwner = ($booking['id_user'] == $currentUserId);
        $isAdmin = ($userRole === 'admin');
        
        // Get checkout information
        $checkoutInfo = null;
        if (!empty($booking['checkout_time'])) {
            $checkoutType = getCheckoutTypeText($booking['checkout_status'], $booking['checked_out_by']);
            $checkoutInfo = [
                'checkout_time' => $booking['checkout_time'],
                'formatted_time' => date('H:i', strtotime($booking['checkout_time'])),
                'checkout_type' => $checkoutType,
                'note' => $booking['completion_note']
            ];
        }
        
        // Process booking data
        $processedBooking = [
            'id_booking' => $booking['id_booking'],
            'nama_acara' => $booking['nama_acara'],
            'jam_mulai' => $booking['jam_mulai'],
            'jam_selesai' => $booking['jam_selesai'],
            'duration' => $durationText,
            'nama_penanggungjawab' => $booking['nama_penanggungjawab'],
            'no_penanggungjawab' => $booking['no_penanggungjawab'],
            'keterangan' => $booking['keterangan'],
            'status' => $booking['status'],
            'status_badge' => getBookingStatusBadge($booking['status']),
            'status_info' => $statusInfo,
            'email' => $booking['email'],
            'nama_ruang' => $booking['nama_ruang'],
            'nama_gedung' => $booking['nama_gedung'] ?? 'Tidak diketahui',
            'kapasitas' => $booking['kapasitas'],
            'created_at' => date('d/m H:i', strtotime($booking['created_at'])),
            'checkout_info' => $checkoutInfo,
            'user_permissions' => [
                'is_owner' => $isOwner,
                'is_admin' => $isAdmin
            ]
        ];
        
        // Add slot availability info for completed/cancelled bookings
        if (in_array($booking['status'], ['done', 'cancelled', 'rejected'])) {
            $processedBooking['slot_available'] = true;
        }
        
        $processedBookings[] = $processedBooking;
    }
    
    // Get room information if room_id is specified
    $roomInfo = null;
    if ($roomId > 0) {
        $stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung WHERE r.id_ruang = ?");
        $stmt->execute([$roomId]);
        $roomInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Check if it's a holiday
    $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
    $stmt->execute([$date]);
    $holiday = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get daily statistics
    $totalBookings = count($processedBookings);
    $statusCounts = [
        'pending' => 0,
        'approve' => 0,
        'active' => 0,
        'done' => 0,
        'cancelled' => 0,
        'rejected' => 0
    ];
    
    $checkoutStats = [
        'manual_checkout' => 0,
        'auto_completed' => 0,
        'force_checkout' => 0
    ];
    
    foreach ($processedBookings as $booking) {
        $status = $booking['status'];
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
        
        if ($status === 'done' && isset($booking['checkout_info'])) {
            $checkoutStatus = $booking['checkout_info']['checkout_type']['text'] ?? '';
            switch ($checkoutStatus) {
                case 'Manual Checkout':
                    $checkoutStats['manual_checkout']++;
                    break;
                case 'Auto-Completed':
                    $checkoutStats['auto_completed']++;
                    break;
                case 'Force Checkout':
                    $checkoutStats['force_checkout']++;
                    break;
            }
        }
    }
    
    // Calculate available slots if room is specified
    $availableSlots = [];
    if ($roomId > 0 && $roomInfo) {
        // Get time slots that are not booked
        $businessHours = ['07:00', '08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'];
        
        foreach ($businessHours as $hour) {
            $slotStart = $hour . ':00';
            $slotEnd = date('H:i', strtotime($slotStart . ' +1 hour'));
            
            $isBooked = false;
            foreach ($processedBookings as $booking) {
                if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'rejected') {
                    if (($slotStart >= $booking['jam_mulai'] && $slotStart < $booking['jam_selesai']) ||
                        ($slotEnd > $booking['jam_mulai'] && $slotEnd <= $booking['jam_selesai'])) {
                        $isBooked = true;
                        break;
                    }
                }
            }
            
            if (!$isBooked) {
                $availableSlots[] = [
                    'start_time' => $slotStart,
                    'end_time' => $slotEnd,
                    'duration' => '1 jam'
                ];
            }
        }
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'date' => $date,
        'formatted_date' => formatDate($date, 'l, d F Y'),
        'bookings' => $processedBookings,
        'room_info' => $roomInfo,
        'holiday' => $holiday,
        'statistics' => [
            'total_bookings' => $totalBookings,
            'status_counts' => $statusCounts,
            'checkout_stats' => $checkoutStats
        ],
        'available_slots' => $availableSlots,
        'total_available_slots' => count($availableSlots)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Get day bookings error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>