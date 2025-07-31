<?php
// File: debug_login.php
// Gunakan file ini untuk debug masalah login

require_once 'config.php';
require_once 'functions.php';

echo "<h2>Debug Login System</h2>";

// 1. Test koneksi database
echo "<h3>1. Test Koneksi Database</h3>";
try {
    $stmt = $conn->prepare("SELECT 1");
    $stmt->execute();
    echo "✅ Koneksi database berhasil<br>";
} catch (Exception $e) {
    echo "❌ Koneksi database gagal: " . $e->getMessage() . "<br>";
    exit;
}

// 2. Cek data user di database
echo "<h3>2. Data User di Database</h3>";
try {
    $stmt = $conn->prepare("SELECT * FROM tbl_users");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>ID</th><th>Email</th><th>Password</th><th>Role</th></tr>";
    
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . $user['id_user'] . "</td>";
        echo "<td>" . $user['email'] . "</td>";
        echo "<td>" . $user['password'] . "</td>";
        echo "<td>" . $user['role'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "❌ Error mengambil data user: " . $e->getMessage() . "<br>";
}

// 3. Test login manual
echo "<h3>3. Test Login Manual</h3>";
echo "<form method='POST'>";
echo "Email: <input type='email' name='test_email' required><br><br>";
echo "Password: <input type='text' name='test_password' required><br><br>";
echo "<button type='submit' name='test_login'>Test Login</button>";
echo "</form>";

if (isset($_POST['test_login'])) {
    $email = $_POST['test_email'];
    $password = $_POST['test_password'];
    
    echo "<h4>Hasil Test Login:</h4>";
    echo "Email yang diinput: " . $email . "<br>";
    echo "Password yang diinput: " . $password . "<br>";
    echo "Password sebagai integer: " . intval($password) . "<br><br>";
    
    // Cari user
    $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ User ditemukan<br>";
        echo "Email dari DB: " . $user['email'] . "<br>";
        echo "Password dari DB: " . $user['password'] . "<br>";
        echo "Password dari DB (type): " . gettype($user['password']) . "<br>";
        echo "Role dari DB: " . $user['role'] . "<br><br>";
        
        // Test password matching
        $inputPasswordInt = intval($password);
        $dbPassword = $user['password'];
        
        echo "Perbandingan Password:<br>";
        echo "Input password (int): " . $inputPasswordInt . "<br>";
        echo "DB password: " . $dbPassword . "<br>";
        echo "Tipe data DB password: " . gettype($dbPassword) . "<br>";
        
        if ($inputPasswordInt == $dbPassword) {
            echo "✅ Password COCOK!<br>";
        } else {
            echo "❌ Password TIDAK COCOK!<br>";
        }
        
        // Test strict comparison
        if ($inputPasswordInt === intval($dbPassword)) {
            echo "✅ Password COCOK (strict)!<br>";
        } else {
            echo "❌ Password TIDAK COCOK (strict)!<br>";
        }
        
    } else {
        echo "❌ User tidak ditemukan<br>";
    }
}

// 4. Test session
echo "<h3>4. Test Session</h3>";
session_start();
echo "Session ID: " . session_id() . "<br>";
echo "Session data: <pre>" . print_r($_SESSION, true) . "</pre>";

// 5. Test file functions.php
echo "<h3>5. Test Functions</h3>";
if (file_exists('functions.php')) {
    echo "✅ File functions.php ada<br>";
    require_once 'functions.php';
    
    if (function_exists('isLoggedIn')) {
        echo "✅ Function isLoggedIn() tersedia<br>";
        echo "isLoggedIn() result: " . (isLoggedIn() ? 'true' : 'false') . "<br>";
    } else {
        echo "❌ Function isLoggedIn() tidak tersedia<br>";
    }
} else {
    echo "❌ File functions.php tidak ada<br>";
}
?>