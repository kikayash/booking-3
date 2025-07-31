
<?php
$host = 'localhost';
$db = 'booking';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Database Dosen (Server Terpisah)
$host_dosen = '10.9.3.2';
$dbname_dosen = 'dbIRIS';
$username_dosen = 'kikan';
$password_dosen = '1nt3rn5h1p';

try {
    // Koneksi Database Booking
    $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", 
                    $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Koneksi Database Dosen
    $conn_dosen = new PDO("mysql:host=$host_dosen;dbname=$dbname_dosen;charset=utf8mb4", 
                          $username_dosen, $password_dosen);
    $conn_dosen->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    error_log("✅ Database connections established successfully");
    
} catch(PDOException $e) {
    error_log("❌ Database connection failed: " . $e->getMessage());
    die("Database connection failed");
}

// Site configuration
$config = [
    'site_name' => 'Sistem Peminjaman Ruangan STIE MCE',
    'admin_email' => 'admin@stie-mce.ac.id',
    'max_booking_hours' => 4, // Maximum hours for a single booking
    'min_booking_hours' => 0.5, // Minimum hours for a single booking
    'booking_start_hour' => 7, // Earliest booking time (24h format)
    'booking_end_hour' => 17, // Latest booking time (24h format)
    'auto_checkout_minutes' => 15, // Auto checkout after booking end time + minutes
];

// Time settings
date_default_timezone_set('Asia/Jakarta');
?>