<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

define('BOOKING_SYSTEM_LOADED', true);

if (isLoggedIn()) {
    // Inisialisasi variabel dengan pengecekan
    $lastCheck = isset($_SESSION['last_auto_check']) ? $_SESSION['last_auto_check'] : 0;
    $now = time();
    
    // Pastikan $now terdefinisi dan cek interval
    if (!empty($now) && ($now - $lastCheck) >= 600) {
        try {
            $autoResult = forceAutoCheckoutExpiredBookings($conn);
            $_SESSION['last_auto_check'] = $now;
            
            if ($autoResult['completed_count'] > 0) {
                $_SESSION['show_auto_update'] = [
                    'count' => $autoResult['completed_count'],
                    'time' => date('H:i:s')
                ];
                error_log("AUTO-UPDATE: Completed {$autoResult['completed_count']} expired bookings");
            }
        } catch (Exception $e) {
            error_log("Auto-update error: " . $e->getMessage());
        }
    }
}

// Auto-generate recurring schedules jika ada
if (function_exists('autoGenerateUpcomingSchedules')) {
    $lastGenerate = $_SESSION['last_auto_generate'] ?? 0;
    $now = isset($now) ? $now : time(); // Cek apakah $now sudah ada, jika tidak buat baru
    
    if (($now - $lastGenerate) >= 3600) { // 1 jam sekali
        try {
            $generated = autoGenerateUpcomingSchedules($conn);
            $_SESSION['last_auto_generate'] = $now;
            
            if ($generated > 0) {
                error_log("AUTO-GENERATE: Generated $generated recurring schedules");
            }
        } catch (Exception $e) {
            error_log("Auto-generate error: " . $e->getMessage());
        }
    }
}


// Get the current view (day, week, month)
$view = isset($_GET['view']) ? $_GET['view'] : 'day';

// Get available rooms
$stmt = $conn->prepare("SELECT * FROM tbl_ruang ORDER BY nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all buildings
$stmt = $conn->prepare("SELECT * FROM tbl_gedung ORDER BY nama_gedung");
$stmt->execute();
$buildings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default selected room (first one if available)
$selectedRoomId = isset($_GET['room_id']) ? $_GET['room_id'] : (count($rooms) > 0 ? $rooms[0]['id_ruang'] : 0);

// Selected building (if any)
$selectedBuildingId = isset($_GET['building_id']) ? $_GET['building_id'] : 0;

// Filter rooms by building if a building is selected
if ($selectedBuildingId) {
    $filteredRooms = array_filter($rooms, function($room) use ($selectedBuildingId) {
        return $room['id_gedung'] == $selectedBuildingId;
    });
} else {
    $filteredRooms = $rooms;
}

// Default date (today if not specified)
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// For week view, calculate the start and end of the week
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate)));
$weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($selectedDate)));

// For month view, calculate the start and end of the month
$monthStart = date('Y-m-01', strtotime($selectedDate));
$monthEnd = date('Y-m-t', strtotime($selectedDate));

// Get bookings based on the selected view
// Get bookings based on the selected view - ENHANCED VERSION
$bookings = [];
if ($selectedRoomId) {
    // Base query yang diperbaiki
    $baseQuery = "
        SELECT DISTINCT
            b.id_booking,
            b.id_user,
            b.id_ruang,
            b.tanggal,
            b.jam_mulai,
            b.jam_selesai,
            b.nama_acara,
            b.keterangan,
            b.no_penanggungjawab,
            b.status,
            b.booking_type,
            b.id_schedule,
            b.created_at,
            u.email,
            u.role,
            r.nama_ruang,
            r.kapasitas,
            g.nama_gedung,
            r.lokasi,
            rs.id_schedule as recurring_schedule_id,
            rs.nama_matakuliah,
            rs.kelas,
            rs.dosen_pengampu,
            rs.semester,
            rs.tahun_akademik,
            rs.hari as recurring_day,
            CASE 
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NOT NULL 
                THEN rs.nama_matakuliah
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NULL 
                THEN 'Perkuliahan'
                ELSE b.nama_acara
            END as display_name,
            CASE 
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NOT NULL 
                THEN CONCAT(rs.nama_matakuliah, ' (', COALESCE(rs.kelas, 'Kelas'), ')')
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NULL 
                THEN 'Jadwal Perkuliahan'
                ELSE b.nama_acara
            END as full_name
        FROM tbl_booking b 
        INNER JOIN tbl_users u ON b.id_user = u.id_user 
        INNER JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_recurring_schedules rs ON (b.id_schedule = rs.id_schedule AND b.booking_type = 'recurring')
        WHERE b.id_ruang = ? 
        AND b.status NOT IN ('cancelled', 'rejected')
    ";
    
    switch ($view) {
        case 'month':
            $stmt = $conn->prepare($baseQuery . " AND b.tanggal BETWEEN ? AND ? ORDER BY b.tanggal ASC, b.jam_mulai ASC");
            $stmt->execute([$selectedRoomId, $monthStart, $monthEnd]);
            break;
            
        case 'week':
            $stmt = $conn->prepare($baseQuery . " AND b.tanggal BETWEEN ? AND ? ORDER BY b.tanggal ASC, b.jam_mulai ASC");
            $stmt->execute([$selectedRoomId, $weekStart, $weekEnd]);
            break;
            
        default: // day view
            $stmt = $conn->prepare($baseQuery . " AND b.tanggal = ? ORDER BY b.jam_mulai ASC");
            $stmt->execute([$selectedRoomId, $selectedDate]);
            break;
    }
    
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // TAMBAHAN: Post-process untuk memastikan tidak ada duplikat
    $uniqueBookings = [];
    $seenKeys = [];
    
    foreach ($bookings as $booking) {
        // Buat unique key berdasarkan tanggal, jam, ruangan
        $uniqueKey = $booking['tanggal'] . '_' . $booking['jam_mulai'] . '_' . $booking['jam_selesai'] . '_' . $booking['id_ruang'];
        
        // Jika sudah ada booking dengan key yang sama, skip
        if (!in_array($uniqueKey, $seenKeys)) {
            $uniqueBookings[] = $booking;
            $seenKeys[] = $uniqueKey;
        }
    }
    
    $bookings = $uniqueBookings;
}

