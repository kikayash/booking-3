<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

$message = '';
$alertType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_schedule') {
        $scheduleData = [
            'id_ruang' => intval($_POST['id_ruang']),
            'nama_matakuliah' => trim($_POST['nama_matakuliah']),
            'kelas' => trim($_POST['kelas']),
            'dosen_pengampu' => trim($_POST['dosen_pengampu']),
            'hari' => $_POST['hari'],
            'jam_mulai' => $_POST['jam_mulai'],
            'jam_selesai' => $_POST['jam_selesai'],
            'semester' => trim($_POST['semester']),
            'tahun_akademik' => trim($_POST['tahun_akademik']),
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'],
            'created_by' => $_SESSION['user_id']
        ];
        
        $result = addRecurringSchedule($conn, $scheduleData);
        
        if ($result['success']) {
            $message = "Jadwal perkuliahan berhasil ditambahkan! Generated {$result['generated_bookings']} booking otomatis.";
            $alertType = 'success';
        } else {
            $message = "Gagal menambahkan jadwal: " . $result['message'];
            $alertType = 'danger';
        }
    }
    
    if ($action === 'edit_schedule') {
        $scheduleId = intval($_POST['schedule_id']);
        $scheduleData = [
            'id_ruang' => intval($_POST['id_ruang']),
            'nama_matakuliah' => trim($_POST['nama_matakuliah']),
            'kelas' => trim($_POST['kelas']),
            'dosen_pengampu' => trim($_POST['dosen_pengampu']),
            'hari' => $_POST['hari'],
            'jam_mulai' => $_POST['jam_mulai'],
            'jam_selesai' => $_POST['jam_selesai'],
            'semester' => trim($_POST['semester']),
            'tahun_akademik' => trim($_POST['tahun_akademik']),
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date']
        ];
        
        $result = updateRecurringSchedule($conn, $scheduleId, $scheduleData);
        
        if ($result['success']) {
            $message = "Jadwal berhasil diperbarui! {$result['removed_bookings']} booking lama dihapus, {$result['generated_bookings']} booking baru dibuat.";
            $alertType = 'success';
        } else {
            $message = "Gagal memperbarui jadwal: " . $result['message'];
            $alertType = 'danger';
        }
    }
    
    if ($action === 'delete_schedule') {
        $scheduleId = intval($_POST['schedule_id']);
        
        try {
            $conn->beginTransaction();
            
            // Hapus booking terkait terlebih dahulu
            $deletedBookings = removeRecurringScheduleBookings($conn, $scheduleId);
            
            // Hapus jadwal
            $stmt = $conn->prepare("DELETE FROM tbl_recurring_schedules WHERE id_schedule = ?");
            $result = $stmt->execute([$scheduleId]);
            
            if ($result) {
                $conn->commit();
                $message = "Jadwal berhasil dihapus! $deletedBookings booking masa depan telah dihapus.";
                $alertType = 'success';
            } else {
                $conn->rollBack();
                $message = "Gagal menghapus jadwal";
                $alertType = 'danger';
            }
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error deleting schedule: " . $e->getMessage());
            $message = "Gagal menghapus jadwal: " . $e->getMessage();
            $alertType = 'danger';
        }
    }
    
    if ($action === 'generate_schedules') {
    $startDate = $_POST['generate_start_date'];
    $endDate = $_POST['generate_end_date'];
    
    try {
        $conn->beginTransaction();
        
        // Get all active recurring schedules
        $stmt = $conn->prepare("
            SELECT * FROM tbl_recurring_schedules 
            WHERE status = 'active' 
            AND start_date <= ? AND end_date >= ?
        ");
        $stmt->execute([$endDate, $startDate]);
        $schedules = $stmt->fetchAll();
        
        $totalGenerated = 0;
        
        foreach ($schedules as $schedule) {
            // Adjust dates to fit within the requested range
            $adjustedStartDate = max($schedule['start_date'], $startDate);
            $adjustedEndDate = min($schedule['end_date'], $endDate);
            
            if ($adjustedStartDate <= $adjustedEndDate) {
                $scheduleData = [
                    'id_ruang' => $schedule['id_ruang'],
                    'nama_matakuliah' => $schedule['nama_matakuliah'],
                    'kelas' => $schedule['kelas'],
                    'dosen_pengampu' => $schedule['dosen_pengampu'],
                    'hari' => $schedule['hari'],
                    'jam_mulai' => $schedule['jam_mulai'],
                    'jam_selesai' => $schedule['jam_selesai'],
                    'semester' => $schedule['semester'],
                    'tahun_akademik' => $schedule['tahun_akademik'],
                    'start_date' => $adjustedStartDate,
                    'end_date' => $adjustedEndDate,
                    'created_by' => $schedule['created_by']
                ];
                
                $generated = generateBookingsForSchedule($conn, $schedule['id_schedule'], $scheduleData);
                $totalGenerated += $generated;
            }
        }
        
        $conn->commit();
        
        $message = "Berhasil generate $totalGenerated booking dari jadwal perkuliahan untuk periode $startDate sampai $endDate!";
        $alertType = 'success';
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error saat generate: " . $e->getMessage();
        $alertType = 'danger';
        error_log("Generate schedule error: " . $e->getMessage());
    }
}
}

