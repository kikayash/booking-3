<?php
header('Content-Type: application/json');

require_once 'config.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Room ID is required'
    ]);
    exit;
}

$roomId = intval($_GET['id']);

try {
    $stmt = $conn->prepare("
        SELECT r.*, g.nama_gedung 
        FROM tbl_ruang r 
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
        WHERE r.id_ruang = ?
    ");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($room) {
        echo json_encode([
            'success' => true,
            'room' => [
                'id_ruang' => $room['id_ruang'],
                'nama_ruang' => $room['nama_ruang'],
                'kapasitas' => $room['kapasitas'],
                'lokasi' => $room['lokasi'],
                'fasilitas' => $room['fasilitas'],
                'nama_gedung' => $room['nama_gedung'] ?: 'Unknown Building'
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Room not found'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>