// TAMBAHAN: Auto-cleanup saat sistem dimuat
if (isLoggedIn()) {
    // Auto-cleanup holiday schedules dan duplikat (jalankan sekali per session)
    if (!isset($_SESSION['cleanup_done']) || (time() - $_SESSION['cleanup_done']) > 3600) { // 1 jam sekali
        
        try {
            // 1. Cleanup jadwal pada hari libur
            $holidayCleanup = autoCleanupHolidaySchedules($conn);
            
            // 2. Remove duplikat jadwal
            $duplicateCleanup = removeDuplicateRecurringBookings($conn);
            
            // Update session
            $_SESSION['cleanup_done'] = time();
            
            // Log hasil cleanup
            if ($holidayCleanup > 0 || $duplicateCleanup > 0) {
                error_log("AUTO-CLEANUP COMPLETED: Holiday cleanup: {$holidayCleanup}, Duplicate cleanup: {$duplicateCleanup}");
                
                // Set notifikasi untuk admin (optional)
                if (isAdmin()) {
                    $_SESSION['cleanup_notification'] = [
                        'holiday_cleaned' => $holidayCleanup,
                        'duplicates_cleaned' => $duplicateCleanup,
                        'time' => date('H:i:s')
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("Auto-cleanup error: " . $e->getMessage());
        }
    }
}


// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk auto-checkout booking yang expired
function autoCheckoutExpiredBookings($conn) {
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    $currentDateTime = date('Y-m-d H:i:s');
    
    // Cari booking dengan status 'active' yang sudah melewati waktu selesai
    $sql = "SELECT b.id_booking, b.nama_acara, b.tanggal, b.jam_mulai, b.jam_selesai, 
                b.no_penanggungjawab, b.id_user,
                   r.nama_ruang, g.nama_gedung, u.email
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            JOIN tbl_users u ON b.id_user = u.id_user
            WHERE b.status = 'active' 
            AND (
                (b.tanggal < ?) OR 
                (b.tanggal = ? AND b.jam_selesai < ?)
            )";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$currentDate, $currentDate, $currentTime]);
    $expiredBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $autoCheckedOutCount = 0;
    
    foreach ($expiredBookings as $booking) {
        // Update status menjadi 'done' dengan auto-checkout
        $updateSql = "UPDATE tbl_booking 
                      SET status = 'done',
                          checkout_status = 'auto_completed',
                          checkout_time = ?,
                          completion_note = 'Ruangan selesai dipakai tanpa checkout dari mahasiswa',
                          checked_out_by = 'SYSTEM_AUTO'
                      WHERE id_booking = ?";
        
        $updateStmt = $conn->prepare($updateSql);
        $result = $updateStmt->execute([$currentDateTime, $booking['id_booking']]);
        
        if ($result) {
            $autoCheckedOutCount++;
            
            // Log untuk tracking
            error_log("AUTO-CHECKOUT: Booking ID {$booking['id_booking']} ({$booking['nama_acara']}) - Status: ACTIVE → DONE (Auto-Completed)");
            error_log("REASON: Ruangan selesai dipakai tanpa checkout dari mahasiswa");
            
            // Kirim notifikasi ke mahasiswa dan admin
            sendAutoCheckoutNotification($booking);
            sendAdminAutoCheckoutNotification($booking);
        }
    }
    
    if ($autoCheckedOutCount > 0) {
        error_log("AUTO-CHECKOUT SUMMARY: {$autoCheckedOutCount} booking(s) automatically checked out");
    }
    
    return $autoCheckedOutCount;
}

// Fungsi untuk mengirim notifikasi auto-checkout ke mahasiswa
function sendAutoCheckoutNotification($booking) {
    $subject = "Auto-Checkout: " . $booking['nama_acara'];
    $message = "Halo {$booking['nama_penanggungjawab']},\n\n";
    $message .= "Booking ruangan Anda telah di-checkout secara otomatis karena melewati waktu selesai.\n\n";
    $message .= "Detail Booking:\n";
    $message .= "Nama Acara: {$booking['nama_acara']}\n";
    $message .= "Ruangan: {$booking['nama_ruang']}\n";
    $message .= "Tanggal: " . date('d/m/Y', strtotime($booking['tanggal'])) . "\n";
    $message .= "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    $message .= "Status: SELESAI (Auto-Checkout)\n\n";
    $message .= "CATATAN: Untuk masa depan, mohon lakukan checkout manual setelah selesai menggunakan ruangan.\n\n";
    $message .= "Terima kasih.";
    
    // Log notifikasi (implementasi email sesuai kebutuhan)
    error_log("AUTO-CHECKOUT NOTIFICATION: Sent to {$booking['email']} for booking #{$booking['id_booking']}");
    
    return true;
}

// Fungsi untuk mengirim notifikasi ke admin
function sendAdminAutoCheckoutNotification($booking) {
    $subject = "Admin Alert: Auto-Checkout - " . $booking['nama_acara'];
    $message = "SISTEM AUTO-CHECKOUT\n\n";
    $message .= "Booking berikut telah di-checkout secara otomatis karena mahasiswa lupa checkout:\n\n";
    $message .= "Detail Booking:\n";
    $message .= "ID Booking: {$booking['id_booking']}\n";
    $message .= "Nama Acara: {$booking['nama_acara']}\n";
    $message .= "Ruangan: {$booking['nama_ruang']}\n";
    $message .= "Tanggal: " . date('d/m/Y', strtotime($booking['tanggal'])) . "\n";
    $message .= "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    $message .= "PIC: {$booking['nama_penanggungjawab']} ({$booking['no_penanggungjawab']})\n";
    $message .= "Email: {$booking['email']}\n\n";
    $message .= "Status: MAHASISWA LUPA CHECKOUT\n";
    $message .= "Auto-checkout time: " . date('d/m/Y H:i:s') . "\n\n";
    $message .= "Silakan tindak lanjuti jika diperlukan.";
    
    // Log notifikasi admin
    error_log("ADMIN AUTO-CHECKOUT ALERT: Booking ID {$booking['id_booking']} - Student forgot to checkout");
    
    return true;
}

// Fungsi untuk mendapatkan statistik checkout
function getCheckoutStatistics($conn, $date = null) {
    $date = $date ?: date('Y-m-d');
    
    try {
        // Manual checkout count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as manual_count 
            FROM tbl_booking 
            WHERE DATE(checkout_time) = ? 
            AND checkout_status = 'manual_checkout'
        ");
        $stmt->execute([$date]);
        $manualCount = $stmt->fetchColumn();
        
        // Auto checkout count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as auto_count 
            FROM tbl_booking 
            WHERE DATE(checkout_time) = ? 
            AND checkout_status = 'auto_completed'
        ");
        $stmt->execute([$date]);
        $autoCount = $stmt->fetchColumn();
        
        return [
            'date' => $date,
            'manual_checkout' => $manualCount,
            'auto_checkout' => $autoCount,
            'total_checkout' => $manualCount + $autoCount,
            'forgot_checkout_rate' => $manualCount + $autoCount > 0 ? 
                round(($autoCount / ($manualCount + $autoCount)) * 100, 2) : 0
        ];
        
    } catch (Exception $e) {
        error_log("Error getting checkout statistics: " . $e->getMessage());
        return null;
    }
}

// Jalankan auto-checkout jika file dipanggil langsung
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $count = autoCheckoutExpiredBookings($conn);
        
        if ($count > 0) {
            echo "AUTO-CHECKOUT: {$count} booking(s) processed successfully\n";
            
            // Show statistics
            $stats = getCheckoutStatistics($conn);
            if ($stats) {
                echo "TODAY'S STATS:\n";
                echo "- Manual Checkout: {$stats['manual_checkout']}\n";
                echo "- Auto Checkout (Forgot): {$stats['auto_checkout']}\n";
                echo "- Forgot Rate: {$stats['forgot_checkout_rate']}%\n";
            }
        } 
        
    } catch (Exception $e) {
        error_log("AUTO-CHECKOUT ERROR: " . $e->getMessage());
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

// Check if the selected date is a holiday
$stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
$stmt->execute([$selectedDate]);
$holiday = $stmt->fetch(PDO::FETCH_ASSOC);

// Get selected room details
$selectedRoom = null;
if ($selectedRoomId) {
    $stmt = $conn->prepare("SELECT * FROM tbl_ruang WHERE id_ruang = ?");
    $stmt->execute([$selectedRoomId]);
    $selectedRoom = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get current month for mini calendar
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Definisikan rentang waktu booking (hari ini sampai 1 bulan ke depan)
$todayDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+1 year'));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Ruangan - STIE MCE</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
    /* modal booking */
    .modal-xl {
        max-width: 1000px;
    }

    .bg-gradient-primary {
        background: linear-gradient(135deg, #007bff, #0056b3);
    }

    .avatar-dosen {
        width: 60px;
        height: 60px;
        background: #e3f2fd;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 3px solid #2196f3;
    }

    .info-card {
        display: flex;
        align-items: center;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 10px;
        border-left: 4px solid #007bff;
        height: 100%;
    }

    .info-icon {
        margin-right: 0.75rem;
    }

    .info-icon i {
        font-size: 1.5rem;
    }

    .info-label {
        font-size: 0.8rem;
        color: #6c757d;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 700;
        color: #212529;
    }

    .form-section {
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #e9ecef;
    }

    .form-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }

    .section-title {
        color: #495057;
        font-weight: 700;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e3f2fd;
    }

    .form-control-lg, .form-select-lg {
        padding: 0.75rem 1rem;
        font-size: 1rem;
        border-radius: 8px;
        border: 2px solid #e9ecef;
        transition: all 0.3s ease;
    }

    .form-control-lg:focus, .form-select-lg:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.15);
    }

    .activity-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem;
    }

    .activity-card {
        display: block;
        padding: 1.5rem 1rem;
        background: #fff;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        color: #495057;
    }

    .activity-card:hover {
        border-color: #007bff;
        background: #f8f9ff;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.15);
    }

    .btn-check:checked + .activity-card {
        border-color: #007bff;
        background: linear-gradient(135deg, #e3f2fd, #f8f9ff);
        color: #007bff;
    }

    .activity-title {
        font-weight: 700;
        font-size: 0.9rem;
        margin-bottom: 0.25rem;
    }

    .activity-desc {
        font-size: 0.75rem;
        color: #6c757d;
    }

    .btn-check:checked + .activity-card .activity-desc {
        color: #0056b3;
    }

    #durationDisplay {
        font-weight: 600;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .modal-xl {
            max-width: 95%;
            margin: 1rem auto;
        }
        
        .activity-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .info-card {
            flex-direction: column;
            text-align: center;
        }
        
        .info-icon {
            margin-right: 0;
            margin-bottom: 0.5rem;
        }
    }

    /* Animation for form transitions */
    .form-section {
        animation: fadeInUp 0.3s ease;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
        .calendar-month-event {
    transition: all 0.2s ease;
    border: 1px solid rgba(255,255,255,0.3);
    }

    .calendar-month-event:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    /* Status-specific colors */
    .bg-warning.text-dark {
        background-color: #ffc107 !important;
        color: #000 !important;
    }

    .bg-success {
        background-color: #28a745 !important;
    }

    .bg-danger {
        background-color: #dc3545 !important;
        animation: pulse-red 2s infinite;
    }

    .bg-info {
        background-color: #17a2b8 !important;
    }

    .bg-secondary {
        background-color: #6c757d !important;
    }

    /* Animasi untuk booking yang sedang berlangsung */
    @keyframes pulse-red {
        0% { 
            background-color: #dc3545; 
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
        }
        50% { 
            background-color: #c82333; 
            box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
        }
        100% { 
            background-color: #dc3545; 
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
        }
    }
.academic-schedule {
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white;
    border-radius: 8px;
}

.academic-schedule .badge {
    background-color: rgba(255,255,255,0.2) !important;
}

.academic-schedule small {
    color: rgba(255,255,255,0.8) !important;
}

/* Academic event dalam month view */
.calendar-month-event.academic {
    background: linear-gradient(135deg, #17a2b8, #138496);
    border-left: 4px solid #ffc107;
}

    /* Improved calendar cell styling */
    .calendar-month-cell {
        position: relative;
        border: 1px solid #dee2e6;
        padding: 5px;
    }

    .calendar-month-day {
        font-weight: 600;
        margin-bottom: 5px;
        border-bottom: 1px solid #eee;
        padding-bottom: 2px;
    }

    .calendar-month-events {
        font-size: 0.8rem;
        overflow: hidden;
        max-height: 85px;
    }

    /* Weekend styling */
    .calendar-month-cell:nth-child(1),
    .calendar-month-cell:nth-child(7) {
        background-color: #f8f9fa;
    }

    /* Today highlighting */
    .table-primary .calendar-month-day {
        background-color: rgba(0, 123, 255, 0.1);
        border-radius: 3px;
        padding: 2px 4px;
    }
        /* Style untuk tanggal di luar rentang booking */
        .mini-calendar-day.out-of-range {
            opacity: 0.5;
            cursor: not-allowed;
            text-decoration: line-through;
            color: #6c757d;
        }

        /* Pastikan tidak mengganggu tampilan tanggal hari ini */
        .mini-calendar-day.out-of-range.today {
            opacity: 0.7;
            text-decoration: none;
            color: var(--primary-color);
            background-color: #e8f0fe;
        }

        /* Pastikan tanggal weekend di luar rentang tetap merah */
        .mini-calendar-day.out-of-range.weekend {
            color: var(--danger-color);
            opacity: 0.5;
        }
        .mini-calendar {
    font-size: 0.85rem;
    margin: 0;
}

.mini-calendar th, 
.mini-calendar td {
    padding: 8px 4px !important;
    text-align: center;
    vertical-align: middle;
    width: 14.28% !important;
    height: 35px;
    border: 1px solid #dee2e6;
}

.mini-calendar th {
    font-weight: 600;
    background-color: #f8f9fa;
    font-size: 0.8rem;
}
        
        /* Style tambahan untuk view kalender */
        .calendar-week-header {
            text-align: center;
            font-weight: 600;
            padding: 8px;
            background-color: #f8f9fa;
        }
        
        .calendar-month-cell {
            height: 100px;
            vertical-align: top;
        }
        
        .calendar-month-day {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .calendar-month-events {
            font-size: 0.8rem;
            overflow: hidden;
        }
        
        .calendar-month-event {
            margin-bottom: 2px;
            padding: 1px 4px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /*buat badges fasilitas*/
        .facility-item {
            display: inline-block;
            margin: 2px;
        }
        .facility-badge {
            background-color: #e3f2fd;
            color: #0277bd;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            border: 1px solid #81d4fa;
        }
        .role-restriction {
            font-size: 0.85rem;
        }
        .room-card {
            transition: all 0.3s ease;
        }
        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .academic-event-badge {
    background: linear-gradient(135deg, #ff8c00, #ff7700) !important;
    color: white !important;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    border: 1px solid #ffd700;
    box-shadow: 0 2px 4px rgba(255, 140, 0, 0.3);
}

/* Academic status indicator */
.academic-status {
    background: linear-gradient(135deg, #ff8c00, #ff7700) !important;
    color: white !important;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 15px;
    border: 2px solid #ffd700;
    box-shadow: 0 2px 6px rgba(255, 140, 0, 0.4);
}

/* Table row for academic schedules */
.table-academic {
    background: linear-gradient(135deg, rgba(255, 140, 0, 0.1), rgba(255, 119, 0, 0.1)) !important;
    border-left: 4px solid #ff8c00 !important;
}

/* Academic detail modal styling */
.modal-header.bg-academic {
    background: linear-gradient(135deg, #ff8c00, #ff7700) !important;
    color: white !important;
}

/* Override existing bg-info for academic events */
.bg-info.academic-event,
.calendar-month-event.bg-info.academic-event {
    background: linear-gradient(135deg, #ff8c00, #ff7700) !important;
    border-left: 4px solid #ffd700 !important;
    color: white !important;
}

/* Day view academic schedule styling */
.time-slot .academic-schedule .text-muted {
    color: rgba(255,255,255,0.9) !important;
}

.time-slot .academic-schedule strong {
    color: white !important;
}

/* Icon styling for academic events */
.academic-schedule .fas {
    color: white !important;
}

/* Ensure text contrast */
.academic-schedule .d-flex div {
    color: white !important;
}

.academic-schedule .text-end small {
    color: rgba(255,255,255,0.9) !important;
}
        
        .calendar-month-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body class="<?= isLoggedIn() ? 'logged-in' : '' ?>">
    <header>
        <?php include 'header.php'; ?>
    </header>

    <div class="container-fluid mt-3">
        <!-- Info Alert 
        <div class="alert alert-info alert-dismissible fade show" role="alert">
        <strong>Informasi Booking!</strong> Anda hanya dapat memesan ruangan dari tanggal <span id="tgl-awal-booking"><?= date('d F Y', strtotime($todayDate)) ?></span> hingga <span id="tgl-akhir-booking"><?= date('d F Y', strtotime($maxDate)) ?></span>.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>-->
        <div class="row">
            <!-- Mini Calendar -->
            <div class="col-md-3">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <button class="btn btn-sm btn-outline-light" onclick="prevMonth()">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <h5 class="mb-0"><?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?></h5>
                            <button class="btn btn-sm btn-outline-light" onclick="nextMonth()">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered table-sm mb-0 mini-calendar">
                            <thead>
                                <tr class="text-center">
                                    <th>Sen</th>
                                    <th>Sel</th>
                                    <th>Rab</th>
                                    <th>Kam</th>
                                    <th>Jum</th>
                                    <th style="color: var(--danger-color);">Sab</th>
                                    <th style="color: var(--danger-color);">Min</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
// Get the first day of the month
$firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
$numberDays = date('t', $firstDayOfMonth);
$firstDayOfWeek = date('w', $firstDayOfMonth); // 0=Sunday, 1=Monday, ..., 6=Saturday

// Convert to Monday=0 format (Indonesia standard)
$firstDayOfWeek = ($firstDayOfWeek == 0) ? 6 : $firstDayOfWeek - 1;

$day = 1;
$today = date('Y-m-d');

// Generate calendar
for ($i = 0; $i < 6; $i++) {
    echo "<tr>";
    
    for ($j = 0; $j < 7; $j++) {
        if (($i == 0 && $j < $firstDayOfWeek) || ($day > $numberDays)) {
            echo "<td class='text-center text-muted'>&nbsp;</td>";
        } else {
            $currentDate = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
            $isToday = $currentDate == $today ? 'today' : '';
            $isSelected = $currentDate == $selectedDate ? 'selected' : '';
            
            // FIXED: Correct weekend detection for mini calendar
            // j=0 is Monday, j=1 is Tuesday, ..., j=5 is Saturday, j=6 is Sunday
            $isWeekend = '';
            if ($j == 5) { // Saturday
                $isWeekend = 'weekend';
            } elseif ($j == 6) { // Sunday  
                $isWeekend = 'weekend';
            }
            
            // Check if date is outside booking range
            $isOutOfRange = '';
            if ($currentDate < $todayDate || $currentDate > $maxDate) {
                $isOutOfRange = 'out-of-range';
            }
            
            $dateHasBookings = false;
            if ($selectedRoomId) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_booking WHERE id_ruang = ? AND tanggal = ?");
                $stmt->execute([$selectedRoomId, $currentDate]);
                $dateHasBookings = $stmt->fetchColumn() > 0;
            }
            
            $isHoliday = false;
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_harilibur WHERE tanggal = ?");
            $stmt->execute([$currentDate]);
            $isHoliday = $stmt->fetchColumn() > 0;
            
            $cellClass = "text-center mini-calendar-day $isToday $isSelected $isWeekend $isOutOfRange";
            if ($dateHasBookings) {
                $cellClass .= ' has-bookings';
            }
            if ($isHoliday) {
                $cellClass .= ' holiday';
            }
            
            echo "<td class='$cellClass' data-date='$currentDate' onclick='selectDate(\"$currentDate\")'>{$day}</td>";
            $day++;
        }
    }
    
    echo "</tr>";
    if ($day > $numberDays) {
        break;
    }
}
?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        <div class="d-flex justify-content-between">
                            <div>
                                <span>Informasi:</span><br>
                                <span class="badge bg-warning me-2">Pending</span>
                                <span class="badge bg-success me-2">Diterima</span>
                                <span class="badge bg-danger">Digunakan</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Room Selection -->
                <div class="card shadow mt-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Pilih Lokasi & Ruangan</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="buildingSelector" class="form-label">Gedung</label>
                            <select class="form-select" id="buildingSelector" onchange="filterRooms(this.value)">
                                <option value="">-- Pilih Gedung --</option>
                                <?php foreach ($buildings as $building): ?>
                                    <option value="<?= $building['id_gedung'] ?>" 
                                            <?= ($selectedBuildingId == $building['id_gedung']) ? 'selected' : '' ?>>
                                        <?= $building['nama_gedung'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-0">
                            <label for="roomSelector" class="form-label">Ruangan</label>
                            <select class="form-select" id="roomSelector" onchange="selectRoom(this.value)">
                                <option value="">-- Pilih Ruangan --</option>
                                <?php foreach ($filteredRooms as $room): ?>
                                    <option value="<?= $room['id_ruang'] ?>" 
                                            <?= $room['id_ruang'] == $selectedRoomId ? 'selected' : '' ?>>
                                        <strong><?= $room['nama_ruang'] ?></strong> (Kapasitas: <?= $room['kapasitas'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Jika dipilih ruangan, tampilkan informasi ruangan -->
                <?php if ($selectedRoom): ?>
                <div class="card shadow mt-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Informasi Ruangan</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-light p-3 rounded-circle me-3">
                                <i class="fas fa-door-open fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h4 class="mb-0"><?= $selectedRoom['nama_ruang'] ?></h4>
                                <p class="text-muted mb-0">
                                    <?php
                                    // Get building name
                                    $stmt = $conn->prepare("SELECT nama_gedung FROM tbl_gedung WHERE id_gedung = ?");
                                    $stmt->execute([$selectedRoom['id_gedung']]);
                                    $buildingName = $stmt->fetchColumn() ?: 'Unknown Building';
                                    echo $buildingName;
                                    ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-sm-6">
                                <p><i class="fas fa-users me-2 text-secondary"></i> <strong>Kapasitas:</strong> <?= $selectedRoom['kapasitas'] ?> orang</p>
                            </div>
                            <div class="col-sm-6">
                                <p><i class="fas fa-map-marker-alt me-2 text-secondary"></i> <strong>Lokasi:</strong> <?= $selectedRoom['lokasi'] ?></p>
                            </div>
                            <div class="col-sm-6">
                                <p><i class="fas fa-cogs me-2 text-secondary"></i> <strong>Fasilitas:</strong> </p>
                                <span class="facility-badge facility-item"><?= $selectedRoom['fasilitas'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Main Calendar -->
            <div class="col-md-9">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <?php if ($view == 'day'): ?>
                                <button class="btn btn-sm btn-outline-light" onclick="prevDay()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h4 class="mb-0">
                                    <?= date('l, d F Y', strtotime($selectedDate)) ?>
                                    <?= $holiday ? '- <span class="badge bg-danger">' . $holiday['keterangan'] . '</span>' : '' ?>
                                </h4>
                                <button class="btn btn-sm btn-outline-light" onclick="nextDay()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php elseif ($view == 'week'): ?>
                                <button class="btn btn-sm btn-outline-light" onclick="prevWeek()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h4 class="mb-0">
                                    <?= date('d M', strtotime($weekStart)) ?> - <?= date('d M Y', strtotime($weekEnd)) ?>
                                </h4>
                                <button class="btn btn-sm btn-outline-light" onclick="nextWeek()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php else: // month view ?>
                                <button class="btn btn-sm btn-outline-light" onclick="prevMonth()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h4 class="mb-0">
                                    <?= date('F Y', strtotime($monthStart)) ?>
                                </h4>
                                <button class="btn btn-sm btn-outline-light" onclick="nextMonth()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="mt-2 text-center">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-outline-light <?= (!isset($_GET['view']) || $_GET['view'] == 'day') ? 'active' : '' ?>" onclick="changeView('day')">Hari</button>
                                <button type="button" class="btn btn-sm btn-outline-light <?= (isset($_GET['view']) && $_GET['view'] == 'week') ? 'active' : '' ?>" onclick="changeView('week')">Minggu</button>
                                <button type="button" class="btn btn-sm btn-outline-light <?= (isset($_GET['view']) && $_GET['view'] == 'month') ? 'active' : '' ?>" onclick="changeView('month')">Bulan</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <?php if ($view == 'day'): ?>
                                <!-- Enhanced Day view dengan Status Workflow -->
                                <table class="table table-bordered table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="15%">Waktu</th>
                                            <th width="85%">
                                                <?= $selectedRoom ? $selectedRoom['nama_ruang'] : 'Pilih Ruangan' ?>
                                                <div class="float-end">
                                                    <small class="text-muted">
                                                        <span class="badge bg-warning me-1">📋 PENDING</span>
                                                        <span class="badge bg-success me-1">✅ APPROVED</span>
                                                        <span class="badge bg-danger me-1">🔴 ONGOING</span>
                                                        <span class="badge bg-info">✅ SELESAI</span>
                                                    </small>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Time slots from 5:00 to 22:00 with 30-minute intervals
                                        $startTime = strtotime('05:00:00');
                                        $endTime = strtotime('22:00:00');
                                        $interval = 30 * 60; // 30 minutes
                                        
                                        $currentDateTime = new DateTime();
                                        $currentDate = $currentDateTime->format('Y-m-d');
                                        $currentTime = $currentDateTime->format('H:i:s');
                                        
                                        // Check if selected date is within booking range
                                        $isDateInRange = ($selectedDate >= $todayDate && $selectedDate <= $maxDate);
                                        
                                        for ($time = $startTime; $time <= $endTime; $time += $interval) {
                                            $timeSlot = date('H:i', $time);
                                            $nextTimeSlot = date('H:i', $time + $interval);
                                            
                                            // Check if there's a booking for this time slot
                                            $slotBooked = false;
                                            $bookingData = null;
                                            $slotStatus = '';
                                            
                                            foreach ($bookings as $booking) {
                                                $bookingStart = date('H:i', strtotime($booking['jam_mulai']));
                                                $bookingEnd = date('H:i', strtotime($booking['jam_selesai']));
                                                
                                                if ($timeSlot >= $bookingStart && $timeSlot < $bookingEnd) {
                                                    $slotBooked = true;
                                                    $bookingData = $booking;
                                                    $slotStatus = $booking['status'];
                                                    
                                                    // AUTO-ACTIVATION LOGIC: Booking approved -> active saat waktunya tiba
                                                    if ($booking['status'] === 'approve' && 
                                                        $booking['tanggal'] === $currentDate && 
                                                        $currentTime >= $booking['jam_mulai'] && 
                                                        $currentTime <= $booking['jam_selesai']) {
                                                        
                                                        // Auto-activate booking
                                                        $updateStmt = $conn->prepare("
                                                            UPDATE tbl_booking 
                                                            SET status = 'active', 
                                                                activated_at = NOW(),
                                                                activated_by = 'SYSTEM_AUTO',
                                                                activation_note = 'Auto-activated: Waktu booking telah tiba'
                                                            WHERE id_booking = ?
                                                        ");
                                                        $updateStmt->execute([$booking['id_booking']]);
                                                        
                                                        // Update status untuk display
                                                        $slotStatus = 'active';
                                                        $bookingData['status'] = 'active';
                                                        
                                                        error_log("AUTO-ACTIVATION: Booking ID {$booking['id_booking']} auto-activated (ONGOING)");
                                                    }
                                                    
                                                    break;
                                                }
                                            }
                                            
                                            // Determine row class based on booking status
                                            $rowClass = '';
                                            $slotDisabled = false;
                                            
                                            if ($holiday) {
                                            } elseif ($slotBooked) {
                                                switch ($slotStatus) {
                                                    case 'pending':
                                                        $rowClass = 'table-warning'; // Yellow - PENDING
                                                        break;
                                                    case 'approve':
                                                        $rowClass = 'table-success'; // Green - APPROVED
                                                        break;
                                                    case 'active':
                                                        $rowClass = 'table-danger'; // Red - ONGOING
                                                        break;
                                                    case 'rejected':
                                                    case 'cancelled':
                                                        $rowClass = 'table-secondary'; // Gray - Cancelled/Rejected
                                                        break;
                                                    case 'done':
                                                        $rowClass = 'table-info'; // Blue - SELESAI
                                                        break;
                                                }
                                            } else {
                                                // Disable past time slots for today
                                                if ($selectedDate == $currentDate && $timeSlot < date('H:i', strtotime($currentTime))) {
                                                    $rowClass = 'table-light';
                                                    $slotDisabled = true;
                                                }
                                                
                                                // Disable slots if date is out of booking range
                                                if (!$isDateInRange) {
                                                    $rowClass = 'table-secondary';
                                                    $slotDisabled = true;
                                                }
                                            }
                                            
                                            echo "<tr class='$rowClass time-slot' data-time='$timeSlot'>";
                                            echo "<td class='fw-bold'>{$timeSlot} - {$nextTimeSlot}</td>";
                                            
                                            echo "<td>";
                                            if ($slotBooked) {
                                                // Display enhanced booking info
                                                echo "<div class='booking-info p-2'>";
                                                echo "<div class='d-flex justify-content-between align-items-start'>";
                                                echo "<div>";
                                                echo "<h6 class='mb-1'><i class='fas fa-calendar-check me-2'></i>{$bookingData['nama_acara']}</h6>";
                                                echo "<p class='mb-1'><i class='fas fa-user me-2'></i>PIC: {$bookingData['nama_penanggungjawab']}</p>";
                                                echo "<p class='mb-1'><i class='fas fa-phone me-2'></i>{$bookingData['no_penanggungjawab']}</p>";
                                                echo "</div>";
                                                echo "<div class='text-end'>";
                                                
                                                // Enhanced status badge
                                                echo "<div class='mb-2'>";
                                                switch ($slotStatus) {
                                                    case 'pending':
                                                        echo "<span class='badge bg-warning text-dark fs-6'>📋 PENDING</span>";
                                                        echo "<br><small class='text-muted'>Menunggu persetujuan admin</small>";
                                                        break;
                                                    case 'approve':
                                                        echo "<span class='badge bg-success fs-6'>✅ APPROVED</span>";
                                                        echo "<br><small class='text-muted'>Siap digunakan</small>";
                                                        break;
                                                    case 'active':
                                                        echo "<span class='badge bg-danger fs-6 blink-badge'>🔴 ONGOING</span>";
                                                        echo "<br><small class='text-white'>Sedang berlangsung</small>";
                                                        break;
                                                    case 'rejected':
                                                        echo "<span class='badge bg-secondary fs-6'>❌ DITOLAK</span>";
                                                        break;
                                                    case 'cancelled':
                                                        echo "<span class='badge bg-secondary fs-6'>❌ DIBATALKAN</span>";
                                                        break;
                                                    case 'done':
                                                        echo "<span class='badge bg-info fs-6'>✅ SELESAI</span>";
                                                        echo "<br><small class='text-muted'>Telah selesai</small>";
                                                        break;
                                                }
                                                echo "</div>";
                                                echo "</div>";
                                                echo "</div>";
                                                
                                                // Action buttons based on status and user ownership
                                                $isUserOwner = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $bookingData['id_user']);
                                                $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');
                                                
                                                if (isset($_SESSION['user_id'])) {
                                                    echo "<div class='mt-2 d-flex gap-2 flex-wrap'>";
                                                    
                                                    // PENDING status buttons
                                                    if ($slotStatus === 'pending') {
                                                        if ($isUserOwner || $isAdmin) {
                                                            echo "<button class='btn btn-sm btn-danger' onclick='cancelBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-times'></i> Batalkan
                                                                  </button>";
                                                        }
                                                        if ($isAdmin) {
                                                            echo "<button class='btn btn-sm btn-success' onclick='approveBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-check'></i> Approve
                                                                  </button>";
                                                            echo "<button class='btn btn-sm btn-secondary' onclick='rejectBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-times'></i> Tolak
                                                                  </button>";
                                                        }
                                                    }
                                                    
                                                    // APPROVED status buttons
                                                    elseif ($slotStatus === 'approve') {
                                                        if ($isUserOwner || $isAdmin) {
                                                            echo "<button class='btn btn-sm btn-danger' onclick='cancelBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-times'></i> Batalkan
                                                                  </button>";
                                                        }
                                                        
                                                        // Manual activation button for user or admin
                                                        if (($isUserOwner || $isAdmin) && canActivateBooking($bookingData, $currentDate, $currentTime)) {
                                                            echo "<button class='btn btn-sm btn-primary activate-btn' onclick='activateBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-play'></i> Aktifkan Sekarang
                                                                  </button>";
                                                        }
                                                    }
                                                    
                                                    // ONGOING (ACTIVE) status buttons
                                                    elseif ($slotStatus === 'active') {
                                                        if ($isUserOwner || $isAdmin) {
                                                            echo "<button class='btn btn-sm btn-warning checkout-btn fw-bold' onclick='showCheckoutModal({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-sign-out-alt'></i> CHECKOUT
                                                                  </button>";
                                                        }
                                                    }
                                                    
                                                    echo "</div>";
                                                }
                                                
                                                echo "</div>";
                                            } elseif (!$slotDisabled && $selectedRoomId) {
                                                // Show booking button for available slots
                                                if ($isDateInRange) {
                                                    echo "<div class='text-center p-3'>";
                                                    echo "<button class='btn btn-outline-primary book-btn' onclick='bookTimeSlot(\"{$selectedDate}\", \"{$timeSlot}\", {$selectedRoomId})'>
                                                            <i class='fas fa-plus'></i> Pesan Ruangan
                                                          </button>";
                                                    echo "</div>";
                                                } else {
                                                    echo "<div class='text-center p-3 text-muted'>";
                                                    echo "<em>Di luar rentang waktu pemesanan</em>";
                                                    echo "</div>";
                                                }
                                            } elseif ($holiday) {
                                                // Tampilkan peringatan tapi tetap bisa booking
                                                echo "<div class='alert alert-warning mb-2 p-2'>";
                                                echo "<small><i class='fas fa-exclamation-triangle me-1'></i>";
                                                echo "Hari Libur: {$holiday['keterangan']}</small>";
                                                echo "</div>";
                                            } elseif ($slotDisabled && $holiday) {
                                                // Jika slot disabled karena alasan lain, tampilkan info hari libur
                                                echo "<div class='text-center p-3'>";
                                                echo "<div class='alert alert-info mb-0 p-2'>";
                                                echo "<small><i class='fas fa-calendar-times me-1'></i>";
                                                echo "Hari Libur: {$holiday['keterangan']}</small>";
                                                echo "</div>";
                                                echo "</div>";
                                            }elseif ($slotDisabled) {
                                                echo "<div class='text-center p-3 text-muted'>";
                                                if (!$isDateInRange) {
                                                    echo "<em>Di luar rentang waktu pemesanan</em>";
                                                } else {
                                                    echo "<em>Waktu sudah berlalu</em>";
                                                }
                                                echo "</div>";
                                            } else {
                                                echo "<div class='text-center p-3 text-muted'>";
                                                echo "<em>Pilih ruangan terlebih dahulu</em>";
                                                echo "</div>";
                                            }
                                            echo "</td>";
                                            echo "</tr>";
                                        }
                                        // Dalam loop time slots di day view, tambahkan setelah status checking:
                                        if ($slotBooked && $bookingData['booking_type'] === 'recurring') {
                                            // Display academic schedule info
                                            echo "<div class='booking-info p-2 academic-schedule'>";
                                            echo "<div class='d-flex justify-content-between align-items-start'>";
                                            echo "<div>";
                                            echo "<h6 class='mb-1'><i class='fas fa-graduation-cap me-2'></i>{$bookingData['nama_matakuliah']}</h6>";
                                            echo "<p class='mb-1'><i class='fas fa-users me-2'></i>Kelas: {$bookingData['kelas']}</p>";
                                            echo "<p class='mb-1'><i class='fas fa-user-tie me-2'></i>Dosen: {$bookingData['dosen_pengampu']}</p>";
                                            echo "<p class='mb-1'><i class='fas fa-calendar-alt me-2'></i>{$bookingData['semester']} {$bookingData['tahun_akademik']}</p>";
                                            echo "</div>";
                                            echo "<div class='text-end'>";
                                            echo "<span class='badge bg-info fs-6'>📚 PERKULIAHAN</span>";
                                            echo "<br><small class='text-muted'>Jadwal Rutin</small>";
                                            echo "</div>";
                                            echo "</div>";
                                            
                                            // Add detail button for academic schedules
                                            echo "<div class='mt-2'>";
                                            echo "<button class='btn btn-sm btn-outline-info' onclick='showAcademicDetail({$bookingData['id_booking']})'>
                                                    <i class='fas fa-info-circle'></i> Detail Jadwal
                                                </button>";
                                            echo "</div>";
                                            echo "</div>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            
                                <!-- Enhanced Checkout Modal -->
                                <div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-sign-out-alt me-2"></i>Checkout Ruangan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Status akan berubah menjadi SELESAI</strong><br>
                    Dengan keterangan: "Ruangan sudah di-checkout oleh mahasiswa"
                </div>
                <div id="checkoutDetails">
                    <!-- Details will be filled by JavaScript -->
                </div>
                
                <!-- FIXED: Checkbox with proper styling -->
                <div class="form-check mt-3 p-3 bg-light rounded">
                    <input class="form-check-input" type="checkbox" id="confirmCheckout" required>
                    <label class="form-check-label fw-bold" for="confirmCheckout">
                        <i class="fas fa-check-circle me-2 text-success"></i>
                        Saya konfirmasi bahwa ruangan sudah selesai digunakan dan dalam kondisi bersih
                    </label>
                    <div class="form-text">
                        <i class="fas fa-exclamation-triangle me-1 text-warning"></i>
                        Centang kotak ini untuk mengaktifkan tombol checkout
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Batal
                </button>
                <button type="button" class="btn btn-secondary fw-bold" id="confirmCheckoutBtn" disabled>
                    <i class="fas fa-check me-2"></i>Ya, Checkout Sekarang
                </button>
            </div>
        </div>
    </div>
</div>
                            <?php endif; ?> <?php if ($view == 'week'): ?>
                                <!-- Week view -->
                                <table class="table table-bordered table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="15%">Waktu</th>
                                            <?php 
$currentDay = new DateTime($weekStart);
$endDay = new DateTime($weekEnd);
while ($currentDay <= $endDay) {
    $dayClass = '';
    $dayNum = $currentDay->format('N'); // 1 (Monday) to 7 (Sunday)
    $isWeekend = ($dayNum == 6 || $dayNum == 7); // 6 = Saturday, 7 = Sunday
    
    if ($currentDay->format('Y-m-d') == date('Y-m-d')) {
        $dayClass = 'table-primary';
    } elseif ($isWeekend) {
        $dayClass = 'table-light';
    }
    
    echo '<th class="' . $dayClass . ' text-center" width="' . (85/7) . '%">';
    // Tambahkan class khusus untuk weekend day name
    $dayNameClass = $isWeekend ? 'day-header-weekend' : '';
    echo '<span class="day-name ' . $dayNameClass . '">' . $currentDay->format('D') . '</span><br>' . 
         $currentDay->format('d/m');
    echo '</th>';
    
    $currentDay->modify('+1 day');
}
?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Time slots for week view
                                        $startTime = strtotime('07:00:00');
                                        $endTime = strtotime('17:00:00');
                                        $interval = 60 * 60; // 1 hour for week view
                                        
                                        for ($time = $startTime; $time <= $endTime; $time += $interval) {
                                            $timeSlot = date('H:i', $time);
                                            $nextTimeSlot = date('H:i', $time + $interval);
                                            
                                            echo "<tr class='time-slot' data-time='$timeSlot'>";
                                            echo "<td>{$timeSlot} - {$nextTimeSlot}</td>";
                                            
                                            // For each day of the week
                                            $currentDay = new DateTime($weekStart);
                                            $endDay = new DateTime($weekEnd);
                                            
                                            while ($currentDay <= $endDay) {
                                                $dayDate = $currentDay->format('Y-m-d');
                                                
                                                // Check if it's a holiday
                                                $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
                                                $stmt->execute([$dayDate]);
                                                $dayHoliday = $stmt->fetch(PDO::FETCH_ASSOC);
                                                
                                                // Check if there's a booking for this day and time slot
                                                $cellBooked = false;
                                                $cellBooking = null;
                                                
                                                foreach ($bookings as $booking) {
                                                    if ($booking['tanggal'] == $dayDate) {
                                                        $bookingStart = date('H:i', strtotime($booking['jam_mulai']));
                                                        $bookingEnd = date('H:i', strtotime($booking['jam_selesai']));
                                                        
                                                        if (($timeSlot >= $bookingStart && $timeSlot < $bookingEnd) || 
                                                            ($bookingStart >= $timeSlot && $bookingStart < $nextTimeSlot)) {
                                                            $cellBooked = true;
                                                            $cellBooking = $booking;
                                                            break;
                                                        }
                                                    }
                                                }
                                                
                                                // Determine cell class based on booking status or holiday
                                                $cellClass = '';
                                                $isDisabled = false;
                                                
                                                if ($dayHoliday) {
                                                    $cellClass = 'table-warning';
                                                    $isDisabled = false;
                                                } elseif ($cellBooked) {
                                                    switch ($cellBooking['status']) {
                                                        case 'pending':
                                                            $cellClass = 'table-warning';
                                                            break;
                                                        case 'approve':
                                                            $cellClass = 'table-success';
                                                            break;
                                                        case 'active':
                                                            $cellClass = 'table-danger';
                                                            break;
                                                        case 'rejected':
                                                        case 'cancelled':
                                                            $cellClass = 'table-secondary';
                                                            break;
                                                        case 'done':
                                                            $cellClass = 'table-info';
                                                            break;
                                                    }
                                                } elseif ($dayDate < $todayDate || $dayDate > $maxDate) {
                                                    $cellClass = 'table-secondary';
                                                    $isDisabled = true;
                                                } elseif ($dayDate == $currentDate && $timeSlot < date('H:i')) {
                                                    $cellClass = 'table-secondary';
                                                    $isDisabled = true;
                                                }
                                                
                                                echo "<td class='$cellClass'>";
                                                if ($cellBooked) {
                                                    echo "<small><strong>{$cellBooking['nama_acara']}</strong></small><br>";
                                                    echo "<span class='badge ";
                                                    
                                                    switch ($cellBooking['status']) {
                                                        case 'pending':
                                                            echo "bg-warning";
                                                            break;
                                                        case 'approve':
                                                            echo "bg-success";
                                                            break;
                                                        case 'active':
                                                            echo "bg-danger";
                                                            break;
                                                        case 'rejected':
                                                        case 'cancelled':
                                                            echo "bg-secondary";
                                                            break;
                                                        case 'done':
                                                            echo "bg-info";
                                                            break;
                                                    }
                                                    
                                                    echo "'>{$cellBooking['status']}</span>";
                                                } elseif ($dayHoliday) {
                                                    echo "<small class='text-muted'>{$dayHoliday['keterangan']}</small>";
                                                } elseif (!$isDisabled && $selectedRoomId) {
                                                    echo "<button class='btn btn-sm btn-outline-primary book-btn' onclick='bookTimeSlot(\"{$dayDate}\", \"{$timeSlot}\", {$selectedRoomId})'>Pesan</button>";
                                                }
                                                echo "</td>";
                                                
                                                $currentDay->modify('+1 day');
                                            }
                                            
                                            echo "</tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            <?php
// Ini adalah bagian month view yang diperbaiki untuk mengganti kode yang error

elseif ($view == 'month'): ?>
    <!-- Month view dengan event yang bisa diklik -->
    <table class="table table-bordered table-hover mb-0">
        <thead class="bg-light">
            <tr>
                <th class="text-center" style="color: var(--danger-color);">Minggu</th>
                <th class="text-center">Senin</th>
                <th class="text-center">Selasa</th>
                <th class="text-center">Rabu</th>
                <th class="text-center">Kamis</th>
                <th class="text-center">Jumat</th>
                <th class="text-center" style="color: var(--danger-color);">Sabtu</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // PERBAIKAN: Tambahkan display properties sebelum digunakan
            if (!empty($bookings)) {
                addBookingDisplayProperties($bookings);
            }
            
            // Get the first day of the month
            $firstDayOfMonth = strtotime($monthStart);
            $lastDayOfMonth = strtotime($monthEnd);
            
            // Get the day of week of the first day (0 = Sunday, 6 = Saturday)
            $firstDayOfWeek = date('w', $firstDayOfMonth);
            
            // Calculate the date of the first cell in the calendar (might be in previous month)
            $startingDate = strtotime("-{$firstDayOfWeek} day", $firstDayOfMonth);
            
            // Generate calendar grid
            $currentDate = $startingDate;
            
            // Loop for up to 6 weeks
            for ($i = 0; $i < 6; $i++) {
                echo "<tr>";
                
                // Loop for each day of the week
                for ($j = 0; $j < 7; $j++) {
                    $dateString = date('Y-m-d', $currentDate);
                    $dayOfMonth = date('j', $currentDate);
                    
                    // Determine if the date is in the current month
                    $isCurrentMonth = (date('m Y', $currentDate) == date('m Y', $firstDayOfMonth));
                    
                    // Determine if it's today
                    $isToday = ($dateString == date('Y-m-d'));
                    
                    // Get cell class
                    $cellClass = 'calendar-month-cell';
                    if (!$isCurrentMonth) {
                        $cellClass .= ' table-secondary';
                    } elseif ($isToday) {
                        $cellClass .= ' table-primary';
                    }
                    
                    // Check if it's a holiday
                    $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
                    $stmt->execute([$dateString]);
                    $dateHoliday = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($dateHoliday) {
                        $cellClass .= ' table-light';
                    }
                    
                    echo "<td class='$cellClass' style='height: 120px; vertical-align: top;'>";
                    
                    // Date header dengan warna weekend
                    echo "<div class='calendar-month-day'>";
                    $dayStyle = ($j == 0 || $j == 6) ? 'color: var(--danger-color);' : '';
                    echo "<span style='$dayStyle'>{$dayOfMonth}</span>";
                    if ($dateHoliday) {
                        echo " <span class='badge bg-danger'><i class='fas fa-star'></i></span>";
                    }
                    echo "</div>";
                    
                    // Show events if in current month
                    if ($isCurrentMonth && $selectedRoomId) {
                        echo "<div class='calendar-month-events'>";
                        
                        // Find bookings for this day
                        $dayBookings = [];
                        foreach ($bookings as $booking) {
                            if ($booking['tanggal'] == $dateString) {
                                $dayBookings[] = $booking;
                            }
                        }
                        
                        // Display bookings
                        if (count($dayBookings) > 0) {
                            foreach ($dayBookings as $index => $booking) {
                                if ($index < 3) { // Show max 3 events per day
                                    
                                    // PERBAIKAN: Pastikan properti ada sebelum digunakan
                                    $eventClass = isset($booking['display_class']) ? $booking['display_class'] : 'bg-light';
                                    $eventIcon = isset($booking['display_icon']) ? $booking['display_icon'] : '📋';
                                    $eventText = isset($booking['display_text']) ? $booking['display_text'] : 'Unknown Event';
                                    
                                    // Determine event style based on type
                                    if ($booking['booking_type'] === 'recurring') {
                                        // Academic schedule styling - ORANGE THEME
                                        $eventClass = 'calendar-month-event bg-orange text-white academic-event';
                                        $eventIcon = '📚';
                                        $courseName = isset($booking['nama_matakuliah']) ? $booking['nama_matakuliah'] : 'Unknown Course';
                                        $eventText = substr($courseName, 0, 15) . (strlen($courseName) > 15 ? '...' : '');
                                        $className = isset($booking['kelas']) ? $booking['kelas'] : 'Unknown Class';
                                        $dosenName = isset($booking['dosen_pengampu']) ? $booking['dosen_pengampu'] : 'Unknown Lecturer';
                                        $eventTitle = "Perkuliahan: {$courseName} - {$className}\nDosen: {$dosenName}\nWaktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']);
                                        
                                        echo "<div class='$eventClass' 
                                        style='cursor: pointer; margin-bottom: 2px; padding: 2px 6px; border-radius: 3px; font-size: 0.75rem; 
                                               background: linear-gradient(135deg, #ff8c00, #ff7700) !important; 
                                               border-left: 4px solid #ffd700 !important; color: white !important;'
                                        data-booking-id='{$booking['id_booking']}'
                                        data-booking-type='{$booking['booking_type']}'
                                        title='$eventTitle'
                                        onclick='showEventDetail({$booking['id_booking']})'>";
                                        
                                        echo $eventIcon . " " . formatTime($booking['jam_mulai']) . " " . $eventText;
                                        echo "</div>";
                                    } else {
                                        // Regular booking styling
                                        $eventClass = 'calendar-month-event ' . $eventClass;
                                        $eventText = substr($booking['nama_acara'], 0, 15) . '...';
                                        $eventTitle = "Acara: {$booking['nama_acara']}\nPIC: {$booking['nama_penanggungjawab']}\nWaktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']);
                                        
                                        echo "<div class='$eventClass clickable-event' 
                                        style='cursor: pointer; margin-bottom: 2px; padding: 2px 6px; border-radius: 3px; font-size: 0.75rem;'
                                        data-booking-id='{$booking['id_booking']}'
                                        data-booking-type='{$booking['booking_type']}'
                                        title='$eventTitle'
                                        onclick='showEventDetail({$booking['id_booking']})'>";
                                        
                                        echo $eventIcon . " " . formatTime($booking['jam_mulai']) . " " . $eventText;
                                        echo "</div>";
                                    }
                                }
                            }
                            
                            if (count($dayBookings) > 3) {
                                echo "<div class='calendar-month-event bg-light text-dark' style='cursor: pointer; padding: 2px 6px; border-radius: 3px; font-size: 0.75rem;'
                                        onclick='showDayDetail(\"$dateString\")'>";
                                echo "+" . (count($dayBookings) - 3) . " lainnya";
                                echo "</div>";
                            }
                        } elseif ($dateString >= $todayDate && $dateString <= $maxDate && !$dateHoliday) {
                            // Show "Available" for future dates within booking range
                            echo "<a href='index.php?date=$dateString&view=day&room_id=$selectedRoomId' class='text-success' style='font-size: 0.75rem;'>
                                    <i class='fas fa-plus-circle'></i> Tersedia
                                </a>";
                        }
                        
                        echo "</div>";
                    }
                    
                    echo "</td>";
                    
                    // Move to the next day
                    $currentDate = strtotime('+1 day', $currentDate);
                }
                
                echo "</tr>";
                
                // Stop if we've gone past the end of the month
                if ($currentDate > $lastDayOfMonth && date('j', $currentDate) > 7) {
                    break;
                }
            }
            ?>
        </tbody>
    </table>

    <?php if (isset($_SESSION['auto_completion_info']) && $_SESSION['auto_completion_info']['completed_count'] > 0): ?>
    <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
        <i class="fas fa-robot me-2"></i>
        <strong>Auto-Update:</strong> 
        <?= $_SESSION['auto_completion_info']['completed_count'] ?> booking yang sudah expired telah otomatis diselesaikan.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['auto_completion_info']); ?>
    <?php endif; ?>

    <!-- Event Detail Modal untuk Month View -->
    <div class="modal fade" id="eventDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Detail Peminjaman</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="eventDetailBody">
                    <!-- Content will be loaded dynamically -->
                </div>
                <div class="modal-footer" id="eventDetailFooter">
                    <!-- Action buttons will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Day Detail Modal untuk melihat semua event dalam satu hari -->
    <div class="modal fade" id="dayDetailModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Semua Peminjaman pada <span id="dayDetailDate"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="dayDetailBody">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Tambahkan script untuk handle event clicks -->
    <script>
    let eventDetailModal;
    let dayDetailModal;

    document.addEventListener('DOMContentLoaded', function() {
        eventDetailModal = new bootstrap.Modal(document.getElementById('eventDetailModal'));
        dayDetailModal = new bootstrap.Modal(document.getElementById('dayDetailModal'));
    });

    function showEventDetail(bookingId) {
        // Fetch event details via AJAX
        fetch(`get_booking_detail.php?id=${bookingId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const booking = data.booking;
                    
                    // Populate modal content
                    document.getElementById('eventDetailBody').innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Informasi Acara</h6>
                                <table class="table table-borderless table-sm">
                                    <tr><th>Nama Acara:</th><td>${booking.nama_acara}</td></tr>
                                    <tr><th>Tanggal:</th><td>${booking.formatted_date}</td></tr>
                                    <tr><th>Waktu:</th><td>${booking.jam_mulai} - ${booking.jam_selesai}</td></tr>
                                    <tr><th>Status:</th><td>${booking.status_badge}</td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Informasi Ruangan & PIC</h6>
                                <table class="table table-borderless table-sm">
                                    <tr><th>Ruangan:</th><td>${booking.nama_ruang}</td></tr>
                                    <tr><th>Gedung:</th><td>${booking.nama_gedung}</td></tr>
                                    <tr><th>PIC:</th><td>${booking.nama_penanggungjawab}</td></tr>
                                    <tr><th>No. HP:</th><td>${booking.no_penanggungjawab}</td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6>Keterangan</h6>
                                <p class="text-muted">${booking.keterangan || 'Tidak ada keterangan'}</p>
                            </div>
                        </div>
                    `;
                    
                    // Populate footer with action buttons
                    let footerButtons = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>';
                    
                    // Add action buttons based on user role and booking status
                    if (booking.can_activate) {
                        footerButtons += `<button type="button" class="btn btn-success ms-2" onclick="activateBooking(${booking.id_booking})">
                                            <i class="fas fa-play"></i> Aktifkan Sekarang
                                        </button>`;
                    }
                    
                    if (booking.can_cancel) {
                        footerButtons += `<button type="button" class="btn btn-danger ms-2" onclick="cancelBooking(${booking.id_booking})">
                                            <i class="fas fa-times"></i> Batalkan
                                        </button>`;
                    }
                    
                    if (booking.can_checkout) {
                        footerButtons += `<button type="button" class="btn btn-warning ms-2" onclick="checkoutBooking(${booking.id_booking})">
                                            <i class="fas fa-sign-out-alt"></i> Checkout
                                        </button>`;
                    }
                    
                    document.getElementById('eventDetailFooter').innerHTML = footerButtons;
                    
                    eventDetailModal.show();
                } else {
                    alert('Gagal memuat detail peminjaman');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat memuat detail');
            });
    }

    function showDayDetail(date) {
        // Show all bookings for a specific day
        const roomId = new URLSearchParams(window.location.search).get('room_id');
        
        fetch(`get_day_bookings.php?date=${date}&room_id=${roomId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('dayDetailDate').textContent = data.formatted_date;
                    
                    let content = '<div class="table-responsive"><table class="table table-striped">';
                    content += '<thead><tr><th>Waktu</th><th>Acara</th><th>Status</th><th>PIC</th><th>Aksi</th></tr></thead><tbody>';
                    
                    data.bookings.forEach(booking => {
                        content += `<tr>
                            <td>${booking.jam_mulai} - ${booking.jam_selesai}</td>
                            <td>${booking.nama_acara}</td>
                            <td>${booking.status_badge}</td>
                            <td>${booking.nama_penanggungjawab}</td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="showEventDetail(${booking.id_booking})">
                                    <i class="fas fa-eye"></i> Detail
                                </button>
                            </td>
                        </tr>`;
                    });
                    
                    content += '</tbody></table></div>';
                    
                    document.getElementById('dayDetailBody').innerHTML = content;
                    dayDetailModal.show();
                } else {
                    alert('Gagal memuat data peminjaman hari ini');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat memuat data');
            });
    }

    function activateBooking(bookingId) {
        if (confirm('Apakah Anda yakin ingin mengaktifkan peminjaman ini sekarang?')) {
            fetch('activate_booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `booking_id=${bookingId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload(); // Refresh page to show updated status
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat mengaktifkan booking');
            });
        }
    }
    </script>
<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Updated Login Modal dengan Role Selector -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="loginModalLabel">Login Sistem Peminjaman Ruangan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Tab Navigation - DOSEN SEBAGAI DEFAULT -->
                <ul class="nav nav-tabs mb-3" id="loginTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="nik-tab" data-bs-toggle="tab" data-bs-target="#nik-login" type="button" role="tab">
                            <i class="fas fa-id-card me-2"></i>Login Dosen
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email-login" type="button" role="tab">
                            <i class="fas fa-envelope me-2"></i>Login Operator
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                    <!-- NIK Login Tab - ACTIVE BY DEFAULT -->
                    <div class="tab-pane fade show active" id="nik-login" role="tabpanel">
                        <!-- Ganti form NIK login dengan ini -->
                        <form id="nikLoginForm" method="POST" onsubmit="return false;">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Login Khusus Dosen:</strong> Gunakan NIK dan password yang telah terdaftar di sistem.
                            </div>
                            
                            <div class="mb-3">
                                <label for="nik_input" class="form-label">NIK (Nomor Identitas Kepegawaian)</label>
                                <input type="text" class="form-control" id="nik_input" name="nik" required 
                                    maxlength="20" 
                                    pattern="[\d\.]{8,20}"
                                    placeholder="Contoh: 203.111.111 atau 203111111">
                                <div class="form-text">Masukkan NIK dengan atau tanpa titik</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="nik_password" class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="nik_password" name="password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleNikPassword">
                                        <i class="fas fa-eye" id="toggleNikPasswordIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Alert containers -->
                            <div id="nikLoginError" class="alert alert-danger d-none" role="alert"></div>
                            <div id="nikLoginSuccess" class="alert alert-success d-none" role="alert"></div>
                            
                            <div class="d-grid mb-3">
                                <button type="button" class="btn btn-success" id="nikLoginBtn" onclick="handleNikLogin(event)">
                                    <i class="fas fa-id-card me-2"></i>Login Dosen
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Email Login Tab -->
                    <div class="tab-pane fade" id="email-login" role="tabpanel">
                        <form id="emailLoginForm" method="POST" action="process-login.php">
                            <div class="mb-3">
                                <label for="email_login_role" class="form-label">Login Sebagai</label>
                                <select class="form-select" id="email_login_role" name="login_role" required>
                                    <option value="">-- Pilih Role --</option>
                                    <option value="admin">Administrator</option>
                                    <option value="cs">Customer Service</option>
                                    <option value="keuangan">Keuangan</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email_input" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email_input" name="email" required 
                                       placeholder="contoh: nama@stie-mce.ac.id">
                            </div>
                            
                            <div class="mb-3">
                                <label for="email_password" class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="email_password" name="password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleEmailPassword">
                                        <i class="fas fa-eye" id="toggleEmailPasswordIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div id="emailLoginError" class="alert alert-danger d-none"></div>
                            <div id="emailLoginSuccess" class="alert alert-success d-none"></div>
                            
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary" id="emailLoginBtn">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login Operator
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="text-center">
                    <h6 class="text-muted">Info Login:</h6>
                    <div class="row">
                        <div class="col-6">
                            <small class="text-muted">
                                <strong>Dosen:</strong><br>
                                NIK: Sesuai database<br>
                                Password: NIK tanpa titik
                            </small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">
                                <strong>Admin:</strong><br>
                                admin@stie-mce.ac.id<br>
                                Password: 12345678
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
console.log('🔧 Complete login modal loaded');

// ===== PASSWORD TOGGLE FUNCTIONALITY =====
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('toggleCompletePassword');
    const passwordInput = document.getElementById('complete_password');
    const toggleIcon = document.getElementById('toggleCompletePasswordIcon');
    
    if (togglePassword && passwordInput && toggleIcon) {
        console.log('✅ Password toggle elements found');
        
        togglePassword.addEventListener('click', function() {
            console.log('👁️ Password toggle clicked');
            
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Update icon
            if (type === 'password') {
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
                console.log('🙈 Password hidden');
            } else {
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
                console.log('👁️ Password visible');
            }
        });
    } else {
        console.error('❌ Password toggle elements not found');
    }
});

// ===== EMAIL SUGGESTION BASED ON ROLE =====
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('complete_login_role');
    const emailInput = document.getElementById('complete_email');
    
    if (roleSelect && emailInput) {
        roleSelect.addEventListener('change', function() {
            const role = this.value;
            let emailSuggestion = '';
            
            switch (role) {
                case 'mahasiswa':
                    emailSuggestion = '@mhs.stie-mce.ac.id';
                    emailInput.placeholder = 'contoh: nim' + emailSuggestion;
                    break;
                case 'dosen':
                case 'karyawan':
                case 'admin':
                case 'cs':
                case 'satpam':
                    emailSuggestion = '@stie-mce.ac.id';
                    emailInput.placeholder = 'contoh: nama' + emailSuggestion;
                    break;
                default:
                    emailInput.placeholder = 'contoh: nama@stie-mce.ac.id';
            }
        });
    }
});

// ===== AJAX LOGIN ALTERNATIVE =====
function tryCompleteAjaxLogin() {
    console.log('🚀 Trying AJAX login...');
    
    const email = document.getElementById('complete_email').value.trim();
    const password = document.getElementById('complete_password').value.trim();
    const role = document.getElementById('complete_login_role').value.trim();
    
    // Validate
    if (!email || !password || !role) {
        showCompleteAlert('❌ Semua field harus diisi!', 'danger');
        return;
    }
    
    // Show loading
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
    
    // Create form data
    const formData = new FormData();
    formData.append('email', email);
    formData.append('password', password);
    formData.append('login_role', role);
    
    console.log('📡 Sending AJAX request...');
    
    fetch('process-login.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log('📡 Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('❌ Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ AJAX response:', data);
        
        if (data.success) {
            showCompleteAlert('🎉 AJAX Login berhasil! Redirecting...', 'success');
            
            // Update UI
            document.body.classList.add('logged-in');
            
            // Store user data
            if (data.user) {
                document.body.dataset.userId = data.user.id;
                document.body.dataset.userRole = data.user.role;
                window.userId = data.user.id;
                window.userRole = data.user.role;
            }
            
            // Close modal and redirect
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('loginModal'));
                if (modal) modal.hide();
                
                if (data.redirect && data.redirect !== 'index.php') {
                    window.location.href = data.redirect;
                } else {
                    window.location.reload();
                }
            }, 1500);
            
        } else {
            showCompleteAlert('❌ AJAX Login gagal: ' + data.message, 'danger');
        }
    })
    .catch(error => {
        console.error('💥 AJAX Error:', error);
        showCompleteAlert('💥 AJAX Error: ' + error.message + '<br><small>Gunakan tombol Login biasa sebagai alternatif.</small>', 'danger');
    })
    .finally(() => {
        // Reset button
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// ===== HELPER FUNCTIONS =====
function showCompleteAlert(message, type = 'info') {
    // Remove existing alerts
    const existingAlerts = document.querySelectorAll('#completeLoginError, #completeLoginSuccess');
    existingAlerts.forEach(alert => alert.classList.add('d-none'));
    
    // Show new alert
    const alertDiv = type === 'danger' ? 
        document.getElementById('completeLoginError') : 
        document.getElementById('completeLoginSuccess');
    
    if (alertDiv) {
        alertDiv.innerHTML = message;
        alertDiv.classList.remove('d-none');
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            alertDiv.classList.add('d-none');
        }, 5000);
    }
}

// ===== AUTO-FILL ON MODAL OPEN =====
document.addEventListener('DOMContentLoaded', function() {
    const loginModal = document.getElementById('loginModal');
    if (loginModal) {
        loginModal.addEventListener('shown.bs.modal', function () {
            console.log('👁️ Complete login modal opened');
            
            // Focus email input
            const emailInput = document.getElementById('complete_email');
            if (emailInput) {
                setTimeout(() => emailInput.focus(), 100);
            }
        });
    }
});

console.log('✅ Complete login modal script loaded successfully');
</script>

    <!-- Booking Modal -->
    <div class="modal fade" id="bookingModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-gradient-primary text-white">
                    <h4 class="modal-title d-flex align-items-center">
                        <i class="fas fa-chalkboard-teacher fa-lg me-3"></i>
                        <div>
                            <div>Booking Ruangan Perkuliahan</div>
                            <small class="opacity-75" id="roomTimeDisplay">Pilih waktu dan ruangan</small>
                        </div>
                    </h4>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-0">
                    <!-- DOSEN PROFILE HEADER -->
                    <div class="bg-light border-bottom p-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-dosen me-3">
                                        <i class="fas fa-user-graduate fa-2x text-primary"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-1" id="dosenNamaProfile">Loading...</h5>
                                        <div class="text-muted small">
                                            <span id="dosenNikProfile">NIK: Loading...</span> • 
                                            <span id="dosenJabatanProfile">Dosen</span>
                                        </div>
                                        <div class="text-muted small">
                                            <i class="fas fa-envelope me-1"></i>
                                            <span id="dosenEmailProfile">email@domain.com</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="badge bg-success fs-6 px-3 py-2">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Auto-Approved
                                </div>
                                <div class="small text-muted mt-1">Booking langsung disetujui</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-4">
                        <form id="dosenBookingForm" novalidate>
                            <input type="hidden" id="booking_date" name="tanggal">
                            <input type="hidden" id="room_id" name="id_ruang">
                            
                            <!-- BOOKING INFO DISPLAY -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <div class="info-icon">
                                            <i class="fas fa-calendar-day text-primary"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Tanggal</div>
                                            <div class="info-value" id="displayDate">Loading...</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <div class="info-icon">
                                            <i class="fas fa-door-open text-success"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Ruangan</div>
                                            <div class="info-value" id="displayRoom">Loading...</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <div class="info-icon">
                                            <i class="fas fa-users text-info"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Kapasitas</div>
                                            <div class="info-value" id="displayCapacity">Loading...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- WAKTU PERKULIAHAN -->
                            <div class="form-section">
                                <h6 class="section-title">
                                    <i class="fas fa-clock me-2"></i>Waktu Perkuliahan
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Jam Mulai</label>
                                        <input type="time" class="form-control form-control-lg" id="jam_mulai" name="jam_mulai" required>
                                        <div class="invalid-feedback">Jam mulai harus diisi</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Jam Selesai</label>
                                        <input type="time" class="form-control form-control-lg" id="jam_selesai" name="jam_selesai" required>
                                        <div class="invalid-feedback">Jam selesai harus lebih dari jam mulai</div>
                                        <div class="form-text">
                                            <span id="durationDisplay" class="text-primary fw-semibold"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- INFORMASI MATA KULIAH -->
                            <div class="form-section">
                                <h6 class="section-title">
                                    <i class="fas fa-book me-2"></i>Informasi Mata Kuliah
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-8">
                                        <label class="form-label fw-semibold">Nama Mata Kuliah <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg" id="mata_kuliah" name="mata_kuliah" 
                                            placeholder="Contoh: Akuntansi Dasar, Manajemen Keuangan, Statistik Bisnis" required>
                                        <div class="invalid-feedback">Mata kuliah harus diisi</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Kelas</label>
                                        <input type="text" class="form-control form-control-lg" id="kelas" name="kelas" 
                                            placeholder="A, B, C">
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Semester</label>
                                        <select class="form-select form-select-lg" id="semester" name="semester">
                                            <option value="">-- Pilih Semester --</option>
                                            <option value="1">Semester 1</option>
                                            <option value="2">Semester 2</option>
                                            <option value="3">Semester 3</option>
                                            <option value="4">Semester 4</option>
                                            <option value="5">Semester 5</option>
                                            <option value="6">Semester 6</option>
                                            <option value="7">Semester 7</option>
                                            <option value="8">Semester 8</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Tahun Akademik</label>
                                        <select class="form-select form-select-lg" id="tahun_akademik" name="tahun_akademik">
                                            <option value="">-- Pilih Tahun --</option>
                                            <option value="2024/2025">2024/2025</option>
                                            <option value="2025/2026">2025/2026</option>
                                            <option value="2026/2027">2026/2027</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Periode</label>
                                        <select class="form-select form-select-lg" id="periode" name="periode">
                                            <option value="">-- Pilih Periode --</option>
                                            <option value="Ganjil">Semester Ganjil</option>
                                            <option value="Genap">Semester Genap</option>
                                            <option value="Pendek">Semester Pendek</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- JENIS KEGIATAN -->
                            <div class="form-section">
                                <h6 class="section-title">
                                    <i class="fas fa-sticky-note me-2"></i>Jenis Kegiatan Akademik <span class="text-muted small">(Opsional)</span>
                                </h6>
                                <textarea class="form-control" id="catatan_tambahan" name="catatan_tambahan" rows="3" 
                                        placeholder="Tambahkan informasi khusus seperti adanya perubahan jadwal / pergantian kelas"></textarea>
                            </div>
                            
                            <!-- QUICK ACTIONS -->
                            <div class="form-section">
                                <h6 class="section-title">
                                    <i class="fas fa-magic me-2"></i>Quick Actions
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="fillSampleData()">
                                            <i class="fas fa-lightbulb me-1"></i>Isi Data Contoh
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="clearForm()">
                                            <i class="fas fa-eraser me-1"></i>Bersihkan Form
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- ERROR/SUCCESS MESSAGES -->
                            <div id="bookingError" class="alert alert-danger d-none" role="alert"></div>
                            <div id="bookingSuccess" class="alert alert-success d-none" role="alert"></div>
                        </form>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <div class="row w-100 align-items-center">
                        <div class="col-md-6">
                            <div class="text-muted small">
                                <i class="fas fa-info-circle me-1"></i>
                                Booking akan langsung disetujui untuk kegiatan akademik
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="button" class="btn btn-light me-2" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Batal
                            </button>
                            <button type="submit" form="dosenBookingForm" class="btn btn-primary btn-lg px-4" id="submitDosenBooking">
                                <i class="fas fa-check me-2"></i>Konfirmasi Booking
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Modal Detail Jadwal Kuliah -->
<div class="modal fade" id="academicDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-graduation-cap me-2"></i>Detail Jadwal Perkuliahan
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="academicDetailBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
</div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="confirmationModalLabel">Konfirmasi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmationMessage"></p>
                    <input type="hidden" id="confirmationId">
                    <input type="hidden" id="confirmationType">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tidak</button>
                    <button type="button" class="btn btn-danger" id="confirmButton">Ya</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include 'footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
    // Initialize checkout modal
    const checkoutModalElement = document.getElementById('checkoutModal');
    if (checkoutModalElement && typeof bootstrap !== 'undefined') {
        checkoutModal = new bootstrap.Modal(checkoutModalElement);
    }
    
    // Ensure checkbox functionality
    const confirmCheckbox = document.getElementById('confirmCheckbox');
    const confirmBtn = document.getElementById('confirmCheckoutBtn');
    
    if (confirmCheckbox && confirmBtn) {
        confirmCheckbox.addEventListener('change', function() {
            if (this.checked) {
                confirmBtn.disabled = false;
                confirmBtn.classList.remove('btn-secondary');
                confirmBtn.classList.add('btn-warning');
                confirmBtn.innerHTML = '<i class="fas fa-check me-2"></i>Ya, Checkout Sekarang';
            } else {
                confirmBtn.disabled = true;
                confirmBtn.classList.remove('btn-warning');
                confirmBtn.classList.add('btn-secondary');
                confirmBtn.innerHTML = '<i class="fas fa-check me-2"></i>Ya, Checkout Sekarang';
            }
        });
        
        // Add click handler for checkout button
        confirmBtn.addEventListener('click', function() {
            if (currentCheckoutBookingId && confirmCheckbox.checked) {
                processEnhancedCheckout(currentCheckoutBookingId);
            } else if (!confirmCheckbox.checked) {
                showAlert('❌ Harap centang konfirmasi terlebih dahulu', 'warning');
                // Add shake animation to checkbox
                confirmCheckbox.parentElement.style.animation = 'shake 0.5s ease-in-out';
                setTimeout(() => {
                    confirmCheckbox.parentElement.style.animation = '';
                }, 500);
            }
        });
    }
});

// Add shake animation CSS
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    
    .form-check:hover {
        background-color: #f8f9fa !important;
        border-radius: 8px;
    }
    
    .form-check-input:checked {
        background-color: #28a745;
        border-color: #28a745;
    }
    
    .form-check-input:focus {
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }
`;
document.head.appendChild(shakeStyle);
// Fix untuk tombol pesan ruang - tambahkan sebelum </body> di index.php
function bookTimeSlot(date, time, roomId) {
    // Check if user is logged in
    const isLoggedIn = document.body.classList.contains('logged-in') || 
                      <?= json_encode(isLoggedIn()) ?>;
    
    if (!isLoggedIn) {
        // Store booking data and show login modal
        sessionStorage.setItem('pendingBooking', JSON.stringify({
            date: date,
            time: time,
            roomId: roomId
        }));
        
        if (typeof loginModal !== 'undefined' && loginModal) {
            loginModal.show();
        } else {
            // Fallback jika modal tidak tersedia
            const modal = new bootstrap.Modal(document.getElementById('loginModal'));
            modal.show();
        }
    } else {
        showBookingForm(date, time, roomId);
    }
}

function updateDosenProfile(loginStatus) {
    const elements = {
        nama: document.getElementById('dosenNamaProfile'),
        nik: document.getElementById('dosenNikProfile'),
        email: document.getElementById('dosenEmailProfile')
    };
    
    if (elements.nama) elements.nama.textContent = loginStatus.nama || 'Dosen';
    if (elements.nik) elements.nik.textContent = `NIK: ${loginStatus.nik || 'N/A'}`;
    if (elements.email) elements.email.textContent = loginStatus.email || 'email@stie-mce.ac.id';
}

function updateBookingDisplay(date, roomId) {
    // Format date
    const dateObj = new Date(date);
    const formattedDate = dateObj.toLocaleDateString('id-ID', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
    
    const displayDate = document.getElementById('displayDate');
    if (displayDate) displayDate.textContent = formattedDate;
    
    // Get room info
    fetch(`get_room_info.php?id=${roomId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const displayRoom = document.getElementById('displayRoom');
                const displayCapacity = document.getElementById('displayCapacity');
                const roomTimeDisplay = document.getElementById('roomTimeDisplay');
                
                if (displayRoom) displayRoom.textContent = data.room.nama_ruang;
                if (displayCapacity) displayCapacity.textContent = `${data.room.kapasitas} orang`;
                if (roomTimeDisplay) roomTimeDisplay.textContent = `${data.room.nama_ruang} • ${formattedDate}`;
            }
        })
        .catch(error => console.error('Error fetching room info:', error));
}

