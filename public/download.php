<?php
/** Sends a launcher build to a signed-in player. */

declare(strict_types=1);

require_once __DIR__ . '/../src/app.php';

start_session();
session_write_close(); // don't hold the session lock for the whole download

$id = (int) ($_SESSION['account_id'] ?? 0);
if ($id === 0 || account_banned($id)) {
    header('Location: ./', true, 303);
    exit;
}

$d = downloads()[(string) ($_GET['platform'] ?? '')] ?? null;
if ($d === null || $d['path'] === null) {
    http_response_code(404);
    echo 'Not available yet.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($d['path']) . '"');
header('Content-Length: ' . $d['size']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
while (ob_get_level()) {
    ob_end_clean();
}
readfile($d['path']);
