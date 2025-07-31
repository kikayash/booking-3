<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is CS
if (!isLoggedIn() || !isCS()) {
    header('Location: ../index.php');
    exit;
}

// Get available rooms
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

// Get available users
$stmt = $conn->prepare("SELECT * FROM tbl_users ORDER BY role, email");
$stmt->execute();
$users = $stmt->fetchAll();

// Define add-on facilities with prices
$addonFacilities = [
    'sound_system' => ['name' => 'Sound System', 'price' => 150000, 'icon' => 'fas fa-volume-up'],
    'projector_screen' => ['name' => 'Projector + Screen', 'price' => 100000, 'icon' => 'fas fa-desktop'],
    'catering_snack' => ['name' => 'Catering Snack', 'price' => 25000, 'icon' => 'fas fa-cookie-bite', 'unit' => 'per orang'],
    'catering_lunch' => ['name' => 'Catering Lunch', 'price' => 50000, 'icon' => 'fas fa-utensils', 'unit' => 'per orang'],
    'decoration' => ['name' => 'Dekorasi Ruangan', 'price' => 300000, 'icon' => 'fas fa-gifts'],
    'photography' => ['name' => 'Dokumentasi Foto', 'price' => 500000, 'icon' => 'fas fa-camera'],
    'security' => ['name' => 'Keamanan Tambahan', 'price' => 75000, 'icon' => 'fas fa-shield-alt', 'unit' => 'per jam'],
    'cleaning_service' => ['name' => 'Cleaning Service', 'price' => 100000, 'icon' => 'fas fa-broom'],
    'wifi_upgrade' => ['name' => 'WiFi Premium', 'price' => 200000, 'icon' => 'fas fa-wifi'],
    'parking_valet' => ['name' => 'Valet Parking', 'price' => 300000, 'icon' => 'fas fa-car']
];