function fillSampleData() {
    const elements = {
        mata_kuliah: document.getElementById('mata_kuliah'),
        kelas: document.getElementById('kelas'),
        semester: document.getElementById('semester'),
        catatan_tambahan: document.getElementById('catatan_tambahan')
    };
    
    if (elements.mata_kuliah) elements.mata_kuliah.value = 'Fundamental Accounting 1';
    if (elements.kelas) elements.kelas.value = 'A';
    if (elements.semester) elements.semester.value = '3';
    if (elements.catatan_tambahan) {
        elements.catatan_tambahan.value = 'Pergantian kelas hari selasa tgl ... karena ada keperluan di tanggal tsb';
    }
    
    showAlert('✅ Data contoh telah diisi', 'success');
}

function clearForm() {
    const form = document.getElementById('dosenBookingForm');
    if (!form) return;
    
    form.querySelectorAll('input[type="text"], textarea').forEach(input => {
        input.value = '';
    });
    
    form.querySelectorAll('select').forEach(select => {
        select.selectedIndex = 0;
    });
    
    setupSmartFeatures(); // Re-initialize defaults
    showAlert('🧹 Form telah dibersihkan', 'info');
}

function showBookingForm(date, time, roomId) {
    // Set hidden fields
    document.getElementById('booking_date').value = date;
    document.getElementById('room_id').value = roomId;
    
    // Set time fields
    const [hours, minutes] = time.split(':');
    const endHours = parseInt(hours) + 1;
    const endTime = endHours.toString().padStart(2, '0') + ':' + minutes;
    
    document.getElementById('jam_mulai').value = time;
    document.getElementById('jam_selesai').value = endTime;
    
    // Reset form messages
    const errorDiv = document.getElementById('bookingError');
    const successDiv = document.getElementById('bookingSuccess');
    if (errorDiv) errorDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');
    
    // Show modal
    if (typeof bookingModal !== 'undefined' && bookingModal) {
        bookingModal.show();
    } else {
        const modal = new bootstrap.Modal(document.getElementById('bookingModal'));
        modal.show();
    }
}

