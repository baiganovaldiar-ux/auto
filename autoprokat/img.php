<?php
// Отдаёт фото авто: сначала из кэша img/, при первом обращении скачивает из Wikimedia Commons
require __DIR__ . '/src/db.php';
require __DIR__ . '/src/photos.php';
$s = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['s'] ?? '')));
$dir = __DIR__ . '/img'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
$file = "$dir/$s.jpg";
if ($s !== '' && !is_file($file) && !is_file("$dir/$s.miss")) {
    foreach ($pdo->query('SELECT brand, model FROM cars')->fetchAll() as $c) {
        if (photo_slug($c['brand'] . ' ' . $c['model']) === $s) { @set_time_limit(60); photo_fetch($c['brand'], $c['model'], $dir); break; }
    }
}
if ($s !== '' && is_file($file)) {
    header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=604800');
    readfile($file); exit;
}
http_response_code(404);