// Get all recurring schedules dengan data angkatan - FIXED JOIN
$stmt = $conn->prepare("
    SELECT rs.*, r.nama_ruang, g.nama_gedung, u.email as created_by_email,
           CASE 
               WHEN rs.kelas REGEXP '^[0-9]+' THEN CONCAT('20', SUBSTRING(rs.kelas, 1, 2))
               ELSE 'Tidak Diketahui'
           END as angkatan
    FROM tbl_recurring_schedules rs
    JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang
    LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
    JOIN tbl_users u ON rs.created_by = u.id_user
    WHERE rs.status = 'active'
    ORDER BY angkatan DESC, rs.hari, rs.jam_mulai
");
$stmt->execute();
$schedules = $stmt->fetchAll();

// Group schedules by angkatan
$schedulesByAngkatan = [];
foreach ($schedules as $schedule) {
    $angkatan = $schedule['angkatan'];
    if (!isset($schedulesByAngkatan[$angkatan])) {
        $schedulesByAngkatan[$angkatan] = [];
    }
    $schedulesByAngkatan[$angkatan][] = $schedule;
}

// Get all rooms for dropdown
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

// Mapping hari
$dayMapping = [
    'monday' => 'Senin',
    'tuesday' => 'Selasa', 
    'wednesday' => 'Rabu',
    'thursday' => 'Kamis',
    'friday' => 'Jumat',
    'saturday' => 'Sabtu',
    'sunday' => 'Minggu'
];

// Get statistics for dashboard
$totalSchedules = count($schedules);
$totalBookingsGenerated = 0;

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_booking WHERE booking_type = 'recurring' AND tanggal >= CURDATE()");
    $stmt->execute();
    $totalBookingsGenerated = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error getting booking count: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Jadwal Perkuliahan - STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .schedule-card {
            transition: transform 0.2s ease;
            border: 1px solid #e0e0e0;
        }
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .day-badge {
            font-size: 0.9rem;
            padding: 8px 12px;
            border-radius: 20px;
        }
        .academic-info {
            background: linear-gradient(135deg, #e3f2fd, #f8f9fa);
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .search-box {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        .angkatan-badge {
            font-size: 1.1rem;
            font-weight: 600;
            padding: 8px 15px;
            border-radius: 25px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
        }
        .schedule-count {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-left: 10px;
        }
        .accordion-button:not(.collapsed) {
            background: linear-gradient(135deg, #e3f2fd, #f8f9fa);
            color: #0056b3;
        }
        .no-results {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .stats-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .generate-tool {
            background: linear-gradient(135deg, #17a2b8, #138496);
            border-radius: 10px;
            color: white;
        }
    </style>
</head>
<body class="admin-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Menu Admin</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="admin-dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                        <a href="recurring_schedules.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-calendar-week me-2"></i> Jadwal Perkuliahan
                        </a>
                        <a href="rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-door-open me-2"></i> Kelola Ruangan
                        </a>
                        <a href="buildings.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-building me-2"></i> Kelola Gedung
                        </a>
                        <a href="holidays.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-alt me-2"></i> Kelola Hari Libur
                        </a>
                        <a href="admin_add_booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="rooms_locks.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-lock me-2"></i> Kelola Lock Ruangan
                        </a>
                        <a href="room_status.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tv me-2"></i> Status Ruangan Real-time
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?= $alertType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Dashboard -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?= $totalSchedules ?></h3>
                                    <p class="mb-0">Jadwal Perkuliahan Aktif</p>
                                </div>
                                <div>
                                    <i class="fas fa-calendar-week fa-3x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stats-card" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?= $totalBookingsGenerated ?></h3>
                                    <p class="mb-0">Booking Auto-Generated</p>
                                </div>
                                <div>
                                    <i class="fas fa-robot fa-3x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Info -->
                <div class="academic-info">
                    <h6 class="text-primary mb-2">
                        <i class="fas fa-graduation-cap me-2"></i>Sistem Jadwal Perkuliahan Otomatis
                    </h6>
                    <p class="mb-2">
                        Sistem ini akan membuat booking otomatis untuk jadwal perkuliahan berulang setiap minggu. 
                        Jika ada hari libur, slot tersebut akan kosong dan bisa dibooking oleh mahasiswa untuk kegiatan lain.
                    </p>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">
                                <i class="fas fa-calendar-check me-1 text-success"></i>
                                <strong>Auto-Generate:</strong> Booking dibuat otomatis
                            </small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">
                                <i class="fas fa-calendar-times me-1 text-warning"></i>
                                <strong>Hari Libur:</strong> Slot kosong untuk booking lain
                            </small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">
                                <i class="fas fa-edit me-1 text-info"></i>
                                <strong>Editable:</strong> Jadwal bisa diubah
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Add New Schedule -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Tambah Jadwal Perkuliahan Berulang</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="addScheduleForm">
                            <input type="hidden" name="action" value="add_schedule">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Mata Kuliah <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="nama_matakuliah" required 
                                               placeholder="contoh: Financial Accounting 2">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Kelas <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="kelas" required 
                                               placeholder="contoh: 23A1">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Dosen Pengampu <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="dosen_pengampu" required 
                                               placeholder="Nama dosen">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Ruangan <span class="text-danger">*</span></label>
                                        <select class="form-select" name="id_ruang" required>
                                            <option value="">-- Pilih Ruangan --</option>
                                            <?php foreach ($rooms as $room): ?>
                                                <option value="<?= $room['id_ruang'] ?>">
                                                    <?= $room['nama_ruang'] ?> (<?= $room['nama_gedung'] ?>) - Kapasitas: <?= $room['kapasitas'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Hari <span class="text-danger">*</span></label>
                                        <select class="form-select" name="hari" required>
                                            <option value="">-- Pilih Hari --</option>
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
                                        <label class="form-label">Jam Mulai <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" name="jam_mulai" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Jam Selesai <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" name="jam_selesai" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Semester <span class="text-danger">*</span></label>
                                        <select class="form-select" name="semester" required>
                                            <option value="">-- Pilih --</option>
                                            <option value="Ganjil">Ganjil</option>
                                            <option value="Genap">Genap</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Tahun Akademik <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="tahun_akademik" required 
                                               placeholder="contoh: 2024/2025" value="2024/2025">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Tanggal Mulai Perkuliahan <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="start_date" required 
                                               value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Tanggal Selesai Perkuliahan <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="end_date" required 
                                               value="<?= date('Y-m-d', strtotime('+6 months')) ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Catatan:</strong> Sistem akan otomatis membuat booking untuk setiap minggu pada hari yang dipilih, 
                                kecuali hari libur yang telah didefinisikan di sistem.
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Simpan & Generate Jadwal
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Generate Schedules Tool -->
                <div class="card shadow mb-4 generate-tool">
                    <div class="card-header text-white">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Generate Booking Otomatis</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="d-flex gap-3 align-items-end flex-wrap" id="generateForm">
                            <input type="hidden" name="action" value="generate_schedules">
                            
                            <div>
                                <label class="form-label text-white">Dari Tanggal</label>
                                <input type="date" class="form-control" name="generate_start_date" 
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div>
                                <label class="form-label text-white">Sampai Tanggal</label>
                                <input type="date" class="form-control" name="generate_end_date" 
                                       value="<?= date('Y-m-d', strtotime('+1 month')) ?>" required>
                            </div>
                            
                            <button type="submit" class="btn btn-light">
                                <i class="fas fa-robot me-2"></i>Generate Booking
                            </button>
                        </form>
                        
                        <div class="mt-3">
                            <small class="text-white-50">
                                <i class="fas fa-info-circle me-1"></i>
                                Tool ini akan membuat booking otomatis berdasarkan jadwal perkuliahan berulang yang sudah ada, 
                                dengan mempertimbangkan hari libur dan konflik jadwal.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Search and Filter -->
                <div class="search-box">
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-search me-2"></i>Cari Jadwal</label>
                            <input type="text" class="form-control" id="searchInput" 
                                   placeholder="Cari mata kuliah, kelas, dosen, atau ruangan...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><i class="fas fa-filter me-2"></i>Filter Angkatan</label>
                            <select class="form-select" id="filterAngkatan">
                                <option value="">Semua Angkatan</option>
                                <?php foreach (array_keys($schedulesByAngkatan) as $angkatan): ?>
                                    <option value="<?= $angkatan ?>"><?= $angkatan ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                                <i class="fas fa-eraser me-2"></i>Reset Filter
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Existing Schedules with Accordion -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Jadwal Perkuliahan Berulang 
                            <span class="badge bg-light text-dark ms-2"><?= count($schedules) ?> Total</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($schedules) > 0): ?>
                            <div class="accordion" id="scheduleAccordion">
                                <?php foreach ($schedulesByAngkatan as $angkatan => $angkatanSchedules): ?>
                                    <div class="accordion-item schedule-group" data-angkatan="<?= $angkatan ?>">
                                        <h2 class="accordion-header" id="heading<?= str_replace(' ', '', $angkatan) ?>">
                                            <button class="accordion-button angkatan-badge" type="button" 
                                                    data-bs-toggle="collapse" 
                                                    data-bs-target="#collapse<?= str_replace(' ', '', $angkatan) ?>" 
                                                    aria-expanded="true" 
                                                    aria-controls="collapse<?= str_replace(' ', '', $angkatan) ?>">
                                                <i class="fas fa-graduation-cap me-3"></i>
                                                Angkatan <?= $angkatan ?>
                                                <span class="schedule-count"><?= count($angkatanSchedules) ?> Jadwal</span>
                                            </button>
                                        </h2>
                                        <div id="collapse<?= str_replace(' ', '', $angkatan) ?>" 
                                             class="accordion-collapse collapse <?= $angkatan === array_keys($schedulesByAngkatan)[0] ? 'show' : '' ?>" 
                                             aria-labelledby="heading<?= str_replace(' ', '', $angkatan) ?>" 
                                             data-bs-parent="#scheduleAccordion">
                                            <div class="accordion-body">
                                                <div class="row">
                                                    <?php foreach ($angkatanSchedules as $schedule): ?>
                                                        <div class="col-md-6 col-lg-4 mb-4 schedule-item" 
                                                             data-search="<?= strtolower($schedule['nama_matakuliah'] . ' ' . $schedule['kelas'] . ' ' . $schedule['dosen_pengampu'] . ' ' . $schedule['nama_ruang']) ?>">
                                                            <div class="card schedule-card h-100 border-primary">
                                                                <div class="card-header bg-light">
                                                                    <div class="d-flex justify-content-between align-items-start">
                                                                        <div>
                                                                            <h6 class="mb-1 text-primary"><?= htmlspecialchars($schedule['nama_matakuliah']) ?></h6>
                                                                            <small class="text-muted"><?= htmlspecialchars($schedule['kelas']) ?></small>
                                                                        </div>
                                                                        <span class="badge day-badge bg-info">
                                                                            <?= $dayMapping[$schedule['hari']] ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Dosen:</small>
                                                                        <strong><?= htmlspecialchars($schedule['dosen_pengampu']) ?></strong>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Waktu:</small>
                                                                        <span class="badge bg-success">
                                                                            <?= date('H:i', strtotime($schedule['jam_mulai'])) ?> - <?= date('H:i', strtotime($schedule['jam_selesai'])) ?>
                                                                        </span>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Ruangan:</small>
                                                                        <strong><?= htmlspecialchars($schedule['nama_ruang']) ?></strong><br>
                                                                        <small class="text-muted"><?= htmlspecialchars($schedule['nama_gedung']) ?></small>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Periode:</small>
                                                                        <span class="badge bg-warning text-dark">
                                                                            <?= htmlspecialchars($schedule['semester']) ?> <?= htmlspecialchars($schedule['tahun_akademik']) ?>
                                                                        </span><br>
                                                                        <small class="text-muted">
                                                                            <?= date('d/m/Y', strtotime($schedule['start_date'])) ?> - <?= date('d/m/Y', strtotime($schedule['end_date'])) ?>
                                                                        </small>
                                                                    </div>
                                                                    
                                                                    <div>
                                                                        <?php if ($schedule['status'] === 'active'): ?>
                                                                            <span class="badge bg-success">Aktif</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-secondary">Nonaktif</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                                <div class="card-footer bg-light">
                                                                    <div class="btn-group w-100" role="group">
                                                                        <button type="button" class="btn btn-sm btn-primary" 
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#editScheduleModal<?= $schedule['id_schedule'] ?>">
                                                                            <i class="fas fa-edit"></i> Edit
                                                                        </button>
                                                                        
                                                                        <button type="button" class="btn btn-sm btn-danger" 
                                                                                onclick="deleteSchedule(<?= $schedule['id_schedule'] ?>)">
                                                                            <i class="fas fa-trash"></i> Hapus
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Edit Schedule Modal -->
                                                        <div class="modal fade" id="editScheduleModal<?= $schedule['id_schedule'] ?>" tabindex="-1">
                                                            <div class="modal-dialog modal-lg">
                                                                <div class="modal-content">
                                                                    <div class="modal-header bg-primary text-white">
                                                                        <h5 class="modal-title">Edit Jadwal Perkuliahan</h5>
                                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <form method="POST">
                                                                            <input type="hidden" name="action" value="edit_schedule">
                                                                            <input type="hidden" name="schedule_id" value="<?= $schedule['id_schedule'] ?>">
                                                                            
                                                                            <div class="row">
                                                                                <div class="col-md-6">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Mata Kuliah</label>
                                                                                        <input type="text" class="form-control" name="nama_matakuliah" 
                                                                                               value="<?= htmlspecialchars($schedule['nama_matakuliah']) ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-6">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Kelas</label>
                                                                                        <input type="text" class="form-control" name="kelas" 
                                                                                               value="<?= htmlspecialchars($schedule['kelas']) ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="row">
                                                                                <div class="col-md-6">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Dosen Pengampu</label>
                                                                                        <input type="text" class="form-control" name="dosen_pengampu" 
                                                                                               value="<?= htmlspecialchars($schedule['dosen_pengampu']) ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-6">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Ruangan</label>
                                                                                        <select class="form-select" name="id_ruang" required>
                                                                                            <option value="">-- Pilih Ruangan --</option>
                                                                                            <?php foreach ($rooms as $room): ?>
                                                                                                <option value="<?= $room['id_ruang'] ?>" 
                                                                                                        <?= $room['id_ruang'] == $schedule['id_ruang'] ? 'selected' : '' ?>>
                                                                                                    <?= $room['nama_ruang'] ?> (<?= $room['nama_gedung'] ?>) - Kapasitas: <?= $room['kapasitas'] ?>
                                                                                                </option>
                                                                                            <?php endforeach; ?>
                                                                                        </select>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="row">
                                                                                <div class="col-md-4">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Hari</label>
                                                                                        <select class="form-select" name="hari" required>
                                                                                            <option value="">-- Pilih Hari --</option>
                                                                                            <?php foreach ($dayMapping as $key => $value): ?>
                                                                                                <option value="<?= $key ?>" <?= $key == $schedule['hari'] ? 'selected' : '' ?>>
                                                                                                    <?= $value ?>
                                                                                                </option>
                                                                                            <?php endforeach; ?>
                                                                                        </select>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-4">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Jam Mulai</label>
                                                                                        <input type="time" class="form-control" name="jam_mulai" 
                                                                                               value="<?= $schedule['jam_mulai'] ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-4">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Jam Selesai</label>
                                                                                        <input type="time" class="form-control" name="jam_selesai" 
                                                                                               value="<?= $schedule['jam_selesai'] ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="row">
                                                                                <div class="col-md-4">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Semester</label>
                                                                                        <select class="form-select" name="semester" required>
                                                                                            <option value="">-- Pilih --</option>
                                                                                            <option value="Ganjil" <?= $schedule['semester'] == 'Ganjil' ? 'selected' : '' ?>>Ganjil</option>
                                                                                            <option value="Genap" <?= $schedule['semester'] == 'Genap' ? 'selected' : '' ?>>Genap</option>
                                                                                        </select>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-4">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Tahun Akademik</label>
                                                                                        <input type="text" class="form-control" name="tahun_akademik" 
                                                                                               value="<?= htmlspecialchars($schedule['tahun_akademik']) ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="row">
                                                                                <div class="col-md-6">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Tanggal Mulai Perkuliahan</label>
                                                                                        <input type="date" class="form-control" name="start_date" 
                                                                                               value="<?= $schedule['start_date'] ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-6">
                                                                                    <div class="mb-3">
                                                                                        <label class="form-label">Tanggal Selesai Perkuliahan</label>
                                                                                        <input type="date" class="form-control" name="end_date" 
                                                                                               value="<?= $schedule['end_date'] ?>" required>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="alert alert-warning">
                                                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                                                <strong>Perhatian:</strong> Mengubah jadwal akan menghapus semua booking masa depan dan membuat ulang booking baru sesuai jadwal yang diperbarui.
                                                                            </div>
                                                                            
                                                                            <div class="d-grid">
                                                                                <button type="submit" class="btn btn-primary">
                                                                                    <i class="fas fa-save me-2"></i>Update Jadwal
                                                                                </button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- No Results Message -->
                            <div id="noResults" class="no-results d-none">
                                <i class="fas fa-search fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada jadwal yang sesuai</h5>
                                <p class="text-muted">Coba gunakan kata kunci yang berbeda atau reset filter.</p>
                            </div>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Belum ada jadwal perkuliahan berulang</h5>
                                <p class="text-muted">Tambahkan jadwal perkuliahan untuk semester ini menggunakan form di atas.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteSchedule(scheduleId) {
            if (confirm('Apakah Anda yakin ingin menghapus jadwal ini?\n\nSemua booking masa depan yang terkait akan dihapus!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_schedule">
                    <input type="hidden" name="schedule_id" value="${scheduleId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            filterSchedules();
        });
        
        document.getElementById('filterAngkatan').addEventListener('change', function() {
            filterSchedules();
        });
        
        function filterSchedules() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const selectedAngkatan = document.getElementById('filterAngkatan').value;
            
            const scheduleGroups = document.querySelectorAll('.schedule-group');
            let visibleGroups = 0;
            
            scheduleGroups.forEach(group => {
                const angkatan = group.getAttribute('data-angkatan');
                const scheduleItems = group.querySelectorAll('.schedule-item');
                let visibleItems = 0;
                
                // Check if angkatan matches filter
                const angkatanMatches = !selectedAngkatan || angkatan === selectedAngkatan;
                
                if (angkatanMatches) {
                    scheduleItems.forEach(item => {
                        const searchData = item.getAttribute('data-search');
                        const matchesSearch = !searchTerm || searchData.includes(searchTerm);
                        
                        if (matchesSearch) {
                            item.style.display = 'block';
                            visibleItems++;
                        } else {
                            item.style.display = 'none';
                        }
                    });
                }
                
                // Show/hide entire group
                if (angkatanMatches && visibleItems > 0) {
                    group.style.display = 'block';
                    visibleGroups++;
                    
                    // Update count in header
                    const countBadge = group.querySelector('.schedule-count');
                    if (countBadge) {
                        countBadge.textContent = visibleItems + ' Jadwal';
                    }
                } else {
                    group.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const noResults = document.getElementById('noResults');
            if (visibleGroups === 0) {
                noResults.classList.remove('d-none');
            } else {
                noResults.classList.add('d-none');
            }
        }
        
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filterAngkatan').value = '';
            filterSchedules();
            
            // Expand all accordion items
            const accordionButtons = document.querySelectorAll('.accordion-button');
            accordionButtons.forEach(button => {
                if (button.classList.contains('collapsed')) {
                    button.click();
                }
            });
        }
        
        // Form validation for schedule form
        document.getElementById('addScheduleForm').addEventListener('submit', function(e) {
            const startDate = new Date(document.querySelector('input[name="start_date"]').value);
            const endDate = new Date(document.querySelector('input[name="end_date"]').value);
            const startTime = document.querySelector('input[name="jam_mulai"]').value;
            const endTime = document.querySelector('input[name="jam_selesai"]').value;
            
            if (endDate <= startDate) {
                e.preventDefault();
                alert('Tanggal selesai harus lebih dari tanggal mulai!');
                return false;
            }
            
            if (endTime <= startTime) {
                e.preventDefault();
                alert('Jam selesai harus lebih dari jam mulai!');
                return false;
            }
            
            // Konfirmasi sebelum submit
            if (!confirm('Apakah Anda yakin ingin menambahkan jadwal perkuliahan ini?\n\nSistem akan otomatis membuat booking untuk setiap minggu.')) {
                e.preventDefault();
                return false;
            }
        });
        
        // Form validation for generate form
        document.getElementById('generateForm').addEventListener('submit', function(e) {
            const startDate = new Date(document.querySelector('input[name="generate_start_date"]').value);
            const endDate = new Date(document.querySelector('input[name="generate_end_date"]').value);
            
            if (endDate <= startDate) {
                e.preventDefault();
                alert('Tanggal akhir harus lebih dari tanggal awal!');
                return false;
            }
            
            // Konfirmasi sebelum generate
            if (!confirm('Apakah Anda yakin ingin generate booking untuk periode ini?\n\nProses ini mungkin memakan waktu beberapa saat.')) {
                e.preventDefault();
                return false;
            }
        });
        
        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-info)');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>