// Fix untuk filter lokasi dan ruangan - tambahkan sebelum </body> di index.php
function filterRooms(buildingId) {
    const currentUrl = new URL(window.location);
    if (buildingId) {
        currentUrl.searchParams.set('building_id', buildingId);
    } else {
        currentUrl.searchParams.delete('building_id');
    }
    // Reset room selection when building changes
    currentUrl.searchParams.delete('room_id');
    window.location.href = currentUrl.toString();
}

function selectRoom(roomId) {
    if (roomId) {
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('room_id', roomId);
        window.location.href = currentUrl.toString();
    }
}

function selectDate(date) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('date', date);
    window.location.href = currentUrl.toString();
}

function changeView(view) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('view', view);
    window.location.href = currentUrl.toString();
}

function prevDay() {
    const currentDate = new Date('<?= $selectedDate ?>');
    currentDate.setDate(currentDate.getDate() - 1);
    const newDate = currentDate.toISOString().split('T')[0];
    selectDate(newDate);
}

function nextDay() {
    const currentDate = new Date('<?= $selectedDate ?>');
    currentDate.setDate(currentDate.getDate() + 1);
    const newDate = currentDate.toISOString().split('T')[0];
    selectDate(newDate);
}

function prevMonth() {
    const currentUrl = new URL(window.location);
    const currentMonth = <?= $month ?>;
    const currentYear = <?= $year ?>;
    
    let newMonth = currentMonth - 1;
    let newYear = currentYear;
    
    if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    }
    
    currentUrl.searchParams.set('month', newMonth);
    currentUrl.searchParams.set('year', newYear);
    window.location.href = currentUrl.toString();
}

