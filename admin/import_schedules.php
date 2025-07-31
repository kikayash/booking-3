<?php
// admin/import_schedules.php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['schedules'])) {
        throw new Exception('Invalid data format');
    }
    
    $schedules = $data['schedules'];
    $imported = 0;
    $errors = 0;
    $totalBookingsGenerated = 0;
    $errorDetails = [];
    
    // Get system user ID
    $systemUserId = getSystemUserId($conn);
    
    // Get all rooms for validation
    $stmt = $conn->prepare("SELECT r.id_ruang, r.nama_ruang, g.nama_gedung 
                           FROM tbl_ruang r 
                           LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung");
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create room mapping for quick lookup
    $roomMap = [];
    foreach ($rooms as $room) {
        $roomMap[strtolower($room['nama_ruang'])] = $room['id_ruang'];
    }
    
    // Day mapping
    $dayMapping = [
        'monday' => 'monday',
        'tuesday' => 'tuesday',
        'wednesday' => 'wednesday',
        'thursday' => 'thursday',
        'friday' => 'friday',
        'saturday' => 'saturday',
        'sunday' => 'sunday'
    ];
    
    $conn->beginTransaction();
    
    foreach ($schedules as $schedule) {
        try {
            // Validate and map room
            $roomName = strtolower(trim($schedule['ruangan']));
            $roomId = null;
            
            foreach ($roomMap as $name => $id) {
                if (strpos($name, $roomName) !== false || strpos($roomName, $name) !== false) {
                    $roomId = $id;
                    break;
                }
            }
            
            if (!$roomId) {
                throw new Exception("Ruangan '{$schedule['ruangan']}' tidak ditemukan");
            }
            
            // Map day
            $dayKey = strtolower($schedule['hari']);
            if (!isset($dayMapping[$dayKey])) {
                throw new Exception("Hari '{$schedule['hari']}' tidak valid");
            }
            
            // Check for existing schedule conflict
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM tbl_recurring_schedules 
                WHERE id_ruang = ? 
                AND hari = ? 
                AND ((jam_mulai <= ? AND jam_selesai > ?) OR 
                     (jam_mulai < ? AND jam_selesai >= ?) OR
                     (jam_mulai >= ? AND jam_selesai <= ?))
                AND status = 'active'
            ");
            $stmt->execute([
                $roomId, 
                $dayMapping[$dayKey],
                $schedule['jam_selesai'], $schedule['jam_mulai'],
                $schedule['jam_mulai'], $schedule['jam_selesai'],
                $schedule['jam_mulai'], $schedule['jam_selesai']
            ]);
            $conflictCount = $stmt->fetchColumn();
            
            if ($conflictCount > 0) {
                throw new Exception("Konflik jadwal di ruangan {$schedule['ruangan']} pada hari {$schedule['hari']}");
            }
            
            // Insert recurring schedule
            $stmt = $conn->prepare("
                INSERT INTO tbl_recurring_schedules 
                (id_ruang, nama_matakuliah, kelas, dosen_pengampu, hari, jam_mulai, jam_selesai, 
                 semester, tahun_akademik, start_date, end_date, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            
            $result = $stmt->execute([
                $roomId,
                $schedule['mata_kuliah'],
                $schedule['kelas'],
                $schedule['dosen'],
                $dayMapping[$dayKey],
                $schedule['jam_mulai'],
                $schedule['jam_selesai'],
                $schedule['semester'],
                $schedule['tahun_akademik'],
                $schedule['tanggal_mulai'],
                $schedule['tanggal_selesai'],
                $systemUserId
            ]);
            
            if ($result) {
                $scheduleId = $conn->lastInsertId();
                
                // Generate bookings for this schedule
                $scheduleData = [
                    'id_ruang' => $roomId,
                    'nama_matakuliah' => $schedule['mata_kuliah'],
                    'kelas' => $schedule['kelas'],
                    'dosen_pengampu' => $schedule['dosen'],
                    'hari' => $dayMapping[$dayKey],
                    'jam_mulai' => $schedule['jam_mulai'],
                    'jam_selesai' => $schedule['jam_selesai'],
                    'semester' => $schedule['semester'],
                    'tahun_akademik' => $schedule['tahun_akademik'],
                    'start_date' => $schedule['tanggal_mulai'],
                    'end_date' => $schedule['tanggal_selesai'],
                    'created_by' => $systemUserId
                ];
                
                $generatedBookings = generateBookingsForSchedule($conn, $scheduleId, $scheduleData);
                $totalBookingsGenerated += $generatedBookings;
                
                $imported++;
                
                error_log("EXCEL IMPORT: Schedule '{$schedule['mata_kuliah']}' imported with {$generatedBookings} bookings");
            } else {
                throw new Exception("Gagal menyimpan jadwal");
            }
            
        } catch (Exception $e) {
            $errors++;
            $errorDetails[] = [
                'schedule' => $schedule['mata_kuliah'] . ' - ' . $schedule['kelas'],
                'error' => $e->getMessage()
            ];
            error_log("EXCEL IMPORT ERROR: " . $e->getMessage());
        }
    }
    
    $conn->commit();
    
    // Prepare response
    $response = [
        'success' => true,
        'imported' => $imported,
        'errors' => $errors,
        'bookings_generated' => $totalBookingsGenerated,
        'message' => "Berhasil import {$imported} jadwal perkuliahan dengan total {$totalBookingsGenerated} booking otomatis."
    ];
    
    if ($errors > 0) {
        $response['error_details'] = $errorDetails;
        $response['message'] .= " {$errors} jadwal gagal diimport.";
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $conn->rollBack();
    error_log("EXCEL IMPORT FATAL ERROR: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Import failed: ' . $e->getMessage()
    ]);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Jadwal Excel - STIE MCE</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .import-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .step-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .step-card:hover {
            border-color: #007bff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .drop-zone {
            border: 3px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        
        .file-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .progress-container {
            display: none;
            margin-top: 20px;
        }
        
        .data-preview {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        
        .error-row {
            background-color: #f8d7da !important;
        }
        
        .success-row {
            background-color: #d1edff !important;
        }
        
        .template-download {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-weight: 600;
        }
        
        .template-download:hover {
            background: linear-gradient(135deg, #218838, #1e9b7f);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
    </style>
</head>
<body>
    <div class="import-container">
        <!-- Header -->
        <div class="text-center mb-5">
            <h2 class="text-primary">
                <i class="fas fa-file-excel me-3"></i>Import Jadwal Perkuliahan dari Excel
            </h2>
            <p class="text-muted">Import jadwal perkuliahan secara massal menggunakan file Excel (.xlsx)</p>
        </div>

        <!-- Steps Guide -->
        <div class="row mb-5">
            <div class="col-md-4">
                <div class="step-card p-4 h-100">
                    <div class="step-number">1</div>
                    <h5>Download Template</h5>
                    <p class="text-muted">Download template Excel dengan format yang sudah ditentukan</p>
                    <a href="#" class="template-download" onclick="downloadTemplate()">
                        <i class="fas fa-download me-2"></i>Download Template
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step-card p-4 h-100">
                    <div class="step-number">2</div>
                    <h5>Isi Data</h5>
                    <p class="text-muted">Isi data jadwal perkuliahan sesuai dengan format template</p>
                    <div class="mt-3">
                        <small class="text-info">
                            <i class="fas fa-info-circle me-1"></i>
                            Pastikan format data sesuai dengan contoh
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step-card p-4 h-100">
                    <div class="step-number">3</div>
                    <h5>Upload & Import</h5>
                    <p class="text-muted">Upload file Excel dan sistem akan mengimpor data secara otomatis</p>
                    <div class="mt-3">
                        <small class="text-success">
                            <i class="fas fa-check-circle me-1"></i>
                            Data akan divalidasi sebelum import
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload Section -->
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-upload me-2"></i>Upload File Excel
                </h5>
            </div>
            <div class="card-body">
                <!-- Drop Zone -->
                <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                    <h5>Drag & Drop file Excel di sini</h5>
                    <p class="text-muted">atau klik untuk memilih file</p>
                    <small class="text-muted">Format yang didukung: .xlsx (maksimal 5MB)</small>
                </div>
                
                <input type="file" id="fileInput" accept=".xlsx" style="display: none;" onchange="handleFileSelect(event)">
                
                <!-- File Preview -->
                <div id="filePreview" class="file-preview" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-file-excel text-success me-2"></i>
                            <span id="fileName"></span>
                            <small class="text-muted ms-2">(<span id="fileSize"></span>)</small>
                        </div>
                        <button class="btn btn-sm btn-outline-danger" onclick="removeFile()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="progress-container" id="progressContainer">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted">Memproses file...</small>
                        <small class="text-muted"><span id="progressText">0%</span></small>
                    </div>
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             id="progressBar" style="width: 0%"></div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="mt-4 text-center">
                    <button class="btn btn-primary btn-lg" id="processBtn" disabled onclick="processFile()">
                        <i class="fas fa-cogs me-2"></i>Proses & Preview Data
                    </button>
                </div>
            </div>
        </div>

        <!-- Data Preview Section -->
        <div class="card shadow mt-4" id="previewSection" style="display: none;">
            <div class="card-header bg-info text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-eye me-2"></i>Preview Data Jadwal
                    </h5>
                    <div>
                        <span class="badge bg-light text-dark me-2">
                            Total: <span id="totalRecords">0</span>
                        </span>
                        <span class="badge bg-success me-2">
                            Valid: <span id="validRecords">0</span>
                        </span>
                        <span class="badge bg-danger">
                            Error: <span id="errorRecords">0</span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="data-preview">
                    <table class="table table-striped table-hover mb-0" id="previewTable">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th width="5%">No</th>
                                <th width="15%">Mata Kuliah</th>
                                <th width="10%">Kelas</th>
                                <th width="15%">Dosen</th>
                                <th width="10%">Hari</th>
                                <th width="15%">Waktu</th>
                                <th width="10%">Ruangan</th>
                                <th width="10%">Periode</th>
                                <th width="10%">Status</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <div class="d-flex justify-content-between">
                    <button class="btn btn-secondary" onclick="resetImport()">
                        <i class="fas fa-redo me-2"></i>Reset
                    </button>
                    <button class="btn btn-success btn-lg" id="importBtn" disabled onclick="importData()">
                        <i class="fas fa-database me-2"></i>Import ke Database
                    </button>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div class="card shadow mt-4" id="resultsSection" style="display: none;">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-check-circle me-2"></i>Hasil Import
                </h5>
            </div>
            <div class="card-body">
                <div id="importResults"></div>
                <div class="text-center mt-4">
                    <a href="recurring_schedules.php" class="btn btn-primary">
                        <i class="fas fa-calendar-week me-2"></i>Lihat Jadwal Perkuliahan
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
        let selectedFile = null;
        let parsedData = [];
        let validatedData = [];

        // Template download
        function downloadTemplate() {
            const templateData = [
                ['Mata Kuliah', 'Kelas', 'Dosen Pengampu', 'Hari', 'Jam Mulai', 'Jam Selesai', 'Ruangan', 'Semester', 'Tahun Akademik', 'Tanggal Mulai', 'Tanggal Selesai'],
                ['Financial Accounting 2', '23A1', 'Bu Dyah', 'Monday', '09:00', '11:30', 'K-4', 'Genap', '2024/2025', '2025-01-15', '2025-06-15'],
                ['Management Accounting', '23B2', 'Pak Budi', 'Tuesday', '13:00', '15:30', 'M-1', 'Genap', '2024/2025', '2025-01-15', '2025-06-15'],
                ['Business Statistics', '22A1', 'Bu Sari', 'Wednesday', '08:00', '10:30', 'K-1', 'Genap', '2024/2025', '2025-01-15', '2025-06-15']
            ];

            const ws = XLSX.utils.aoa_to_sheet(templateData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Jadwal Template');
            
            // Set column widths
            ws['!cols'] = [
                {width: 20}, {width: 10}, {width: 15}, {width: 10}, 
                {width: 10}, {width: 10}, {width: 10}, {width: 10}, 
                {width: 12}, {width: 12}, {width: 12}
            ];
            
            XLSX.writeFile(wb, 'Template_Jadwal_Perkuliahan.xlsx');
        }

        // Drag and drop functionality
        const dropZone = document.getElementById('dropZone');
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFile(files[0]);
            }
        });

        // File selection handler
        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                handleFile(file);
            }
        }

        // Handle file
        function handleFile(file) {
            // Validate file type
            if (!file.name.toLowerCase().endsWith('.xlsx')) {
                alert('❌ Hanya file Excel (.xlsx) yang diizinkan!');
                return;
            }
            
            // Validate file size (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                alert('❌ Ukuran file maksimal 5MB!');
                return;
            }
            
            selectedFile = file;
            showFilePreview(file);
        }

        // Show file preview
        function showFilePreview(file) {
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = formatFileSize(file.size);
            document.getElementById('filePreview').style.display = 'block';
            document.getElementById('processBtn').disabled = false;
        }

        // Remove file
        function removeFile() {
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('filePreview').style.display = 'none';
            document.getElementById('processBtn').disabled = true;
            resetPreview();
        }

        // Format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Process file
        function processFile() {
            if (!selectedFile) return;
            
            showProgress(true);
            updateProgress(20, 'Membaca file Excel...');
            
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    updateProgress(40, 'Parsing data...');
                    
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, {type: 'array'});
                    const sheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[sheetName];
                    
                    updateProgress(60, 'Memvalidasi data...');
                    
                    const jsonData = XLSX.utils.sheet_to_json(worksheet, {header: 1});
                    parsedData = jsonData.slice(1); // Remove header row
                    
                    updateProgress(80, 'Memproses data...');
                    
                    validateData();
                    
                    updateProgress(100, 'Selesai!');
                    
                    setTimeout(() => {
                        showProgress(false);
                        showPreview();
                    }, 1000);
                    
                } catch (error) {
                    console.error('Error processing file:', error);
                    showProgress(false);
                    alert('❌ Error memproses file: ' + error.message);
                }
            };
            
            reader.readAsArrayBuffer(selectedFile);
        }

        // Validate data
        function validateData() {
            validatedData = [];
            
            parsedData.forEach((row, index) => {
                const record = {
                    no: index + 1,
                    mata_kuliah: row[0] || '',
                    kelas: row[1] || '',
                    dosen: row[2] || '',
                    hari: row[3] || '',
                    jam_mulai: row[4] || '',
                    jam_selesai: row[5] || '',
                    ruangan: row[6] || '',
                    semester: row[7] || '',
                    tahun_akademik: row[8] || '',
                    tanggal_mulai: row[9] || '',
                    tanggal_selesai: row[10] || '',
                    errors: [],
                    isValid: true
                };
                
                // Validation rules
                if (!record.mata_kuliah.trim()) {
                    record.errors.push('Mata kuliah wajib diisi');
                    record.isValid = false;
                }
                
                if (!record.kelas.trim()) {
                    record.errors.push('Kelas wajib diisi');
                    record.isValid = false;
                }
                
                if (!record.dosen.trim()) {
                    record.errors.push('Dosen wajib diisi');
                    record.isValid = false;
                }
                
                const validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                if (!validDays.includes(record.hari)) {
                    record.errors.push('Hari tidak valid (Monday-Sunday)');
                    record.isValid = false;
                }
                
                // Time validation
                const timeRegex = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/;
                if (!timeRegex.test(record.jam_mulai)) {
                    record.errors.push('Format jam mulai tidak valid (HH:MM)');
                    record.isValid = false;
                }
                
                if (!timeRegex.test(record.jam_selesai)) {
                    record.errors.push('Format jam selesai tidak valid (HH:MM)');
                    record.isValid = false;
                }
                
                if (record.jam_mulai >= record.jam_selesai) {
                    record.errors.push('Jam selesai harus lebih dari jam mulai');
                    record.isValid = false;
                }
                
                if (!record.ruangan.trim()) {
                    record.errors.push('Ruangan wajib diisi');
                    record.isValid = false;
                }
                
                validatedData.push(record);
            });
        }

        // Show preview
        function showPreview() {
            const previewSection = document.getElementById('previewSection');
            const tableBody = document.getElementById('previewTableBody');
            
            // Update counters
            const totalRecords = validatedData.length;
            const validRecords = validatedData.filter(r => r.isValid).length;
            const errorRecords = totalRecords - validRecords;
            
            document.getElementById('totalRecords').textContent = totalRecords;
            document.getElementById('validRecords').textContent = validRecords;
            document.getElementById('errorRecords').textContent = errorRecords;
            
            // Populate table
            tableBody.innerHTML = '';
            
            validatedData.forEach(record => {
                const row = document.createElement('tr');
                row.className = record.isValid ? 'success-row' : 'error-row';
                
                const statusCell = record.isValid ? 
                    '<span class="badge bg-success">Valid</span>' :
                    '<span class="badge bg-danger">Error</span>';
                
                row.innerHTML = `
                    <td>${record.no}</td>
                    <td>${record.mata_kuliah}</td>
                    <td>${record.kelas}</td>
                    <td>${record.dosen}</td>
                    <td>${record.hari}</td>
                    <td>${record.jam_mulai} - ${record.jam_selesai}</td>
                    <td>${record.ruangan}</td>
                    <td>${record.semester} ${record.tahun_akademik}</td>
                    <td>${statusCell}</td>
                `;
                
                if (!record.isValid) {
                    row.title = 'Errors: ' + record.errors.join(', ');
                }
                
                tableBody.appendChild(row);
            });
            
            previewSection.style.display = 'block';
            document.getElementById('importBtn').disabled = validRecords === 0;
        }

        // Import data
        function importData() {
            const validRecords = validatedData.filter(r => r.isValid);
            
            if (validRecords.length === 0) {
                alert('❌ Tidak ada data valid untuk diimpor!');
                return;
            }
            
            if (!confirm(`Import ${validRecords.length} jadwal perkuliahan ke database?`)) {
                return;
            }
            
            // Show loading
            document.getElementById('importBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
            document.getElementById('importBtn').disabled = true;
            
            // Send to server
            fetch('import_schedules.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    schedules: validRecords
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showResults(data);
                } else {
                    alert('❌ Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Terjadi kesalahan saat import');
            })
            .finally(() => {
                document.getElementById('importBtn').innerHTML = '<i class="fas fa-database me-2"></i>Import ke Database';
                document.getElementById('importBtn').disabled = false;
            });
        }

        // Show results
        function showResults(data) {
            const resultsDiv = document.getElementById('importResults');
            
            resultsDiv.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h4 class="text-success">${data.imported}</h4>
                                <p class="mb-0">Jadwal Imported</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-plus fa-3x text-info mb-3"></i>
                                <h4 class="text-info">${data.bookings_generated || 0}</h4>
                                <p class="mb-0">Booking Generated</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                <h4 class="text-warning">${data.errors || 0}</h4>
                                <p class="mb-0">Errors</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-success mt-4">
                    <h5 class="alert-heading">
                        <i class="fas fa-check-circle me-2"></i>Import Berhasil!
                    </h5>
                    <p class="mb-0">${data.message || 'Data jadwal perkuliahan berhasil diimpor ke database.'}</p>
                </div>
            `;
            
            document.getElementById('resultsSection').style.display = 'block';
        }

        // Utility functions
        function showProgress(show) {
            document.getElementById('progressContainer').style.display = show ? 'block' : 'none';
        }
        
        function updateProgress(percent, text) {
            document.getElementById('progressBar').style.width = percent + '%';
            document.getElementById('progressText').textContent = percent + '%';
            
            if (text) {
                // You can add progress text display if needed
            }
        }
        
        function resetPreview() {
            document.getElementById('previewSection').style.display = 'none';
            document.getElementById('resultsSection').style.display = 'none';
        }
        
        function resetImport() {
            removeFile();
            resetPreview();
            parsedData = [];
            validatedData = [];
        }
    </script>
</body>
</html>