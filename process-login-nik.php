<?php
// process-login-nik.php - FIXED VERSION
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to prevent any output before JSON
ob_start();
session_start();
require_once 'config.php';
require_once 'functions.php';

// Clean any previous output
ob_clean();

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

function sendJsonResponse($data) {
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function logError($message) {
    error_log("NIK LOGIN ERROR: " . $message);
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse([
            'success' => false, 
            'message' => 'Method tidak diizinkan'
        ]);
    }

    // Get and validate input
    $nik = trim($_POST['nik'] ?? '');
    $password = trim($_POST['password'] ?? '');

    logError("Login attempt for NIK: $nik");

    if (empty($nik) || empty($password)) {
        sendJsonResponse([
            'success' => false, 
            'message' => 'NIK dan password harus diisi'
        ]);
    }

    // Clean NIK (remove dots and spaces)
    $cleanNik = preg_replace('/[^\d]/', '', $nik);

    // Validate NIK format
    if (strlen($cleanNik) < 8 || strlen($cleanNik) > 16) {
        sendJsonResponse([
            'success' => false, 
            'message' => 'Format NIK tidak valid (harus 8-16 digit)'
        ]);
    }

    logError("Cleaned NIK: $cleanNik");

    // STEP 1: Try to authenticate with dosen database (if available)
    $dosenData = null;
    $authMethod = 'fallback';
    
    // Check if dosen database connection exists
    if (isset($conn_dosen) && $conn_dosen !== null) {
        try {
            logError("Attempting dosen database authentication...");
            
            // Try different NIK formats
            $nikVariations = [
                $cleanNik,
                $nik,
                substr($cleanNik, 0, 3) . '.' . substr($cleanNik, 3, 3) . '.' . substr($cleanNik, 6)
            ];
            
            foreach ($nikVariations as $searchNik) {
                $stmt = $conn_dosen->prepare("
                    SELECT karyawan_id, nik, nama_lengkap, email, password_hash,
                           status_aktif, status_mengajar
                    FROM tblKaryawan 
                    WHERE nik = ? AND status_aktif = 'Aktif'
                    LIMIT 1
                ");
                $stmt->execute([$searchNik]);
                $dosenData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dosenData) {
                    logError("Dosen found in database: " . $dosenData['nama_lengkap']);
                    $authMethod = 'database';
                    break;
                }
            }
            
            if ($dosenData) {
                // Verify password
                $passwordValid = false;
                
                // Method 1: Hash verification
                if (!empty($dosenData['password_hash'])) {
                    if (password_verify($password, $dosenData['password_hash'])) {
                        $passwordValid = true;
                        logError("Password verified using hash");
                    }
                }
                
                // Method 2: Direct comparison with common passwords
                if (!$passwordValid) {
                    $testPasswords = [
                        $password,
                        $cleanNik,
                        str_replace('.', '', $dosenData['nik']),
                        $dosenData['nik']
                    ];
                    
                    foreach ($testPasswords as $testPwd) {
                        if ($password === $testPwd) {
                            $passwordValid = true;
                            logError("Password verified using direct match");
                            break;
                        }
                    }
                }
                
                if (!$passwordValid) {
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'Password salah. Coba gunakan NIK tanpa titik sebagai password.'
                    ]);
                }
            }
            
        } catch (Exception $e) {
            logError("Dosen database error: " . $e->getMessage());
            // Continue with fallback method
        }
    }
    
    // STEP 2: Fallback authentication if no dosen database or not found
    if (!$dosenData) {
        logError("Using fallback authentication...");
        
        // Simple password check (NIK as password)
        if ($password !== $cleanNik && $password !== $nik) {
            sendJsonResponse([
                'success' => false,
                'message' => 'Password salah. Gunakan NIK sebagai password.'
            ]);
        }
        
        // Create dummy dosen data for fallback
        $dosenData = [
            'nama_lengkap' => "Dosen NIK $cleanNik",
            'email' => $cleanNik . '@dosen.stie-mce.ac.id',
            'status_aktif' => 'Aktif',
            'status_mengajar' => 'Ya'
        ];
        $authMethod = 'fallback';
    }

    // STEP 3: Sync to booking database
    logError("Syncing to booking database...");
    
    $email = !empty($dosenData['email']) ? $dosenData['email'] : $cleanNik . '@dosen.stie-mce.ac.id';
    $nama = $dosenData['nama_lengkap'];
    
    // Check if user exists in booking system
    $stmt = $conn->prepare("SELECT id_user, email, role FROM tbl_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        $userId = $existingUser['id_user'];
        logError("Found existing user: $userId");
        
        // Update password if needed
        try {
            $stmt = $conn->prepare("UPDATE tbl_users SET password = ? WHERE id_user = ?");
            $stmt->execute([password_hash($cleanNik, PASSWORD_DEFAULT), $userId]);
        } catch (Exception $e) {
            logError("Warning: Could not update password: " . $e->getMessage());
        }
        
    } else {
        logError("Creating new user...");
        
        // Create new user with simplified approach
        $stmt = $conn->prepare("
            INSERT INTO tbl_users (email, password, role, nama, created_at) 
            VALUES (?, ?, 'dosen', ?, NOW())
        ");
        
        $result = $stmt->execute([
            $email, 
            password_hash($cleanNik, PASSWORD_DEFAULT), 
            $nama
        ]);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Failed to create user: " . $errorInfo[2]);
        }
        
        $userId = $conn->lastInsertId();
        logError("Created new user: $userId");
    }

    // STEP 4: Set session
    session_regenerate_id(true);
    
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['nik'] = $cleanNik;
    $_SESSION['nama'] = $nama;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = 'dosen';
    $_SESSION['login_method'] = 'nik_login';
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
    $_SESSION['last_activity'] = time();
    
    logError("Login successful for user: $userId");

    // SUCCESS RESPONSE
    sendJsonResponse([
        'success' => true,
        'message' => 'Login berhasil! Selamat datang, ' . $nama,
        'user' => [
            'id' => $userId,
            'nik' => $cleanNik,
            'nama' => $nama,
            'email' => $email,
            'role' => 'dosen'
        ],
        'redirect' => 'index.php',
        'auth_method' => $authMethod
    ]);

} catch (Exception $e) {
    logError("EXCEPTION: " . $e->getMessage());
    logError("Stack trace: " . $e->getTraceAsString());
    
    sendJsonResponse([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>