function nextMonth() {
    const currentUrl = new URL(window.location);
    const currentMonth = <?= $month ?>;
    const currentYear = <?= $year ?>;
    
    let newMonth = currentMonth + 1;
    let newYear = currentYear;
    
    if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    }
    
    currentUrl.searchParams.set('month', newMonth);
    currentUrl.searchParams.set('year', newYear);
    window.location.href = currentUrl.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    // Update booking range validation
    const todayDate = '<?= $todayDate ?>';
    const maxDate = '<?= $maxDate ?>';
    
    // Update mini calendar
    const calendarDays = document.querySelectorAll('.mini-calendar-day');
    calendarDays.forEach(day => {
        const dateStr = day.dataset.date;
        if (dateStr && (dateStr < todayDate)) {
            // Hanya disable tanggal yang sudah lewat
            day.classList.add('out-of-range');
            day.style.pointerEvents = 'none';
            day.title = 'Tanggal sudah berlalu';
        }
    });
    
    // Update booking form date input
    const dateInput = document.getElementById('booking_date');
    if (dateInput) {
        dateInput.min = todayDate;
        dateInput.max = maxDate; // 1 tahun ke depan
    }
});

// Update validation function
function validateBookingDates() {
    const today = new Date();
    const maxDate = new Date();
    maxDate.setFullYear(today.getFullYear() + 1); // 1 tahun ke depan
    
    const todayStr = today.toISOString().split('T')[0];
    const maxDateStr = maxDate.toISOString().split('T')[0];
    
    return {
        today: todayStr,
        maxDate: maxDateStr,
        isValidDate: function(dateStr) {
            return dateStr >= todayStr && dateStr <= maxDateStr;
        }
    };
}

