<?php
// File: web_cron.php
// Web-based cron untuk shared hosting

// Check if called from web or command line
if (isset($_SERVER['HTTP_HOST'])) {
    // Called from web - check security token
    $token = $_GET['token'] ?? '';
    $valid_token = 'your-secret-token-here'; // Change this!
    
    if ($token !== $valid_token) {
        http_response_code(403);
        die('Unauthorized');
    }
}

require_once 'run_auto_processes.php';

// Return JSON response for web calls
if (isset($_SERVER['HTTP_HOST'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'completed', 'time' => date('Y-m-d H:i:s')]);
}
?>