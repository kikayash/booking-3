<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is CS
if (!isLoggedIn() || !isCS()) {
    header('Location: ../index.php?access_error=Akses ditolak - Anda bukan CS');
    exit;
}

// Get current user info
$currentUser = [
    'id' => $_SESSION['user_id'],
    'email' => $_SESSION['email'],
    'role' => $_SESSION['role']
];

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20; // Records per page
$offset = ($page - 1) * $limit;

// Get status filter and search
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for CS - can view all bookings but limited actions
$sql = "SELECT b.*, u.email, u.role as user_role, r.nama_ruang, r.kapasitas, g.nama_gedung,
               b.checkout_status, b.checkout_time, b.checked_out_by, b.completion_note,
               b.cancelled_by, b.cancellation_reason, b.approved_at, b.approved_by,
               rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu, rs.hari,
               b.booking_type, b.is_external, b.auto_generated
        FROM tbl_booking b 
        JOIN tbl_users u ON b.id_user = u.id_user 
        JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule";

$countSql = "SELECT COUNT(*) as total FROM tbl_booking b 
             JOIN tbl_users u ON b.id_user = u.id_user 
             JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
             LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
             LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule";

$params = [];
$whereConditions = [];

// Add status filter
if ($statusFilter !== 'all') {
    $whereConditions[] = "b.status = ?";
    $params[] = $statusFilter;
}

// Add search filter
if (!empty($search)) {
    $whereConditions[] = "(b.nama_acara LIKE ? OR b.nama_penanggungjawab LIKE ? OR b.keterangan LIKE ? OR u.email LIKE ? OR r.nama_ruang LIKE ? OR g.nama_gedung LIKE ? OR rs.nama_matakuliah LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Combine WHERE conditions
if (!empty($whereConditions)) {
    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
    $sql .= $whereClause;
    $countSql .= $whereClause;
}

// Get total count for pagination
try {
    $stmt = $conn->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = $stmt->fetch()['total'];
} catch (PDOException $e) {
    error_log("Error in count query: " . $e->getMessage());
    $totalRecords = 0;
}

$totalPages = ceil($totalRecords / $limit);

// Add ordering and pagination
$sql .= " ORDER BY 
            CASE WHEN b.status = 'pending' AND u.role = 'dosen' THEN 1 ELSE 2 END,
            b.created_at DESC, b.tanggal DESC, b.jam_mulai DESC 
          LIMIT $limit OFFSET $offset";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error in CS dashboard query: " . $e->getMessage());
    $bookings = [];
    $search_error = "Terjadi kesalahan dalam pencarian. Silakan coba lagi.";
}

// Get today's room usage
$todayDate = date('Y-m-d');
$todayUsageQuery = "SELECT b.*, r.nama_ruang, g.nama_gedung, u.email,
                           rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu
                    FROM tbl_booking b
                    JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                    LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                    JOIN tbl_users u ON b.id_user = u.id_user
                    LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
                    WHERE b.tanggal = ? AND b.status IN ('approve', 'active')
                    ORDER BY b.jam_mulai ASC";

try {
    $stmt = $conn->prepare($todayUsageQuery);
    $stmt->execute([$todayDate]);
    $todayUsage = $stmt->fetchAll();
} catch (PDOException $e) {
    $todayUsage = [];
}

// Get pending requests from lecturers (schedule changes)
$pendingDosenQuery = "SELECT b.*, u.email, r.nama_ruang, g.nama_gedung, rs.nama_matakuliah, rs.kelas
                      FROM tbl_booking b
                      JOIN tbl_users u ON b.id_user = u.id_user
                      JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                      LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                      LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
                      WHERE b.status = 'pending' AND u.role = 'dosen'
                      AND b.tanggal >= CURDATE()
                      ORDER BY b.created_at ASC";

try {
    $stmt = $conn->prepare($pendingDosenQuery);
    $stmt->execute();
    $pendingDosenRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    $pendingDosenRequests = [];
}

// Statistics for CS dashboard
$stats = [
    'total_bookings' => $totalRecords,
    'today_usage' => count($todayUsage),
    'pending_bookings' => count(array_filter($bookings, function($b) { return $b['status'] === 'pending'; })),
    'pending_dosen' => count($pendingDosenRequests),
    'mahasiswa_bookings' => count(array_filter($bookings, function($b) { return $b['user_role'] === 'mahasiswa'; }))
];