// FIXED NIK LOGIN HANDLER - Replace in index.php
function handleNikLogin(e) {
    // Prevent any form submission
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    console.log('🔑 Starting NIK login process...');
    
    const form = document.getElementById('nikLoginForm');
    if (!form) {
        console.error('❌ NIK login form not found');
        return false;
    }
    
    const formData = new FormData(form);
    const submitBtn = document.getElementById('nikLoginBtn');
    
    // Validate required fields
    const nik = formData.get('nik');
    const password = formData.get('password');
    
    if (!nik || !password) {
        showNikAlert('❌ NIK dan password harus diisi!', 'danger');
        return false;
    }
    
    // Validate NIK format
    if (!validateNIKFormat(nik)) {
        showNikAlert('❌ Format NIK tidak valid!', 'danger');
        return false;
    }
    
    // Show loading state
    const originalText = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memverifikasi NIK...';
    }
    
    console.log('🌐 Sending AJAX NIK login request...');
    
    // Send AJAX request
    fetch('process-login-nik.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log('📡 Response received:', {
            status: response.status,
            statusText: response.statusText,
            headers: response.headers
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const contentType = response.headers.get('content-type') || '';
        console.log('Content-Type:', contentType);
        
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('❌ Non-JSON response:', text.substring(0, 500));
                throw new Error('Server mengembalikan response non-JSON. Periksa error server.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ NIK Login response:', data);
        
        if (data.success) {
            console.log('🎉 NIK Login successful!');
            
            // Show success message
            showNikAlert('🎉 Login berhasil! Selamat datang, ' + data.user.nama + '!', 'success');
            
            // Update UI state
            document.body.classList.add('logged-in');
            
            // Store user data globally
            if (data.user) {
                document.body.dataset.userId = data.user.id;
                document.body.dataset.userRole = data.user.role;
                window.userId = data.user.id;
                window.userRole = data.user.role;
                window.userNIK = data.user.nik;
                window.userName = data.user.nama;
                
                console.log('User data stored:', {
                    id: data.user.id,
                    role: data.user.role,
                    nik: data.user.nik,
                    name: data.user.nama
                });
            }
            
            // Hide modal
            const loginModalElement = document.getElementById('loginModal');
            if (loginModalElement) {
                const modal = bootstrap.Modal.getInstance(loginModalElement);
                if (modal) {
                    modal.hide();
                }
            }
            
            // Show redirect message
            showNikAlert('✅ Login berhasil! Mengalihkan ke halaman utama...', 'success');
            
            // Redirect after delay
            setTimeout(() => {
                console.log('🔄 Redirecting to:', data.redirect || 'index.php');
                
                if (data.redirect && data.redirect !== '' && data.redirect !== 'index.php') {
                    // Redirect to specific page
                    window.location.href = data.redirect;
                } else {
                    // Reload current page (index.php)
                    window.location.reload();
                }
            }, 1500); // 1.5 second delay
            
        } else {
            console.error('❌ NIK Login failed:', data.message);
            showNikAlert('❌ ' + data.message, 'danger');
        }
    })
    .catch(error => {
        console.error('💥 NIK Login error:', error);
        showNikAlert('💥 Terjadi kesalahan: ' + error.message, 'danger');
    })
    .finally(() => {
        // Reset button state
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
        console.log('🏁 NIK Login process completed');
    });
    
    return false;
}

