<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// Variabel untuk pesan notifikasi
$message = '';
$alertType = '';

// Cek pesan error/sukses dari URL
if (isset($_GET['error'])) {
    $alertType = 'danger';
    
    switch ($_GET['error']) {
        case 'missing_fields':
            $message = 'Semua field harus diisi.';
            break;
        case 'invalid_date':
            $message = 'Tanggal tidak valid. Pastikan dalam rentang waktu yang diizinkan.';
            break;
        case 'invalid_time_range':
            $message = 'Jam selesai harus setelah jam mulai.';
            break;
        case 'invalid_room':
            $message = 'Ruangan tidak valid.';
            break;
        case 'invalid_user':
            $message = 'Pengguna tidak valid.';
            break;
        case 'booking_conflict':
            $message = 'Terdapat konflik jadwal dengan booking lain.';
            break;
        case 'holiday':
            $message = 'Tidak dapat melakukan booking pada hari libur.';
            break;
        default:
            $message = 'Terjadi kesalahan. Silakan coba lagi.';
    }
} elseif (isset($_GET['success']) && $_GET['success'] == 'booking_added') {
    $alertType = 'success';
    $message = 'Booking berhasil ditambahkan.';
}

// Set back path untuk header
$backPath = '../';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Booking Manual - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="admin-theme">
    <header>
        <?php include '../header.php'; ?>
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
                        <a href="recurring_schedules.php" class="list-group-item list-group-item-action">
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
                        <a href="admin_add_booking.php" class="list-group-item list-group-item-action active">
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
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form id="adminBookingForm" method="post" action="process_admin_booking.php">
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0">Informasi Pemesan</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label for="admin_user_id" class="form-label">Pengguna</label>
                                                <select class="form-select" id="admin_user_id" name="id_user" required>
                                                    <option value="">-- Pilih Pengguna --</option>
                                                    <?php
                                                    $stmt = $conn->prepare("SELECT id_user, email, role FROM tbl_users ORDER BY role, email");
                                                    $stmt->execute();
                                                    $users = $stmt->fetchAll();
                                                    
                                                    $currentRole = '';
                                                    foreach ($users as $user) {
                                                        if ($currentRole != $user['role']) {
                                                            if ($currentRole != '') {
                                                                echo '</optgroup>';
                                                            }
                                                            echo '<optgroup label="' . ucfirst($user['role']) . '">';
                                                            $currentRole = $user['role'];
                                                        }
                                                        echo "<option value='{$user['id_user']}'>{$user['email']}</option>";
                                                    }
                                                    if ($currentRole != '') {
                                                        echo '</optgroup>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="admin_pic_name" class="form-label">Nama Penanggung Jawab</label>
                                                <input type="text" class="form-control" id="admin_pic_name" name="nama_penanggungjawab" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="admin_pic_phone" class="form-label">No. HP Penanggung Jawab</label>
                                                <input type="tel" class="form-control" id="admin_pic_phone" name="no_penanggungjawab" 
                                                       placeholder="Format: 08xxxxxxxxxx" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0">Informasi Ruangan</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label for="admin_room_id" class="form-label">Ruangan</label>
                                                <select class="form-select" id="admin_room_id" name="id_ruang" required>
                                                    <option value="">-- Pilih Ruangan --</option>
                                                    <?php
                                                    $stmt = $conn->prepare("SELECT r.id_ruang, r.nama_ruang, g.nama_gedung, r.kapasitas, r.lokasi
                                                                           FROM tbl_ruang r
                                                                           JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                                                                           ORDER BY g.nama_gedung, r.nama_ruang");
                                                    $stmt->execute();
                                                    $rooms = $stmt->fetchAll();
                                                    
                                                    $currentGedung = '';
                                                    foreach ($rooms as $room) {
                                                        if ($currentGedung != $room['nama_gedung']) {
                                                            if ($currentGedung != '') {
                                                                echo '</optgroup>';
                                                            }
                                                            echo '<optgroup label="' . $room['nama_gedung'] . '">';
                                                            $currentGedung = $room['nama_gedung'];
                                                        }
                                                        echo "<option value='{$room['id_ruang']}'>{$room['nama_ruang']} - {$room['lokasi']} (Kapasitas: {$room['kapasitas']})</option>";
                                                    }
                                                    if ($currentGedung != '') {
                                                        echo '</optgroup>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-12">
                                                    <label for="admin_booking_date" class="form-label">Tanggal</label>
                                                    <input type="date" class="form-control" id="admin_booking_date" name="tanggal" 
                                                           min="<?= date('Y-m-d') ?>" 
                                                           max="<?= date('Y-m-d', strtotime('+1 month')) ?>" required>
                                                    <small class="form-text text-muted">Maksimal 1 bulan ke depan</small>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label for="admin_start_time" class="form-label">Jam Mulai</label>
                                                    <input type="time" class="form-control" id="admin_start_time" name="jam_mulai" 
                                                           min="07:00" max="17:00" required>
                                                    <small class="form-text text-muted">Format 24 jam</small>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="admin_end_time" class="form-label">Jam Selesai</label>
                                                    <input type="time" class="form-control" id="admin_end_time" name="jam_selesai" 
                                                           min="07:30" max="17:00" required>
                                                    <small class="form-text text-muted">Format 24 jam</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">Informasi Kegiatan</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="admin_event_name" class="form-label">Nama Acara</label>
                                        <input type="text" class="form-control" id="admin_event_name" name="nama_acara" 
                                            placeholder="Misalnya: Rapat Jurusan, Seminar, dll" required>
                                    </div>
                                    
                                    <!-- Tambahkan checkbox eksternal disini -->
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="is_external" name="is_external" value="1">
                                        <label class="form-check-label" for="is_external">
                                            <strong>Peminjaman Eksternal</strong> - Acara dilakukan oleh pihak luar kampus
                                        </label>
                                    </div>

                                    <!-- Tambahkan setelah checkbox eksternal -->
                                        <div id="external_info" class="external-info d-none">
                                            <h6><i class="fas fa-exclamation-triangle me-2"></i> Informasi Peminjaman Eksternal</h6>
                                            <ul class="mb-0">
                                                <li>Peminjaman eksternal mungkin dikenakan biaya tambahan</li>
                                                <li>Perlu persetujuan dari Kepala Bagian Umum</li>
                                                <li>Formulir tanggung jawab pengguna fasilitas harus diisi</li>
                                            </ul>
                                        </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_description" class="form-label">Keterangan</label>
                                        <textarea class="form-control" id="admin_description" name="keterangan" rows="4" 
                                                placeholder="Detail acara dan informasi tambahan" required></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_booking_status" class="form-label">Status Booking</label>
                                        <select class="form-select" id="admin_booking_status" name="status" required>
                                            <option value="pending">Pending (Menunggu Persetujuan)</option>
                                            <option value="approve" selected>Disetujui</option>
                                            <option value="active">Aktif (Sedang Berlangsung)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mb-4">
                                <div class="d-flex">
                                    <div class="me-3">
                                        <i class="fas fa-info-circle fa-2x"></i>
                                    </div>
                                    <div>
                                        <h5>Informasi</h5>
                                        <ul class="mb-0">
                                            <li>Pastikan tidak ada konflik jadwal dengan booking lain.</li>
                                            <li>Jika status yang dipilih "Disetujui" atau "Aktif", sistem akan memeriksa konflik jadwal secara otomatis.</li>
                                            <li>Email notifikasi akan dikirim ke pengguna jika status booking disetujui.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='admin-dashboard.php'">
                                    <i class="fas fa-arrow-left me-2"></i> Kembali ke Dashboard
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Simpan Booking
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../main.js"></script>
    <script>
        // Script khusus untuk halaman ini
        document.addEventListener('DOMContentLoaded', function() {
            // Validasi form sebelum submit
            document.getElementById('adminBookingForm').addEventListener('submit', function(e) {
                const startTime = document.getElementById('admin_start_time').value;
                const endTime = document.getElementById('admin_end_time').value;
                
                if (startTime >= endTime) {
                    e.preventDefault();
                    alert('Jam selesai harus setelah jam mulai.');
                }
            });

            // Tambahkan pada bagian script di admin_add_booking.php
            document.getElementById('is_external').addEventListener('change', function() {
                // Tambahkan highlight pada form jika eksternal dicentang
                if (this.checked) {
                    document.getElementById('adminBookingForm').classList.add('external-booking');
                    // Opsional: Tambahkan biaya tambahan atau informasi lain untuk booking eksternal
                    document.getElementById('external_info').classList.remove('d-none');
                } else {
                    document.getElementById('adminBookingForm').classList.remove('external-booking');
                    document.getElementById('external_info').classList.add('d-none');
                }
            });
            
            // Auto-fill nama penanggung jawab berdasarkan user yang dipilih
            document.getElementById('admin_user_id').addEventListener('change', function() {
                const userId = this.value;
                if (userId) {
                    fetch(`get_user_details.php?id=${userId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('admin_pic_name').value = data.name || '';
                                document.getElementById('admin_pic_phone').value = data.phone || '';
                            }
                        })
                        .catch(error => console.error('Error:', error));
                }
            });
        });
    </script>
</body>
</html>