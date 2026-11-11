<?php
// One-click database setup / repair for XAMPP. Open http://localhost/elvora/setup.php
// Runs hotel.sql (safe to repeat; never deletes reservations), then reports what it found.
// DELETE THIS FILE before public hosting.
declare(strict_types=1);
require __DIR__ . '/config.php';
header('Content-Type: text/html; charset=UTF-8');
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Setup can only be run from localhost.'); }
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ok = false; $msg = ''; $notes = []; $roomList = [];
try {
    if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME)) throw new RuntimeException('DB_NAME may only contain letters, numbers and underscores.');
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = str_replace('elvora_hotel', DB_NAME, file_get_contents(__DIR__ . '/hotel.sql'));
    $sql = preg_replace('/^[ \t]*--.*$/m', '', $sql);
    $n = 0;
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try { $pdo->exec($stmt); $n++; }
        catch (PDOException $e) { throw new RuntimeException($e->getMessage() . '  —  while running: ' . mb_substr((string) preg_replace('/\s+/', ' ', $stmt), 0, 180)); }
    }
    $pdo->exec('USE `' . DB_NAME . '`');

    // Repairs
    $pdo->exec('UPDATE rooms SET active = 1 WHERE active IS NULL');
    $total = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
    $visible = (int) $pdo->query('SELECT COUNT(*) FROM rooms WHERE active = 1')->fetchColumn();
    if ($total > 0 && $visible === 0) {
        $pdo->exec('UPDATE rooms SET active = 1');
        $notes[] = 'Every room was hidden, so all rooms were made visible again.';
    }
    $roomList = $pdo->query('SELECT id, room_name, price, active FROM rooms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $bookings = (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    $ok = true;
    $msg = "Done: $n statements ran on database “" . DB_NAME . "” (host " . DB_HOST . "). Rooms: " . count($roomList) . ". Existing bookings kept: $bookings.";
} catch (Throwable $e) { $msg = $e->getMessage(); }
?><!DOCTYPE html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ELVORA setup</title>
<body style="font-family:Georgia,serif;max-width:640px;margin:10vh auto;padding:0 1.5rem;line-height:1.6">
<h1 style="letter-spacing:.3em;font-weight:400">ELVORA</h1>
<p><strong><?= $ok ? 'Database ready.' : 'Setup failed.' ?></strong></p><p><?= $h($msg) ?></p>
<?php foreach ($notes as $note): ?><p><?= $h($note) ?></p><?php endforeach ?>
<?php if ($ok): ?>
  <table style="border-collapse:collapse;font-size:.9em;width:100%"><?php foreach ($roomList as $r): ?><tr style="border-bottom:1px solid #ddd"><td style="padding:4px 8px"><?= (int) $r['id'] ?></td><td><?= $h($r['room_name']) ?></td><td>₱<?= $h(number_format((float) $r['price'])) ?></td><td><?= $r['active'] ? 'visible' : 'hidden' ?></td></tr><?php endforeach ?></table>
  <p><a href="index.php">Open the website</a> · <a href="admin.php">Staff dashboard</a></p><p style="color:#777;font-size:.9em">You can delete setup.php now.</p>
<?php else: ?><p>Make sure MySQL is running in the XAMPP Control Panel, then reload. If it still fails, send the message above to your developer.</p><?php endif ?>
</body></html>