function validateNIKFormat(nik) {
    // Allow NIK with dots: 203.111.111 or without: 203111111
    return /^[\d\.]{8,20}$/.test(nik) && 
           /\d/.test(nik) && 
           !nik.startsWith('.') && 
           !nik.endsWith('.') && 
           !/\.{2,}/.test(nik);
}

function showNikAlert(message, type = 'info') {
    console.log('Alert:', type, message);
    
    // Remove existing alerts
    const errorDiv = document.getElementById('nikLoginError');
    const successDiv = document.getElementById('nikLoginSuccess');
    
    if (errorDiv) errorDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');
    
    // Show new alert
    const alertDiv = type === 'danger' ? errorDiv : successDiv;
    
    if (alertDiv) {
        alertDiv.innerHTML = message;
        alertDiv.classList.remove('d-none');
        
        // Auto-hide after 5 seconds for error messages
        if (type === 'danger') {
            setTimeout(() => {
                alertDiv.classList.add('d-none');
            }, 5000);
        }
    }
}

// ENSURE PROPER EVENT HANDLING
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔧 Setting up NIK login handlers...');
    
    const nikForm = document.getElementById('nikLoginForm');
    const nikBtn = document.getElementById('nikLoginBtn');
    
    if (nikForm) {
        // Prevent normal form submission
        nikForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Form submit prevented');
            return false;
        });
        
        // Set form onsubmit to prevent submission
        nikForm.onsubmit = function(e) {
            e.preventDefault();
            return false;
        };
        
        console.log('✅ NIK form submit prevention set');
    }
    
    if (nikBtn) {
        // Ensure button click handler
        nikBtn.onclick = function(e) {
            e.preventDefault();
            handleNikLogin(e);
            return false;
        };
        
        console.log('✅ NIK button click handler set');
    }
    
    // Also prevent any enter key submission
    const nikInput = document.getElementById('nik_input');
    const passwordInput = document.getElementById('nik_password');
    
    if (nikInput) {
        nikInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleNikLogin(e);
                return false;
            }
        });
    }
    
    if (passwordInput) {
        passwordInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleNikLogin(e);
                return false;
            }
        });
    }
    
    console.log('✅ NIK login system initialized');
});

// Password toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    const toggleNikPassword = document.getElementById('toggleNikPassword');
    const nikPasswordInput = document.getElementById('nik_password');
    const toggleNikIcon = document.getElementById('toggleNikPasswordIcon');
    
    if (toggleNikPassword && nikPasswordInput && toggleNikIcon) {
        toggleNikPassword.addEventListener('click', function() {
            const type = nikPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            nikPasswordInput.setAttribute('type', type);
            
            if (type === 'password') {
                toggleNikIcon.classList.remove('fa-eye-slash');
                toggleNikIcon.classList.add('fa-eye');
            } else {
                toggleNikIcon.classList.remove('fa-eye');
                toggleNikIcon.classList.add('fa-eye-slash');
            }
        });
    }
});


