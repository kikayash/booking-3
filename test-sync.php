<?php
// debug-error.php - Untuk debugging error login
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

echo "<h2>Debug Information</h2>";

// 1. Check PHP Version
echo "<h3>1. PHP Version</h3>";
echo "PHP Version: " . phpversion() . "<br>";

// 2. Check if required files exist
echo "<h3>2. Required Files Check</h3>";
$requiredFiles = ['config.php', 'functions.php', 'process-login-nik.php'];
foreach ($requiredFiles as $file) {
    if (file_exists($file)) {
        echo "✅ $file exists<br>";
    } else {
        echo "❌ $file NOT FOUND<br>";
    }
}

// 3. Test Database Connection
echo "<h3>3. Database Connection Test</h3>";
try {
    require_once 'config.php';
    echo "✅ config.php loaded successfully<br>";
    
    if (isset($conn) && $conn instanceof PDO) {
        echo "✅ Main database connection OK<br>";
        
        // Test query
        $stmt = $conn->query("SELECT 1");
        if ($stmt) {
            echo "✅ Database query test successful<br>";
        } else {
            echo "❌ Database query test failed<br>";
        }
    } else {
        echo "❌ Main database connection failed<br>";
    }
    
    if (isset($conn_dosen) && $conn_dosen instanceof PDO) {
        echo "✅ Dosen database connection OK<br>";
    } else {
        echo "⚠️ Dosen database not available (this is OK for fallback mode)<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}

// 4. Test Functions
echo "<h3>4. Functions Test</h3>";
try {
    require_once 'functions.php';
    echo "✅ functions.php loaded successfully<br>";
    
    if (function_exists('isLoggedIn')) {
        echo "✅ isLoggedIn() function exists<br>";
    } else {
        echo "❌ isLoggedIn() function missing<br>";
    }
    
    if (function_exists('isDosenDatabaseAvailable')) {
        echo "✅ isDosenDatabaseAvailable() function exists<br>";
    } else {
        echo "❌ isDosenDatabaseAvailable() function missing<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Functions error: " . $e->getMessage() . "<br>";
}

// 5. Check Session
echo "<h3>5. Session Test</h3>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "✅ Session is active<br>";
} else {
    session_start();
    echo "✅ Session started<br>";
}

// 6. Test POST to process-login-nik.php
echo "<h3>6. Login Process Test</h3>";
echo "Testing with dummy data...<br>";

// Simulate POST request
$_POST['nik'] = '203111111';
$_POST['password'] = '203111111';

try {
    // Capture output
    ob_start();
    include 'process-login-nik.php';
    $output = ob_get_clean();
    
    echo "✅ process-login-nik.php executed without fatal errors<br>";
    echo "Output: <pre>" . htmlspecialchars($output) . "</pre>";
    
} catch (Exception $e) {
    echo "❌ Error in process-login-nik.php: " . $e->getMessage() . "<br>";
} catch (ParseError $e) {
    echo "❌ Syntax error in process-login-nik.php: " . $e->getMessage() . "<br>";
} catch (Error $e) {
    echo "❌ Fatal error in process-login-nik.php: " . $e->getMessage() . "<br>";
}

// 7. Check permissions
echo "<h3>7. File Permissions</h3>";
$files = ['process-login-nik.php', 'config.php', 'functions.php'];
foreach ($files as $file) {
    if (file_exists($file)) {
        $perms = fileperms($file);
        echo "$file: " . substr(sprintf('%o', $perms), -4) . "<br>";
    }
}

// 8. Memory usage
echo "<h3>8. Memory Usage</h3>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Current Usage: " . memory_get_usage(true) / 1024 / 1024 . " MB<br>";

echo "<hr>";
echo "<p><strong>Check the above information and fix any ❌ errors first.</strong></p>";
?>