// Handle form submission
$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['id_user', 'id_ruang', 'nama_acara', 'tanggal', 'jam_mulai', 'jam_selesai', 'keterangan', 'nama_penanggungjawab', 'no_penanggungjawab'];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field $field harus diisi.");
            }
        }
        
        $id_user = $_POST['id_user'];
        $id_ruang = $_POST['id_ruang'];
        $nama_acara = $_POST['nama_acara'];
        $tanggal = $_POST['tanggal'];
        $jam_mulai = $_POST['jam_mulai'];
        $jam_selesai = $_POST['jam_selesai'];
        $keterangan = $_POST['keterangan'];
        $nama_penanggungjawab = $_POST['nama_penanggungjawab'];
        $no_penanggungjawab = $_POST['no_penanggungjawab'];
        $is_external = isset($_POST['is_external']) ? 1 : 0;
        
        // Validate date range
        $today = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime('+1 year'));
        
        if ($tanggal < $today || $tanggal > $maxDate) {
            throw new Exception("Tanggal booking harus antara hari ini sampai 1 tahun ke depan.");
        }
        
        // Validate time range
        if ($jam_mulai >= $jam_selesai) {
            throw new Exception("Jam selesai harus setelah jam mulai.");
        }
        
        // Check for conflicts
        if (hasBookingConflict($conn, $id_ruang, $tanggal, $jam_mulai, $jam_selesai)) {
            throw new Exception("Terdapat konflik jadwal dengan booking lain.");
        }
        
        // Calculate add-on costs
        $selectedAddons = $_POST['selected_addons'] ?? [];
        $addonQuantities = $_POST['addon_quantities'] ?? [];
        $totalAddonCost = 0;
        $addonDetails = [];
        
        if ($is_external && !empty($selectedAddons)) {
            foreach ($selectedAddons as $addonKey) {
                if (isset($addonFacilities[$addonKey])) {
                    $addon = $addonFacilities[$addonKey];
                    $quantity = isset($addonQuantities[$addonKey]) ? intval($addonQuantities[$addonKey]) : 1;
                    $quantity = max(1, $quantity); // Minimum 1
                    
                    $cost = $addon['price'] * $quantity;
                    $totalAddonCost += $cost;
                    
                    $addonDetails[] = [
                        'key' => $addonKey,
                        'name' => $addon['name'],
                        'quantity' => $quantity,
                        'unit_price' => $addon['price'],
                        'total_price' => $cost,
                        'unit' => $addon['unit'] ?? 'unit'
                    ];
                }
            }
        }
        
        $conn->beginTransaction();
        
        // Insert booking
        $stmt = $conn->prepare("INSERT INTO tbl_booking 
                               (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, keterangan, 
                                nama_penanggungjawab, no_penanggungjawab, status, is_external, 
                                created_by_cs, cs_user_id, addon_total_cost, booking_type) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 1, ?, ?, ?)");
        
        $bookingType = $is_external ? 'external' : 'manual';
        
        $stmt->execute([
            $id_user, $id_ruang, $nama_acara, $tanggal, $jam_mulai, $jam_selesai, $keterangan, 
            $nama_penanggungjawab, $no_penanggungjawab, $is_external, $_SESSION['user_id'], $totalAddonCost, $bookingType
        ]);
        
        $bookingId = $conn->lastInsertId();
        
        // Insert add-on details if any
        if (!empty($addonDetails)) {
            // Create table if not exists
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_booking_addons (
                id_addon INT AUTO_INCREMENT PRIMARY KEY,
                id_booking INT NOT NULL,
                addon_key VARCHAR(50) NOT NULL,
                addon_name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(10,2) NOT NULL,
                total_price DECIMAL(10,2) NOT NULL,
                unit_type VARCHAR(20) DEFAULT 'unit',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (id_booking) REFERENCES tbl_booking(id_booking) ON DELETE CASCADE
            )");
            
            $stmt = $conn->prepare("INSERT INTO tbl_booking_addons 
                                   (id_booking, addon_key, addon_name, quantity, unit_price, total_price, unit_type) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($addonDetails as $detail) {
                $stmt->execute([
                    $bookingId, $detail['key'], $detail['name'], $detail['quantity'],
                    $detail['unit_price'], $detail['total_price'], $detail['unit']
                ]);
            }
        }
        
        $conn->commit();
        
        $message = "✅ Booking berhasil ditambahkan! ID: #$bookingId";
        if ($totalAddonCost > 0) {
            $message .= " | Total add-on: Rp " . number_format($totalAddonCost, 0, ',', '.');
        }
        $alertType = 'success';
        
        // Reset form
        $_POST = [];
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        $message = "❌ " . $e->getMessage();
        $alertType = 'danger';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Booking Manual - CS STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .addon-card { 
            border: 2px dashed #ddd; 
            border-radius: 10px; 
            padding: 15px; 
            margin: 10px 0; 
            cursor: pointer; 
            transition: all 0.3s;
        }
        .addon-card:hover { 
            border-color: #007bff; 
            background: #f8f9fa; 
        }
        .addon-card.selected { 
            border-color: #28a745; 
            background: #d4edda; 
            border-style: solid; 
        }
        .addon-price { 
            font-size: 1.2rem; 
            color: #dc3545; 
            font-weight: bold; 
        }
        .total-display {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin: 15px 0;
        }
    </style>
</head>
<body class="cs-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- CS Sidebar -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-headset me-2"></i>Menu CS</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard CS
                        </a>
                        <a href="add-booking.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="today_rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-day me-2"></i> Ruangan Hari Ini
                        </a>
                        <a href="../index.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar me-2"></i> Kalender Booking
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
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Tambah Booking Manual - Customer Service
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>Informasi CS</h6>
                            <ul class="mb-0">
                                <li>Semua booking CS memerlukan persetujuan admin</li>
                                <li>Add-on tersedia untuk acara eksternal (centang checkbox)</li>
                                <li>Dokumentasikan permintaan dari klien eksternal</li>
                            </ul>
                        </div>
                        
                        <form method="POST" id="bookingForm">
                            <div class="row">
                                <!-- Basic Information -->
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0">Informasi Dasar</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Peminjam *</label>
                                                <select class="form-select" name="id_user" required>
                                                    <option value="">-- Pilih Peminjam --</option>
                                                    <?php foreach ($users as $user): ?>
                                                        <option value="<?= $user['id_user'] ?>" <?= (isset($_POST['id_user']) && $_POST['id_user'] == $user['id_user']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($user['email']) ?> (<?= ucfirst($user['role']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Ruangan *</label>
                                                <select class="form-select" name="id_ruang" required>
                                                    <option value="">-- Pilih Ruangan --</option>
                                                    <?php foreach ($rooms as $room): ?>
                                                        <option value="<?= $room['id_ruang'] ?>" <?= (isset($_POST['id_ruang']) && $_POST['id_ruang'] == $room['id_ruang']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($room['nama_ruang']) ?> - <?= htmlspecialchars($room['nama_gedung']) ?> (<?= $room['kapasitas'] ?> orang)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Nama Acara *</label>
                                                <input type="text" class="form-control" name="nama_acara" 
                                                       value="<?= htmlspecialchars($_POST['nama_acara'] ?? '') ?>" 
                                                       placeholder="Contoh: Seminar Bisnis Digital" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal *</label>
                                                <input type="date" class="form-control" name="tanggal" 
                                                       value="<?= htmlspecialchars($_POST['tanggal'] ?? '') ?>"
                                                       min="<?= date('Y-m-d') ?>" 
                                                       max="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label class="form-label">Jam Mulai *</label>
                                                    <input type="time" class="form-control" name="jam_mulai" 
                                                           value="<?= htmlspecialchars($_POST['jam_mulai'] ?? '') ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Jam Selesai *</label>
                                                    <input type="time" class="form-control" name="jam_selesai" 
                                                           value="<?= htmlspecialchars($_POST['jam_selesai'] ?? '') ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Contact Information -->
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0">Informasi Kontak</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Nama PIC *</label>
                                                <input type="text" class="form-control" name="nama_penanggungjawab" 
                                                       value="<?= htmlspecialchars($_POST['nama_penanggungjawab'] ?? '') ?>" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">No. HP PIC *</label>
                                                <input type="tel" class="form-control" name="no_penanggungjawab" 
                                                       value="<?= htmlspecialchars($_POST['no_penanggungjawab'] ?? '') ?>"
                                                       placeholder="08xxxxxxxxxx" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Keterangan Acara *</label>
                                                <textarea class="form-control" name="keterangan" rows="4" 
                                                          placeholder="Detail acara, jumlah peserta, kebutuhan khusus" required><?= htmlspecialchars($_POST['keterangan'] ?? '') ?></textarea>
                                            </div>
                                            
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_external" value="1" 
                                                       id="is_external" onchange="toggleAddons()"
                                                       <?= (isset($_POST['is_external']) && $_POST['is_external']) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="is_external">
                                                    <strong>Acara Eksternal/Non-Akademik</strong>
                                                    <br><small class="text-muted">Centang untuk menambah fasilitas add-on</small>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Add-on Section -->
                            <div class="card mb-4" id="addonSection" style="display: none;">
                                <div class="card-header bg-warning text-dark">
                                    <h5 class="mb-0">
                                        <i class="fas fa-plus-square me-2"></i>Add-on Fasilitas Premium
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($addonFacilities as $key => $addon): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="addon-card" onclick="toggleAddon('<?= $key ?>')">
                                                    <input type="checkbox" name="selected_addons[]" value="<?= $key ?>" 
                                                           id="addon_<?= $key ?>" style="display: none;">
                                                    
                                                    <div class="text-center">
                                                        <i class="<?= $addon['icon'] ?> fa-2x text-primary mb-2"></i>
                                                        <h6 class="fw-bold"><?= $addon['name'] ?></h6>
                                                        <div class="addon-price">
                                                            Rp <?= number_format($addon['price'], 0, ',', '.') ?>
                                                            <?php if (isset($addon['unit'])): ?>
                                                                <br><small>/ <?= $addon['unit'] ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div class="mt-2" id="quantity_<?= $key ?>" style="display: none;">
                                                            <label class="form-label small">Jumlah:</label>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="addon_quantities[<?= $key ?>]" 
                                                                   value="1" min="1" max="100" onchange="calculateTotal()">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="total-display">
                                        <h5 class="mb-1">Total Biaya Add-on</h5>
                                        <h3 class="mb-0">Rp <span id="totalAmount">0</span></h3>
                                        <small>Belum termasuk biaya sewa ruangan dasar</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Kembali
                                </a>
                                
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>
                                    Kirim ke Admin untuk Persetujuan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const addonPrices = <?= json_encode(array_map(function($addon) { return $addon['price']; }, $addonFacilities)) ?>;
        
        function toggleAddons() {
            const isExternal = document.getElementById('is_external').checked;
            const addonSection = document.getElementById('addonSection');
            
            if (isExternal) {
                addonSection.style.display = 'block';
            } else {
                addonSection.style.display = 'none';
                // Reset all addons
                document.querySelectorAll('input[name="selected_addons[]"]').forEach(checkbox => {
                    checkbox.checked = false;
                    const addonKey = checkbox.value;
                    document.querySelector('[onclick="toggleAddon(\'' + addonKey + '\')"]').classList.remove('selected');
                    document.getElementById('quantity_' + addonKey).style.display = 'none';
                });
                calculateTotal();
            }
        }
        
        function toggleAddon(addonKey) {
            const checkbox = document.getElementById('addon_' + addonKey);
            const card = checkbox.closest('.addon-card');
            const quantityDiv = document.getElementById('quantity_' + addonKey);
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                card.classList.add('selected');
                quantityDiv.style.display = 'block';
            } else {
                card.classList.remove('selected');
                quantityDiv.style.display = 'none';
                quantityDiv.querySelector('input').value = 1;
            }
            
            calculateTotal();
        }
        
        function calculateTotal() {
            let total = 0;
            
            document.querySelectorAll('input[name="selected_addons[]"]:checked').forEach(checkbox => {
                const addonKey = checkbox.value;
                const price = addonPrices[addonKey] || 0;
                const quantityInput = document.querySelector('input[name="addon_quantities[' + addonKey + ']"]');
                const quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
                
                total += price * quantity;
            });
            
            document.getElementById('totalAmount').textContent = total.toLocaleString('id-ID');
        }
        
        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const jamMulai = document.querySelector('input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('input[name="jam_selesai"]').value;
            
            if (jamMulai >= jamSelesai) {
                e.preventDefault();
                alert('Jam selesai harus setelah jam mulai.');
                return false;
            }
            
            const isExternal = document.getElementById('is_external').checked;
            const hasAddons = document.querySelectorAll('input[name="selected_addons[]"]:checked').length > 0;
            
            if (isExternal && hasAddons) {
                const totalAmount = document.getElementById('totalAmount').textContent;
                const confirmed = confirm(`Konfirmasi booking eksternal dengan add-on.\nTotal: Rp ${totalAmount}\n\nLanjutkan?`);
                if (!confirmed) {
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('is_external').checked) {
                toggleAddons();
            }
        });
    </script>
</body>
</html>