document.addEventListener('DOMContentLoaded', function() {
    
    // Debug: Check initial state
    console.log('🔍 DEBUG: DOM loaded, checking login form...');
    
    const loginForm = document.getElementById('loginForm');
    const loginModal = document.getElementById('loginModal');
    
    if (loginForm) {
        console.log('✅ Login form found');
        
        // PERBAIKAN: Prevent all types of form submission
        loginForm.onsubmit = function(e) {
            console.log('🚫 Form submit intercepted');
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        };
        
        // Override form action to prevent accidental submission
        loginForm.action = 'javascript:void(0)';
        
        // Add multiple event listeners for safety
        ['submit', 'onsubmit'].forEach(eventType => {
            loginForm.addEventListener(eventType, function(e) {
                console.log(`🚫 ${eventType} event intercepted`);
                e.preventDefault();
                e.stopImmediatePropagation();
                handleLogin(e);
                return false;
            }, { capture: true, passive: false });
        });
        
        // Handle submit button specifically
        const submitBtn = document.getElementById('loginSubmitBtn');
        if (submitBtn) {
            console.log('✅ Submit button found');
            
            submitBtn.addEventListener('click', function(e) {
                console.log('🎯 Submit button clicked');
                e.preventDefault();
                e.stopImmediatePropagation();
                
                // Validate form first
                if (loginForm.checkValidity()) {
                    console.log('✅ Form is valid, processing login...');
                    handleLogin(e);
                } else {
                    console.log('❌ Form validation failed');
                    loginForm.reportValidity();
                }
                return false;
            }, { capture: true, passive: false });
        }
        
        // Debug: Monitor form changes
        loginForm.addEventListener('change', function(e) {
            console.log('📝 Form field changed:', e.target.name, '=', e.target.value);
        });
        
    } else {
        console.log('❌ Login form not found!');
    }
    
    // Debug: Monitor modal events
    if (loginModal) {
        loginModal.addEventListener('shown.bs.modal', function() {
            console.log('👁️ Login modal opened');
            // Focus on first input
            const firstInput = loginModal.querySelector('input[type="email"]');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        });
        
        loginModal.addEventListener('hidden.bs.modal', function() {
            console.log('🙈 Login modal closed');
            // Clear form when modal closes
            if (loginForm) {
                loginForm.reset();
                const errorDiv = document.getElementById('loginError');
                if (errorDiv) {
                    errorDiv.classList.add('d-none');
                }
            }
        });
    }
    
    // PERBAIKAN: Enhanced login handler dengan logging yang lebih detail
    window.handleLogin = function(e) {
        if (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
        
        console.log('🚀 Starting login process...');
        
        const form = document.getElementById('loginForm');
        if (!form) {
            console.error('❌ Login form not found');
            return false;
        }
        
        const formData = new FormData(form);
        const errorDiv = document.getElementById('loginError');
        const submitBtn = document.getElementById('loginSubmitBtn');
        
        // Debug: Log all form data
        console.log('📋 Form data:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: ${value}`);
        }
        
        // Validate required fields
        const email = formData.get('email');
        const password = formData.get('password');
        const role = formData.get('login_role');
        
        if (!email || !password || !role) {
            const missingFields = [];
            if (!email) missingFields.push('email');
            if (!password) missingFields.push('password');
            if (!role) missingFields.push('role');
            
            const errorMsg = 'Field yang harus diisi: ' + missingFields.join(', ');
            console.error('❌ Validation failed:', errorMsg);
            showLoginError(errorMsg);
            return false;
        }
        
        // Reset error state
        if (errorDiv) {
            errorDiv.classList.add('d-none');
        }
        
        // Show loading state
        const originalText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Logging in...';
        }
        
        console.log('🌐 Sending login request...');
        
        // Send AJAX request
        fetch('process-login.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => {
            console.log('📡 Response received:', {
                status: response.status,
                statusText: response.statusText,
                headers: Object.fromEntries(response.headers.entries())
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const contentType = response.headers.get('content-type') || '';
            console.log('📋 Content-Type:', contentType);
            
            if (!contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('❌ Non-JSON response:', text.substring(0, 500));
                    throw new Error('Server returned non-JSON response');
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('✅ Login response:', data);
            
            if (data.success) {
                console.log('🎉 Login successful!');
                
                // Hide modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('loginModal'));
                if (modal) {
                    modal.hide();
                }
                
                // Update UI
                document.body.classList.add('logged-in');
                
                if (data.user) {
                    document.body.dataset.userId = data.user.id;
                    document.body.dataset.userRole = data.user.role;
                    window.userId = data.user.id;
                    window.userRole = data.user.role;
                    console.log('👤 User data stored:', data.user);
                }
                
                // Show success message
                showAlert(`✅ Login berhasil! Selamat datang, ${data.user.email}`, 'success');
                
                // Handle post-login actions
                handlePostLoginRedirect(data);
                
            } else {
                console.error('❌ Login failed:', data.message);
                showLoginError(data.message || 'Login gagal. Silakan coba lagi.');
            }
        })
        .catch(error => {
            console.error('💥 Login error:', error);
            showLoginError('Terjadi kesalahan: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
            console.log('🏁 Login process completed');
        });
        
        return false;
    };
    
    // Helper functions
    window.showLoginError = function(message) {
        const errorDiv = document.getElementById('loginError');
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.classList.remove('d-none');
        }
        console.error('Login error:', message);
    };
    
    window.handlePostLoginRedirect = function(data) {
        // Check for pending booking
        const pendingBooking = sessionStorage.getItem('pendingBooking');
        
        if (pendingBooking) {
            console.log('📋 Found pending booking, showing booking form...');
            try {
                const bookingData = JSON.parse(pendingBooking);
                setTimeout(() => {
                    showBookingForm(bookingData.date, bookingData.time, bookingData.roomId);
                    sessionStorage.removeItem('pendingBooking');
                }, 1000);
                return;
            } catch (e) {
                console.error('Error parsing pending booking:', e);
                sessionStorage.removeItem('pendingBooking');
            }
        }
        
        // Handle redirect
        if (data.redirect && data.redirect !== window.location.pathname) {
            console.log('🔄 Redirecting to:', data.redirect);
            setTimeout(() => {
                window.location.href = data.redirect;
            }, 1500);
        } else {
            console.log('🔄 Reloading current page...');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }
    };
    
    console.log('🔧 Login debugging script loaded successfully');
});

function showAcademicDetail(bookingId) {
    fetch(`get_academic_detail.php?id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const booking = data.booking;
                
                document.getElementById('academicDetailBody').innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Informasi Mata Kuliah</h6>
                            <table class="table table-borderless table-sm">
                                <tr><th>Mata Kuliah:</th><td>${booking.nama_matakuliah || 'Unknown'}</td></tr>
                                <tr><th>Kelas:</th><td>${booking.kelas || 'Unknown'}</td></tr>
                                <tr><th>Semester:</th><td>${booking.semester || ''} ${booking.tahun_akademik || ''}</td></tr>
                                <tr><th>Dosen:</th><td>${booking.dosen_pengampu || 'Unknown'}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Informasi Jadwal</h6>
                            <table class="table table-borderless table-sm">
                                <tr><th>Ruangan:</th><td>${booking.nama_ruang || 'Unknown'}</td></tr>
                                <tr><th>Gedung:</th><td>${booking.nama_gedung || 'Unknown'}</td></tr>
                                <tr><th>Hari:</th><td>${booking.hari_indo || 'Unknown'}</td></tr>
                                <tr><th>Waktu:</th><td>${booking.jam_mulai || 'Unknown'} - ${booking.jam_selesai || 'Unknown'}</td></tr>
                            </table>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Catatan:</strong> Ini adalah jadwal perkuliahan rutin yang akan berulang setiap minggu. 
                        Pada hari libur, slot ini akan kosong dan tersedia untuk booking lain.
                    </div>
                `;
                
                const academicModal = new bootstrap.Modal(document.getElementById('academicDetailModal'));
                academicModal.show();
            } else {
                alert('Gagal memuat detail jadwal perkuliahan');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat memuat detail');
        });
}

// Auto-refresh untuk update real-time
setInterval(function() {
    // Check if there are any active bookings that might need status updates
    const activeElements = document.querySelectorAll('.table-danger .booking-info');
    if (activeElements.length > 0) {
        console.log('Checking for status updates...');
        // You can add auto-refresh logic here if needed
    }
}, 30000); // Check every 30 seconds

// Global error handler for debugging
window.addEventListener('error', function(e) {
    console.error('💥 Global error:', e.error);
});

// Prevent any accidental form submissions globally
document.addEventListener('submit', function(e) {
    if (e.target.id === 'loginForm') {
        console.log('🚫 Global submit prevention for login form');
        e.preventDefault();
        e.stopImmediatePropagation();
        return false;
    }
}, { capture: true });
</script>
<!-- Academic Schedule Detail & Edit Modal -->
<div class="modal fade" id="academicScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-academic text-white">
                <h5 class="modal-title">
                    <i class="fas fa-graduation-cap me-2"></i>
                    <span id="academicModalTitle">Detail Jadwal Perkuliahan</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="academicModalBody">
                <!-- Content will be loaded dynamically -->
                <div class="d-flex justify-content-center align-items-center py-5">
                    <div class="spinner-border text-primary me-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <span>Memuat detail jadwal...</span>
                </div>
            </div>
            <div class="modal-footer bg-light" id="academicModalFooter">
                <!-- Footer buttons will be loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<!-- Quick Edit Modal for Academic Schedule -->
<div class="modal fade" id="quickEditAcademicModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Cepat Jadwal Perkuliahan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickEditAcademicForm">
                    <input type="hidden" id="edit_schedule_id" name="schedule_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Mata Kuliah</label>
                                <input type="text" class="form-control" id="edit_mata_kuliah" name="nama_matakuliah" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Kelas</label>
                                <input type="text" class="form-control" id="edit_kelas" name="kelas" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Dosen Pengampu</label>
                                <input type="text" class="form-control" id="edit_dosen" name="dosen_pengampu" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Ruangan</label>
                                <select class="form-select" id="edit_ruangan" name="id_ruang" required>
                                    <!-- Options will be loaded dynamically -->
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Hari</label>
                                <select class="form-select" id="edit_hari" name="hari" required>
                                    <option value="monday">Senin</option>
                                    <option value="tuesday">Selasa</option>
                                    <option value="wednesday">Rabu</option>
                                    <option value="thursday">Kamis</option>
                                    <option value="friday">Jumat</option>
                                    <option value="saturday">Sabtu</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Jam Mulai</label>
                                <input type="time" class="form-control" id="edit_jam_mulai" name="jam_mulai" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Jam Selesai</label>
                                <input type="time" class="form-control" id="edit_jam_selesai" name="jam_selesai" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Semester</label>
                                <select class="form-select" id="edit_semester" name="semester" required>
                                    <option value="Ganjil">Ganjil</option>
                                    <option value="Genap">Genap</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tahun Akademik</label>
                                <input type="text" class="form-control" id="edit_tahun_akademik" name="tahun_akademik" 
                                       placeholder="2024/2025" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tanggal Mulai</label>
                                <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tanggal Selesai</label>
                                <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Perhatian:</strong> Perubahan jadwal akan mempengaruhi semua booking masa depan yang terkait dengan jadwal ini.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Batal
                </button>
                <button type="button" class="btn btn-warning" onclick="saveQuickEdit()">
                    <i class="fas fa-save me-2"></i>Simpan Perubahan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Edit Modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-tasks me-2"></i>Edit Massal Jadwal
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Pilih jadwal yang ingin diedit secara bersamaan:
                </p>
                
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th width="5%">
                                    <input type="checkbox" id="selectAllSchedules" onchange="toggleAllSchedules()">
                                </th>
                                <th>Mata Kuliah</th>
                                <th>Kelas</th>
                                <th>Dosen</th>
                                <th>Hari & Waktu</th>
                                <th>Ruangan</th>
                            </tr>
                        </thead>
                        <tbody id="bulkEditTableBody">
                            <!-- Will be populated dynamically -->
                        </tbody>
                    </table>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6>Field yang akan diubah:</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bulk_change_dosen">
                            <label class="form-check-label" for="bulk_change_dosen">Dosen Pengampu</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bulk_change_ruangan">
                            <label class="form-check-label" for="bulk_change_ruangan">Ruangan</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bulk_change_semester">
                            <label class="form-check-label" for="bulk_change_semester">Semester</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Dosen Baru</label>
                            <input type="text" class="form-control" id="bulk_new_dosen" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ruangan Baru</label>
                            <select class="form-select" id="bulk_new_ruangan" disabled>
                                <!-- Options will be loaded -->
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Semester Baru</label>
                            <select class="form-select" id="bulk_new_semester" disabled>
                                <option value="Ganjil">Ganjil</option>
                                <option value="Genap">Genap</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Batal
                </button>
                <button type="button" class="btn btn-info" onclick="applyBulkEdit()">
                    <i class="fas fa-check me-2"></i>Terapkan Perubahan
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>