// Handle messages
$message = '';
$alertType = '';

if (isset($_GET['login_success'])) {
    $message = 'Login berhasil! Selamat datang di Dashboard CS.';
    $alertType = 'success';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard CS - STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <!-- FontAwesome - Load multiple sources untuk memastikan icon muncul -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Fallback untuk FontAwesome -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">

    <style>
        .cs-theme {
            --primary-color: #e91e63;
            --secondary-color: #f8bbd9;
            --accent-color: #ad1457;
        }
        
        .bg-cs-primary {
            background: linear-gradient(135deg, #e91e63, #ad1457) !important;
        }
        
        .priority-request {
            border-left: 4px solid #ff9800;
            background: rgba(255, 152, 0, 0.1);
        }
        
        .schedule-change-badge {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
        }
        
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .page-info {
            background: #f8f9fa;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            color: #6c757d;
        }

        /* Fix untuk icon di sidebar */
        .list-group-item i {
            display: inline-block !important;
            width: 20px;
            text-align: center;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .sidebar-cs .list-group-item {
            padding: 12px 20px;
            border: none;
            color: #333;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .sidebar-cs .list-group-item:hover {
            background-color: #f8f9fa;
            color: #e91e63;
            padding-left: 25px;
        }
        
        .sidebar-cs .list-group-item.active {
            background-color: #e91e63;
            color: white;
            border-left: 4px solid #ad1457;
        }
        
        .notification-badge {
            background: #dc3545;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        /* Stat cards icons */
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .cs-stat-icon-primary {
            background: linear-gradient(135deg, #e91e63, #ad1457);
            color: white;
        }
        
        .cs-stat-icon-warning {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            position: relative;
        }
        
        .cs-stat-icon-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .cs-stat-icon-info {
            background: linear-gradient(135deg, #17a2b8, #007bff);
            color: white;
        }
        
        .blink-badge {
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.5; }
        }
    </style>
</head>
<body class="cs-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?= $alertType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Debug info - hapus setelah testing -->
        <?php if (isset($search_error)): ?>
            <div class="alert alert-danger">
                <?= $search_error ?>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- CS Sidebar dengan Icon yang Fixed -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow dashboard-sidebar sidebar-cs">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-headset me-2"></i>Menu CS
                        </h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action active position-relative">
                            <i class="fas fa-tachometer-alt"></i>Dashboard CS
                            <?php if ($stats['pending_dosen'] > 0): ?>
                                <span class="notification-badge"><?= $stats['pending_dosen'] ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="add-booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle"></i>Tambah Booking Manual
                        </a>
                        <a href="today_rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-day"></i>Ruangan Hari Ini
                        </a>
                        <a href="../index.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar"></i>Kalender Booking
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <!-- Statistics Cards dengan Icon -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="stat-icon mx-auto cs-stat-icon-primary">
                                    <i class="fas fa-list"></i>
                                </div>
                                <h4 class="mb-1"><?= $stats['total_bookings'] ?></h4>
                                <p class="text-muted mb-0">Total Peminjaman</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="stat-icon mx-auto cs-stat-icon-warning position-relative">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <?php if ($stats['pending_dosen'] > 0): ?>
                                        <span class="notification-badge position-absolute top-0 end-0"><?= $stats['pending_dosen'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="mb-1"><?= $stats['pending_dosen'] ?></h4>
                                <p class="text-muted mb-0">Perubahan Jadwal Dosen</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="stat-icon mx-auto cs-stat-icon-success">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <h4 class="mb-1"><?= $stats['today_usage'] ?></h4>
                                <p class="text-muted mb-0">Ruangan Terpakai Hari Ini</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="stat-icon mx-auto cs-stat-icon-info">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <h4 class="mb-1"><?= $stats['mahasiswa_bookings'] ?></h4>
                                <p class="text-muted mb-0">Peminjaman Mahasiswa</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Priority: Pending Dosen Requests -->
                <?php if (count($pendingDosenRequests) > 0): ?>
                <div class="card shadow mb-4 priority-request">
                    <div class="card-header" style="background: linear-gradient(135deg, #ff9800, #f57c00); color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Perubahan Jadwal Dosen - Perlu Persetujuan
                            <span class="schedule-change-badge ms-2"><?= count($pendingDosenRequests) ?> pending</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Prioritas Tinggi:</strong> Dosen meminta perubahan jadwal perkuliahan. 
                            CS dapat menyetujui dan menghapus jadwal asli jika diperlukan.
                        </div>
                        
                        <?php foreach ($pendingDosenRequests as $request): ?>
                            <div class="card mb-3 border-warning">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <h6 class="text-warning">
                                                <i class="fas fa-clock me-1"></i>
                                                <?= htmlspecialchars($request['nama_acara']) ?>
                                                <?php if ($request['nama_matakuliah']): ?>
                                                    <span class="badge bg-info ms-2"><?= htmlspecialchars($request['nama_matakuliah']) ?> - <?= htmlspecialchars($request['kelas']) ?></span>
                                                <?php endif; ?>
                                            </h6>
                                            <p class="mb-2">
                                                <i class="fas fa-user me-1"></i><strong>Dosen:</strong> <?= htmlspecialchars($request['email']) ?><br>
                                                <i class="fas fa-door-open me-1"></i><strong>Ruangan:</strong> <?= htmlspecialchars($request['nama_ruang']) ?><br>
                                                <i class="fas fa-calendar me-1"></i><strong>Jadwal Baru:</strong> <?= formatDate($request['tanggal']) ?>, <?= formatTime($request['jam_mulai']) ?> - <?= formatTime($request['jam_selesai']) ?><br>
                                                <i class="fas fa-user-tie me-1"></i><strong>PIC:</strong> <?= htmlspecialchars($request['nama_penanggungjawab']) ?> (<?= htmlspecialchars($request['no_penanggungjawab']) ?>)
                                            </p>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>Diminta: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                            </small>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="btn-group-vertical d-grid gap-2">
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="approveScheduleChange(<?= $request['id_booking'] ?>)">
                                                    <i class="fas fa-check me-1"></i>Setujui
                                                </button>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="rejectScheduleChange(<?= $request['id_booking'] ?>)">
                                                    <i class="fas fa-times me-1"></i>Tolak
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Main Dashboard -->
                <div class="card shadow">
                    <div class="card-header bg-cs-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Dashboard CS - Monitor Peminjaman Ruangan
                        </h4>
                        <div>
                            <button class="btn btn-sm btn-outline-light" onclick="showTodayUsage()">
                                <i class="fas fa-calendar-day me-1"></i>Ruangan Hari Ini (<?= count($todayUsage) ?>)
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- CS Notice -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>Peran CS dalam Sistem Booking</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><i class="fas fa-eye me-1"></i><strong>Monitoring:</strong> Melihat semua peminjaman ruangan</li>
                                        <li><i class="fas fa-calendar-alt me-1"></i><strong>Perubahan Jadwal:</strong> Menyetujui perubahan jadwal dari dosen</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><i class="fas fa-plus me-1"></i><strong>Booking Manual:</strong> Menambah booking untuk eksternal/add-on</li>
                                        <li><i class="fas fa-headset me-1"></i><strong>Support:</strong> Membantu user dengan pertanyaan terkait booking</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Search and Filter -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <form method="GET" class="d-flex gap-2">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                                    <input type="hidden" name="page" value="1">
                                    
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" 
                                               class="form-control" 
                                               name="search" 
                                               value="<?= htmlspecialchars($search) ?>" 
                                               placeholder="Cari acara, dosen, email, ruangan, mata kuliah...">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-search me-1"></i>Cari
                                        </button>
                                        
                                        <?php if (!empty($search)): ?>
                                            <a href="?status=<?= $statusFilter ?>&page=<?= $page ?>" class="btn btn-outline-secondary">
                                                <i class="fas fa-times me-1"></i>Reset
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            <div class="col-md-4">
                                <div class="btn-group w-100" role="group">
                                    <a href="?search=<?= urlencode($search) ?>&status=all&page=1" 
                                       class="btn btn-sm btn-<?= $statusFilter === 'all' ? 'primary' : 'outline-primary' ?>">
                                        <i class="fas fa-list me-1"></i>Semua
                                    </a>
                                    <a href="?search=<?= urlencode($search) ?>&status=pending&page=1" 
                                       class="btn btn-sm btn-<?= $statusFilter === 'pending' ? 'warning' : 'outline-warning' ?>">
                                        <i class="fas fa-clock me-1"></i>Pending
                                    </a>
                                    <a href="?search=<?= urlencode($search) ?>&status=approve&page=1" 
                                       class="btn btn-sm btn-<?= $statusFilter === 'approve' ? 'success' : 'outline-success' ?>">
                                        <i class="fas fa-check me-1"></i>Disetujui
                                    </a>
                                    <a href="?search=<?= urlencode($search) ?>&status=active&page=1" 
                                       class="btn btn-sm btn-<?= $statusFilter === 'active' ? 'danger' : 'outline-danger' ?>">
                                        <i class="fas fa-play me-1"></i>Aktif
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Bookings Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="5%"><i class="fas fa-hashtag"></i></th>
                                        <th width="20%">Nama Acara & PIC</th>
                                        <th width="15%">Ruangan</th>
                                        <th width="15%">Tanggal & Waktu</th>
                                        <th width="15%">Peminjam</th>
                                        <th width="15%"></i>Status</th>
                                        <th width="15%"></i>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($bookings) > 0): ?>
                                        <?php $no = $offset + 1; foreach ($bookings as $booking): ?>
                                            <tr class="<?= $booking['status'] === 'active' ? 'table-danger' : '' ?> 
                                                        <?= $booking['status'] === 'pending' && $booking['user_role'] === 'dosen' ? 'priority-request' : '' ?>">
                                                <td class="text-center"><strong><?= $no++ ?></strong></td>
                                                <td>
                                                    <strong class="text-primary">
                                                        <i class="fas fa-calendar-check me-1"></i>
                                                        <?= htmlspecialchars($booking['nama_acara'] ?? '') ?>
                                                    </strong>
                                                    
                                                    <?php if (!empty($booking['nama_matakuliah'])): ?>
                                                        <br><span class="badge bg-info">
                                                            <i class="fas fa-book me-1"></i>
                                                            <?= htmlspecialchars($booking['nama_matakuliah']) ?> - <?= htmlspecialchars($booking['kelas']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($booking['booking_type'] === 'external' || $booking['is_external']): ?>
                                                        <br><span class="badge bg-warning text-dark">
                                                            <i class="fas fa-building me-1"></i>Eksternal
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($booking['auto_generated']): ?>
                                                        <br><span class="badge bg-secondary">
                                                            <i class="fas fa-robot me-1"></i>Auto-Generated
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <br><small class="text-muted">
                                                        <i class="fas fa-user me-1"></i>PIC: <?= htmlspecialchars($booking['nama_penanggungjawab'] ?? '') ?><br>
                                                        <i class="fas fa-phone me-1"></i><?= htmlspecialchars($booking['no_penanggungjawab'] ?? '') ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <strong>
                                                        <i class="fas fa-door-open me-1"></i>
                                                        <?= htmlspecialchars($booking['nama_ruang'] ?? '') ?>
                                                    </strong>
                                                    <?php if (!empty($booking['nama_gedung'])): ?>
                                                        <br><small class="text-muted">
                                                            <i class="fas fa-building me-1"></i><?= htmlspecialchars($booking['nama_gedung']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong>
                                                        <i class="fas fa-calendar me-1"></i>
                                                        <?= formatDate($booking['tanggal']) ?>
                                                    </strong><br>
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-clock me-1"></i><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong>
                                                        <i class="fas fa-envelope me-1"></i>
                                                        <?= htmlspecialchars($booking['email'] ?? '') ?>
                                                    </strong>
                                                    <br><span class="badge bg-secondary">
                                                        <i class="fas fa-user-tag me-1"></i>
                                                        <?= ucfirst($booking['user_role']) ?>
                                                    </span>
                                                    <br><small class="text-muted">
                                                        <i class="fas fa-hashtag me-1"></i>ID: #<?= $booking['id_booking'] ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status = $booking['status'] ?? 'unknown';
                                                    switch ($status) {
                                                        case 'pending':
                                                            if ($booking['user_role'] === 'dosen') {
                                                                echo '<span class="badge bg-warning text-dark blink-badge">
                                                                    <i class="fas fa-clock me-1"></i>Perubahan Jadwal
                                                                </span>';
                                                            } else {
                                                                echo '<span class="badge bg-warning text-dark">
                                                                    <i class="fas fa-hourglass-half me-1"></i>Menunggu
                                                                </span>';
                                                            }
                                                            break;
                                                        case 'approve':
                                                            echo '<span class="badge bg-success">
                                                                <i class="fas fa-check me-1"></i>Disetujui
                                                            </span>';
                                                            break;
                                                        case 'active':
                                                            echo '<span class="badge bg-danger">
                                                                <i class="fas fa-play me-1"></i>Sedang Berlangsung
                                                            </span>';
                                                            break;
                                                        case 'rejected':
                                                            echo '<span class="badge bg-danger">
                                                                <i class="fas fa-times me-1"></i>Ditolak
                                                            </span>';
                                                            break;
                                                        case 'cancelled':
                                                            echo '<span class="badge bg-secondary">
                                                                <i class="fas fa-ban me-1"></i>Dibatalkan
                                                            </span>';
                                                            break;
                                                        case 'done':
                                                            echo '<span class="badge bg-info">
                                                                <i class="fas fa-check-circle me-1"></i>Selesai
                                                            </span>';
                                                            break;
                                                        default:
                                                            echo '<span class="badge bg-secondary">
                                                                <i class="fas fa-question me-1"></i>' . ucfirst($status) . '
                                                            </span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group-vertical d-grid gap-1">
                                                        <button type="button" class="btn btn-sm btn-info" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#detailModal<?= $booking['id_booking'] ?>">
                                                            <i class="fas fa-eye me-1"></i>Detail
                                                        </button>
                                                        
                                                        <?php if ($booking['status'] === 'pending' && $booking['user_role'] === 'dosen'): ?>
                                                            <button class="btn btn-sm btn-warning" 
                                                                   onclick="handleScheduleChange(<?= $booking['id_booking'] ?>)">
                                                                <i class="fas fa-clock me-1"></i>Kelola
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (!empty($booking['email'])): ?>
                                                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>" 
                                                               class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-envelope me-1"></i>Email
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-search fa-3x mb-3"></i>
                                                    <h5>Tidak ada data ditemukan</h5>
                                                    <?php if (!empty($search)): ?>
                                                        <p>Hasil pencarian untuk: "<strong><?= htmlspecialchars($search) ?></strong>"</p>
                                                        <a href="dashboard.php" class="btn btn-primary">
                                                            <i class="fas fa-list me-1"></i>Lihat Semua Data
                                                        </a>
                                                    <?php else: ?>
                                                        <p>Belum ada peminjaman ruangan yang tercatat</p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination-wrapper">
                            <div class="page-info">
                                <i class="fas fa-info-circle me-1"></i>
                                Halaman <?= $page ?> dari <?= $totalPages ?> 
                                (<?= number_format($totalRecords) ?> total data)
                            </div>
                            
                            <nav aria-label="Pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <!-- First Page -->
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&page=1">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Previous Page -->
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&page=<?= $page - 1 ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Page Numbers -->
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);
                                    
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&page=<?= $i ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <!-- Next Page -->
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&page=<?= $page + 1 ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <!-- Last Page -->
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&page=<?= $totalPages ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Modals for each booking -->
    <?php foreach ($bookings as $booking): ?>
        <div class="modal fade" id="detailModal<?= $booking['id_booking'] ?>" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-cs-primary text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-info-circle me-2"></i>Detail Peminjaman #<?= $booking['id_booking'] ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom pb-2">
                                    <i class="fas fa-calendar-alt me-1"></i>Informasi Acara
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <th width="30%">Nama Acara:</th>
                                        <td><?= htmlspecialchars($booking['nama_acara'] ?? '') ?></td>
                                    </tr>
                                    <?php if (!empty($booking['nama_matakuliah'])): ?>
                                        <tr>
                                            <th>Mata Kuliah:</th>
                                            <td>
                                                <?= htmlspecialchars($booking['nama_matakuliah']) ?>
                                                <?php if (!empty($booking['kelas'])): ?>
                                                    <span class="badge bg-info ms-2"><?= htmlspecialchars($booking['kelas']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if (!empty($booking['dosen_pengampu'])): ?>
                                            <tr>
                                                <th>Dosen:</th>
                                                <td><?= htmlspecialchars($booking['dosen_pengampu']) ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Tanggal:</th>
                                        <td><?= formatDate($booking['tanggal']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Waktu:</th>
                                        <td>
                                            <span class="badge bg-info">
                                                <?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom pb-2">
                                    <i class="fas fa-users me-1"></i>Informasi Ruangan & Peminjam
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <th width="30%">Ruangan:</th>
                                        <td><?= htmlspecialchars($booking['nama_ruang'] ?? '') ?></td>
                                    </tr>
                                    <?php if (!empty($booking['nama_gedung'])): ?>
                                        <tr>
                                            <th>Gedung:</th>
                                            <td><?= htmlspecialchars($booking['nama_gedung']) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Email Peminjam:</th>
                                        <td>
                                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>">
                                                <?= htmlspecialchars($booking['email'] ?? '') ?>
                                            </a>
                                            <br><span class="badge bg-secondary"><?= ucfirst($booking['user_role']) ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>PIC:</th>
                                        <td><?= htmlspecialchars($booking['nama_penanggungjawab'] ?? '') ?></td>
                                    </tr>
                                    <tr>
                                        <th>No. HP PIC:</th>
                                        <td>
                                            <a href="tel:<?= htmlspecialchars($booking['no_penanggungjawab']) ?>">
                                                <?= htmlspecialchars($booking['no_penanggungjawab'] ?? '') ?>
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="text-primary border-bottom pb-2">
                                    <i class="fas fa-sticky-note me-1"></i>Keterangan
                                </h6>
                                <p class="text-muted"><?= nl2br(htmlspecialchars($booking['keterangan'] ?? 'Tidak ada keterangan')) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Tutup
                        </button>
                        <?php if (!empty($booking['email'])): ?>
                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>" class="btn btn-primary">
                                <i class="fas fa-envelope me-2"></i>Email Peminjam
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        function showTodayUsage() {
            alert('🏢 Fitur Ruangan Hari Ini akan segera ditambahkan!\n\nTotal ruangan terpakai: <?= count($todayUsage) ?>');
        }

        function approveScheduleChange(bookingId) {
            const reason = prompt('Masukkan alasan persetujuan:');
            if (reason) {
                // Implement approval logic
                if (confirm(`Setujui perubahan jadwal #${bookingId}?\n\nAlasan: ${reason}`)) {
                    alert('✅ Perubahan jadwal disetujui!');
                    location.reload();
                }
            }
        }

        function rejectScheduleChange(bookingId) {
            const reason = prompt('Masukkan alasan penolakan:');
            if (reason) {
                // Implement rejection logic
                if (confirm(`Tolak perubahan jadwal #${bookingId}?\n\nAlasan: ${reason}`)) {
                    alert('❌ Perubahan jadwal ditolak!');
                    location.reload();
                }
            }
        }

        function handleScheduleChange(bookingId) {
            if (confirm('Kelola perubahan jadwal #' + bookingId + '?')) {
                alert('🔄 Fitur kelola jadwal akan segera dikembangkan!');
            }
        }

        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-info)');
                alerts.forEach(function(alert) {
                    if (alert.classList.contains('alert-success') || alert.classList.contains('alert-danger')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Check if FontAwesome loaded
            const testIcon = document.createElement('i');
            testIcon.className = 'fas fa-test';
            document.body.appendChild(testIcon);
            
            const computedStyle = window.getComputedStyle(testIcon, ':before');
            if (computedStyle.content === 'none' || computedStyle.content === '""') {
                console.warn('FontAwesome might not be loaded properly');
                // Fallback: reload FontAwesome
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css';
                document.head.appendChild(link);
            }
            
            document.body.removeChild(testIcon);
        });
    </script>
</body>
</html>