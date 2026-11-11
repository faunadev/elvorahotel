<?php
/*
|--------------------------------------------------------------------------
| ELVORA HOTEL — admin.php (staff dashboard)
|--------------------------------------------------------------------------
| Tabs: Overview, Bookings, Calendar, Rooms, Pricing & promos, Guests, Reviews.
| Requires PHP 7.4+ (PHP 8.2+ recommended).
*/
declare(strict_types=1);

// A different session name from index.php, so customers and staff never share a session.
session_name('ELVORA_ADMIN');
session_set_cookie_params(['secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

require __DIR__ . '/lib.php';

/*
 | DEMO ADMIN LOGIN (portfolio simplification; do not use as-is in production).
 | Username: admin   Password: elvora123
 | Only the HASH is stored. Generate your own with:
 |   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
 | and set the ADMIN_USERNAME / ADMIN_PASSWORD_HASH environment variables.
 */
define('ADMIN_USERNAME', getenv('ADMIN_USERNAME') ?: 'admin');
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$k/ncuNcvdJDJyRezP6e0.OsPjKsUY79IGUHZt09feJblpDvh7TaQy');
define('SHOW_DEMO_HINT', !getenv('ADMIN_PASSWORD_HASH'));

$pdo = db();

function is_admin(): bool { return !empty($_SESSION['admin_logged_in']); }

function redirect_with(string $type, string $message, string $tab = 'overview'): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
    header('Location: admin.php#' . $tab);
    exit;
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }

/** A date field from a form: '' -> null, valid -> same string, invalid -> false. */
function opt_date(array $src, string $key)
{
    $v = input_str($src, $key);
    if ($v === '') {
        return null;
    }
    return parse_date($v) ? $v : false;
}

/** Add / edit form for one room ($r = [] for a new room). */
function room_form(array $r): string
{
    $id = (int) ($r['id'] ?? 0);
    ob_start(); ?>
    <form method="post" action="admin.php" class="form-grid">
        <input type="hidden" name="action" value="room_save"><input type="hidden" name="room_id" value="<?= $id ?>"><?= csrf_field() ?>
        <label>Room name<input name="room_name" maxlength="100" required value="<?= e($r['room_name'] ?? '') ?>"></label>
        <label>Price per night (₱)<input type="number" name="price" min="1" max="1000000" step="0.01" required value="<?= e($r['price'] ?? '') ?>"></label>
        <label>Max guests (1–6)<input type="number" name="capacity" min="1" max="6" required value="<?= e($r['capacity'] ?? 2) ?>"></label>
        <label>Beds<input name="beds" maxlength="100" required value="<?= e($r['beds'] ?? '') ?>" placeholder="1 king bed"></label>
        <label class="wide">Description<textarea name="description" rows="2" maxlength="1000" required><?= e($r['description'] ?? '') ?></textarea></label>
        <label class="wide">Photo URL <span class="muted">(optional; https://… or assets/your-file.jpg. Leave empty for the default photo)</span><input name="photo" maxlength="255" value="<?= e($r['photo'] ?? '') ?>"></label>
        <label class="inline-check"><input type="checkbox" name="is_suite" value="1" <?= !empty($r['is_suite']) ? 'checked' : '' ?>> Show under “Suites & residences”</label>
        <label class="inline-check"><input type="checkbox" name="active" value="1" <?= ($id === 0 || !empty($r['active'])) ? 'checked' : '' ?>> Visible and bookable</label>
        <button class="btn btn--solid btn--sm" type="submit"><?= $id ? 'Save room' : 'Add room' ?></button>
    </form>
    <?php return ob_get_clean();
}

/* ==========================================================================
   REQUEST HANDLING
   ========================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input_str($_POST, 'action');

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        redirect_with('error', 'Your session expired. Please try again.');
    }

    if ($action === 'login') {
        $now = time();
        if (($_SESSION['login_locked_until'] ?? 0) > $now) {
            redirect_with('error', 'Too many attempts. Try again in ' . ($_SESSION['login_locked_until'] - $now) . ' seconds.');
        }
        $userOk = hash_equals(ADMIN_USERNAME, input_str($_POST, 'username'));
        $passOk = password_verify(is_string($_POST['password'] ?? null) ? $_POST['password'] : '', ADMIN_PASSWORD_HASH);
        if ($userOk && $passOk) {
            session_regenerate_id(true);
            unset($_SESSION['csrf'], $_SESSION['login_fails'], $_SESSION['login_locked_until']);
            $_SESSION['admin_logged_in'] = true;
            header('Location: admin.php');
            exit;
        }
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            $_SESSION['login_fails'] = 0;
            $_SESSION['login_locked_until'] = $now + 60;
            redirect_with('error', 'Too many attempts. Try again in 60 seconds.');
        }
        redirect_with('error', 'Incorrect username or password.');
    }

    if (!is_admin()) {
        redirect_with('error', 'Please sign in first.');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
        header('Location: admin.php');
        exit;
    }

    try {
        switch ($action) {
            case 'confirm':
            case 'cancel':
            case 'delete':
                $id = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) {
                    redirect_with('error', 'That booking could not be found.', 'bookings');
                }
                $find = $pdo->prepare('SELECT booking_reference FROM bookings WHERE id = :id');
                $find->execute([':id' => $id]);
                $reference = $find->fetchColumn();
                if ($reference === false) {
                    redirect_with('error', 'That booking no longer exists.', 'bookings');
                }
                if ($action === 'confirm') {
                    // Only Pending can be confirmed: a Cancelled or Expired booking's dates may already be re-booked.
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'Confirmed' WHERE id = :id AND status = 'Pending'");
                    $stmt->execute([':id' => $id]);
                    $stmt->rowCount() === 1 ? redirect_with('success', $reference . ' is now confirmed.', 'bookings')
                                            : redirect_with('error', $reference . ' could not be confirmed because it is not pending.', 'bookings');
                }
                if ($action === 'cancel') {
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'Cancelled' WHERE id = :id AND status IN ('Pending', 'Confirmed')");
                    $stmt->execute([':id' => $id]);
                    $stmt->rowCount() === 1 ? redirect_with('success', $reference . ' was cancelled. The room is available again for those dates.', 'bookings')
                                            : redirect_with('error', $reference . ' is already cancelled.', 'bookings');
                }
                $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = :id AND status IN ('Cancelled', 'Expired')");
                $stmt->execute([':id' => $id]);
                $stmt->rowCount() ? redirect_with('success', $reference . ' was deleted.', 'bookings')
                                  : redirect_with('error', 'Cancel a reservation before deleting it.', 'bookings');

            case 'room_save':
                $id = (int) filter_var($_POST['room_id'] ?? 0, FILTER_VALIDATE_INT);
                $name = input_str($_POST, 'room_name');
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
                $cap = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
                $beds = input_str($_POST, 'beds');
                $desc = input_str($_POST, 'description');
                $photo = input_str($_POST, 'photo');
                if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || $price === false || $price <= 0 || $price > 1000000 || $cap === false || $cap < 1 || $cap > 6
                    || $beds === '' || mb_strlen($beds) > 100 || $desc === '' || mb_strlen($desc) > 1000) {
                    redirect_with('error', 'Check the room details: name, price, guests (1–6), beds and description are required.', 'rooms');
                }
                if ($photo !== '' && (strlen($photo) > 255 || !preg_match('#^(https?://\S+|assets/[A-Za-z0-9._/-]+)$#', $photo))) {
                    redirect_with('error', 'The photo must be an https:// link or a file in the assets folder, like assets/room-9.jpg.', 'rooms');
                }
                $vals = [$name, round((float) $price, 2), $cap, $beds, $desc, $photo === '' ? null : $photo, isset($_POST['is_suite']) ? 1 : 0, isset($_POST['active']) ? 1 : 0];
                if ($id > 0) {
                    $pdo->prepare('UPDATE rooms SET room_name=?, price=?, capacity=?, beds=?, description=?, photo=?, is_suite=?, active=? WHERE id=?')->execute([...$vals, $id]);
                    redirect_with('success', $name . ' was updated. Existing bookings keep the price they were made at.', 'rooms');
                }
                $pdo->prepare('INSERT INTO rooms (room_name, price, capacity, beds, description, photo, is_suite, active) VALUES (?,?,?,?,?,?,?,?)')->execute($vals);
                redirect_with('success', $name . ' was added.', 'rooms');

            case 'promo_add':
                $code = strtoupper(input_str($_POST, 'code'));
                $type = input_str($_POST, 'type') === 'fixed' ? 'fixed' : 'percent';
                $value = filter_var($_POST['value'] ?? null, FILTER_VALIDATE_FLOAT);
                $min = filter_var($_POST['min_nights'] ?? 1, FILTER_VALIDATE_INT);
                $from = opt_date($_POST, 'valid_from');
                $to = opt_date($_POST, 'valid_to');
                $maxRaw = input_str($_POST, 'max_uses');
                $max = $maxRaw === '' ? null : filter_var($maxRaw, FILTER_VALIDATE_INT);
                if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code) || $value === false || $value <= 0 || ($type === 'percent' && $value > 100)
                    || $min === false || $min < 1 || $min > 30 || $from === false || $to === false || ($from && $to && $to < $from) || $max === false || ($max !== null && $max < 1)) {
                    redirect_with('error', 'Check the promo: code (3–30 letters/numbers), a positive value (percent up to 100), valid dates and numbers.', 'pricing');
                }
                try {
                    $pdo->prepare('INSERT INTO promo_codes (code, type, value, min_nights, valid_from, valid_to, max_uses, active) VALUES (?,?,?,?,?,?,?,1)')->execute([$code, $type, $value, $min, $from, $to, $max]);
                } catch (PDOException $e) {
                    redirect_with('error', 'A promo code named ' . $code . ' already exists.', 'pricing');
                }
                redirect_with('success', 'Promo ' . $code . ' was added.', 'pricing');

            case 'promo_toggle':
                $pdo->prepare('UPDATE promo_codes SET active = 1 - active WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                redirect_with('success', 'Promo updated.', 'pricing');

            case 'promo_delete':
                $pdo->prepare('DELETE FROM promo_codes WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                redirect_with('success', 'Promo deleted. Past bookings keep their discount.', 'pricing');

            case 'season_add':
                $name = input_str($_POST, 'name');
                $from = opt_date($_POST, 'start_date');
                $to = opt_date($_POST, 'end_date');
                $pct = filter_var($_POST['adjust_percent'] ?? null, FILTER_VALIDATE_FLOAT);
                if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || !$from || !$to || $to < $from || $pct === false || $pct == 0 || $pct < -90 || $pct > 300) {
                    redirect_with('error', 'Check the season: a name, a start and end date (end not before start) and an adjustment between -90 and 300 percent.', 'pricing');
                }
                $pdo->prepare('INSERT INTO seasonal_rates (name, start_date, end_date, adjust_percent, active) VALUES (?,?,?,?,1)')->execute([$name, $from, $to, $pct]);
                redirect_with('success', 'Season “' . $name . '” was added.', 'pricing');

            case 'season_toggle':
                $pdo->prepare('UPDATE seasonal_rates SET active = 1 - active WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                redirect_with('success', 'Season updated.', 'pricing');

            case 'season_delete':
                $pdo->prepare('DELETE FROM seasonal_rates WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                redirect_with('success', 'Season deleted.', 'pricing');

            case 'settings_save':
                $weekend = filter_var($_POST['weekend'] ?? null, FILTER_VALIDATE_FLOAT);
                $hours = filter_var($_POST['expiry'] ?? null, FILTER_VALIDATE_INT);
                if ($weekend === false || $weekend < -90 || $weekend > 300 || $hours === false || $hours < 0 || $hours > 720) {
                    redirect_with('error', 'Weekend adjustment must be between -90 and 300 percent, and expiry between 0 and 720 hours.', 'pricing');
                }
                $up = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                $up->execute(['weekend_surcharge_percent', (string) $weekend]);
                $up->execute(['pending_expiry_hours', (string) $hours]);
                redirect_with('success', 'Settings saved.', 'pricing');

            case 'review_toggle':
                $pdo->prepare("UPDATE reviews SET status = IF(status = 'Visible', 'Hidden', 'Visible') WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
                redirect_with('success', 'Review updated.', 'reviews');

            case 'review_delete':
                $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                redirect_with('success', 'Review deleted.', 'reviews');
        }
    } catch (PDOException $e) {
        error_log('ELVORA admin action failed: ' . $e->getMessage());
        redirect_with('error', 'Something went wrong. ' . (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) ? $e->getMessage() : ''));
    }
    redirect_with('error', 'Unknown action.');
}

/* ==========================================================================
   DASHBOARD DATA
   ========================================================================== */

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

if (is_admin()) {
    try {
        expire_pending($pdo);
        $allRooms = get_rooms($pdo, false);
        $bookings = $pdo->query(
            'SELECT b.*, r.room_name FROM bookings b JOIN rooms r ON r.id = b.room_id ORDER BY b.created_at DESC, b.id DESC'
        )->fetchAll();
        $promos = $pdo->query('SELECT * FROM promo_codes ORDER BY id DESC')->fetchAll();
        $seasons = $pdo->query('SELECT * FROM seasonal_rates ORDER BY start_date DESC')->fetchAll();
        $reviews = $pdo->query('SELECT rv.*, r.room_name, u.full_name FROM reviews rv JOIN rooms r ON r.id = rv.room_id JOIN users u ON u.id = rv.user_id ORDER BY rv.created_at DESC')->fetchAll();
        $accounts = $pdo->query('SELECT u.id, u.full_name, u.email, u.phone, u.created_at, (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id) AS stays FROM users u ORDER BY u.created_at DESC')->fetchAll();
        $guestHistory = $pdo->query(
            "SELECT b.email, MAX(b.guest_name) AS name, MAX(b.phone) AS phone, COUNT(*) AS stays,
                    SUM(CASE WHEN b.status = 'Confirmed' THEN b.total_amount ELSE 0 END) AS spent, MAX(b.check_in) AS last_stay
             FROM bookings b GROUP BY b.email ORDER BY last_stay DESC"
        )->fetchAll();
        $weekendPct = setting($pdo, 'weekend_surcharge_percent', '0');
        $expiryHours = setting($pdo, 'pending_expiry_hours', '0');
    } catch (PDOException $e) {
        render_fatal('The hotel database is not set up or needs updating. Run setup.php (or import hotel.sql in phpMyAdmin), then reload this page.', $e);
    }

    if (isset($_GET['export'])) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="elvora-reservations.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference', 'Guest', 'Email', 'Phone', 'Room', 'Check-in', 'Check-out', 'Guests', 'Total PHP', 'Promo', 'Discount PHP', 'Status', 'Extras', 'Requests'], ',', '"', '');
        foreach ($bookings as $b) {
            $extras = implode('; ', array_column(json_decode((string) $b['addons_json'], true) ?: [], 'name'));
            $row = [$b['booking_reference'], $b['guest_name'], $b['email'], $b['phone'], $b['room_name'], $b['check_in'], $b['check_out'], $b['guests'], $b['total_amount'], $b['promo_code'], $b['discount_amount'], $b['status'], $extras, $b['special_requests']];
            fputcsv($out, array_map(function ($v) { $v = (string) $v; return preg_match('/^[\s]*[=+@-]/u', $v) ? "'" . $v : $v; }, $row), ',', '"', '');
        }
        fclose($out);
        exit;
    }

    /* ----- Overview numbers ----- */
    $stats = ['total' => count($bookings), 'Pending' => 0, 'Confirmed' => 0, 'Cancelled' => 0, 'Expired' => 0];
    $revenue = 0.0;
    $pendingValue = 0.0;
    $arrivals = 0;
    $inHouse = 0;
    $todayYmd = date('Y-m-d');
    $monthly = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthly[date('Y-m', strtotime("first day of -$i month"))] = 0.0;
    }
    $byRoom = [];
    $occDays = [];   // 'roomId|date' => true, for the next 30 days
    $horizon = [];
    for ($i = 0; $i < 30; $i++) {
        $horizon[date('Y-m-d', strtotime("+$i day"))] = true;
    }
    foreach ($bookings as $b) {
        $stats[$b['status']]++;
        $live = in_array($b['status'], ['Pending', 'Confirmed'], true);
        if ($b['status'] === 'Confirmed') {
            $revenue += (float) $b['total_amount'];
            $mk = substr($b['check_in'], 0, 7);
            if (isset($monthly[$mk])) {
                $monthly[$mk] += (float) $b['total_amount'];
            }
            $byRoom[$b['room_name']] = ($byRoom[$b['room_name']] ?? 0) + (float) $b['total_amount'];
            if ($b['check_in'] <= $todayYmd && $todayYmd < $b['check_out']) {
                $inHouse++;
            }
        }
        if ($b['status'] === 'Pending') {
            $pendingValue += (float) $b['total_amount'];
        }
        if ($live && $b['check_in'] === $todayYmd) {
            $arrivals++;
        }
        if ($live) {
            for ($d = new DateTimeImmutable($b['check_in']), $end = new DateTimeImmutable($b['check_out']); $d < $end; $d = $d->modify('+1 day')) {
                if (isset($horizon[$d->format('Y-m-d')])) {
                    $occDays[$b['room_id'] . '|' . $d->format('Y-m-d')] = true;
                }
            }
        }
    }
    arsort($byRoom);
    $activeCount = count(array_filter($allRooms, fn($r) => $r['active']));
    $occupancy = $activeCount ? round(100 * count($occDays) / ($activeCount * 30)) : 0;
    $maxMonth = max($monthly) ?: 1;

    /* ----- Calendar ----- */
    $monthParam = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $mStart = new DateTimeImmutable($monthParam . '-01');
    $mEnd = $mStart->modify('+1 month');
    $daysInMonth = (int) $mStart->format('t');
    $cal = [];
    foreach ($bookings as $b) {
        if (!in_array($b['status'], ['Pending', 'Confirmed'], true)) {
            continue;
        }
        $until = $b['occupied_until'] ?: $b['check_out'];
        for ($d = new DateTimeImmutable($b['check_in']), $end = new DateTimeImmutable($until); $d < $end; $d = $d->modify('+1 day')) {
            if ($d >= $mStart && $d < $mEnd) {
                $ymd = $d->format('Y-m-d');
                $cal[$b['room_id']][(int) $d->format('j')] = [$ymd >= $b['check_out'] ? 'h' : ($b['status'] === 'Confirmed' ? 'c' : 'p'), $b['booking_reference'] . ' · ' . $b['guest_name']];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin — ELVORA HOTEL</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>

<?php if (!is_admin()): ?>
    <main class="login">
        <div class="login__card">
            <p class="login__brand">ELVORA</p>
            <p class="login__sub">Admin sign in</p>
            <?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div><?php endif ?>
            <form method="post" action="admin.php">
                <input type="hidden" name="action" value="login"><?= csrf_field() ?>
                <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" autocomplete="username" required autofocus></div>
                <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" autocomplete="current-password" required></div>
                <button type="submit" class="btn btn--solid btn--block">Sign in</button>
            </form>
            <?php if (SHOW_DEMO_HINT): ?><p class="login__hint">Portfolio demo. Username <strong>admin</strong>, password <strong>elvora123</strong>.</p><?php endif ?>
            <a href="index.php" class="login__back">Back to ELVORA</a>
        </div>
    </main>

<?php else: ?>
    <header class="topbar">
        <div class="container">
            <div class="topbar__brand"><a href="admin.php" class="topbar__logo">ELVORA</a><span class="topbar__label">Admin</span></div>
            <div class="topbar__actions">
                <a href="index.php" target="_blank" rel="noopener">View website</a>
                <form method="post" action="admin.php"><input type="hidden" name="action" value="logout"><?= csrf_field() ?><button type="submit" class="btn btn--sm">Log out</button></form>
            </div>
        </div>
    </header>

    <main class="dashboard">
        <div class="container">
            <h1 class="display">Dashboard</h1>
            <?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif ?>

            <nav class="tabs" aria-label="Sections">
                <a href="#overview" data-tab="overview">Overview</a>
                <a href="#bookings" data-tab="bookings">Bookings (<?= (int) $stats['total'] ?>)</a>
                <a href="#calendar" data-tab="calendar">Calendar</a>
                <a href="#rooms" data-tab="rooms">Rooms</a>
                <a href="#pricing" data-tab="pricing">Pricing &amp; promos</a>
                <a href="#guests" data-tab="guests">Guests</a>
                <a href="#reviews" data-tab="reviews">Reviews (<?= count($reviews) ?>)</a>
            </nav>

            <!-- ================= OVERVIEW ================= -->
            <section data-panel="overview">
                <div class="cards">
                    <div class="card"><span>Confirmed revenue</span><strong><?= e(money($revenue)) ?></strong><small>From confirmed bookings</small></div>
                    <div class="card"><span>Awaiting confirmation</span><strong><?= (int) $stats['Pending'] ?></strong><small><?= e(money($pendingValue)) ?> pending value</small></div>
                    <div class="card"><span>Occupancy, next 30 days</span><strong><?= (int) $occupancy ?>%</strong><div class="meter"><i style="width:<?= (int) $occupancy ?>%"></i></div></div>
                    <div class="card"><span>Today</span><strong><?= (int) $arrivals ?> arriving</strong><small><?= (int) $inHouse ?> guest booking(s) in house</small></div>
                </div>
                <div class="cards">
                    <div class="card"><span>Total bookings</span><strong><?= (int) $stats['total'] ?></strong></div>
                    <div class="card"><span>Confirmed</span><strong><?= (int) $stats['Confirmed'] ?></strong></div>
                    <div class="card"><span>Cancelled / expired</span><strong><?= (int) $stats['Cancelled'] ?> / <?= (int) $stats['Expired'] ?></strong></div>
                    <div class="card"><span>Registered guests</span><strong><?= count($accounts) ?></strong></div>
                </div>
                <div class="two">
                    <div class="box"><h3>Confirmed revenue by arrival month</h3>
                        <div class="bars"><?php foreach ($monthly as $m => $v): ?><div class="bar-col"><b><?= $v > 0 ? e(money($v)) : '' ?></b><div class="bar" style="height:<?= (int) round(100 * $v / $maxMonth * 0.8) ?>%"></div><?= e(date('M', strtotime($m . '-01'))) ?></div><?php endforeach ?></div></div>
                    <div class="box"><h3>Revenue by room</h3>
                        <?php if (!$byRoom): ?><p class="hint">Confirm a booking to see revenue by room.</p><?php else: ?><table class="rank"><?php foreach (array_slice($byRoom, 0, 6, true) as $name => $v): ?><tr><td><?= e($name) ?></td><td class="num"><?= e(money($v)) ?></td></tr><?php endforeach ?></table><?php endif ?></div>
                </div>
            </section>

            <!-- ================= BOOKINGS ================= -->
            <section data-panel="bookings" class="panel" hidden>
                <div class="panel__head"><h2 class="display">All bookings</h2><a href="admin.php?export=1" class="btn btn--sm">Export CSV ↗</a></div>
                <div class="admin-filters"><label>Search reservations<input id="admin-search" placeholder="Reference, guest, email, room…" type="search"></label><label>Status<select id="admin-status"><option value="">All statuses</option><option>Pending</option><option>Confirmed</option><option>Cancelled</option><option>Expired</option></select></label><label>Check-in from<input type="date" id="admin-from"></label><label>Check-in through<input type="date" id="admin-to"></label></div>
                <p id="filter-count" aria-live="polite" class="filter-count"></p>
                <?php if (!$bookings): ?>
                    <div class="empty"><strong>No bookings yet</strong>New reservations made on the website will appear here.</div>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Reference</th><th>Guest</th><th>Room</th><th>Check-in</th><th>Check-out</th><th class="num">Guests</th><th class="num">Total</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($bookings as $b): $status = $b['status']; ?>
                            <tr data-booking data-status="<?= e($status) ?>" data-date="<?= e($b['check_in']) ?>">
                                <td data-label="Reference"><span class="ref"><?= e($b['booking_reference']) ?></span><span class="muted">Booked <?= e(short_date($b['created_at'])) ?></span></td>
                                <td data-label="Guest"><?= e($b['guest_name']) ?><?= $b['user_id'] ? ' <span class="pill">Account</span>' : '' ?><span class="muted"><?= e($b['email']) ?></span><span class="muted"><?= e($b['phone']) ?></span></td>
                                <td data-label="Room"><?= e($b['room_name']) ?><details class="booking-details"><summary>Stay details</summary><?php foreach (json_decode((string) $b['addons_json'], true) ?: [] as $extra): ?><span class="muted"><?= e($extra['name']) ?> · <?= e(money($extra['amount'])) ?></span><?php endforeach ?><p class="muted"><?= e($b['special_requests'] ?: 'No special requests.') ?></p></details></td>
                                <td data-label="Check-in" class="nowrap"><?= e(short_date($b['check_in'])) ?></td>
                                <td data-label="Check-out" class="nowrap"><?= e(short_date($b['check_out'])) ?><span class="muted"><?= (int) $b['total_nights'] ?> night<?= (int) $b['total_nights'] === 1 ? '' : 's' ?></span></td>
                                <td data-label="Guests" class="num"><?= (int) $b['guests'] ?></td>
                                <td data-label="Total" class="num nowrap"><?= e(money($b['total_amount'])) ?><?php if ((float) $b['discount_amount'] > 0): ?><span class="muted"><?= e($b['promo_code']) ?> −<?= e(money($b['discount_amount'])) ?></span><?php endif ?></td>
                                <td data-label="Status"><span class="badge badge--<?= e(strtolower($status)) ?>"><?= e($status) ?></span></td>
                                <td data-label="Action"><div class="actions">
                                    <?php if ($status === 'Pending'): ?><form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>"><button type="submit" class="btn btn--solid btn--sm">Confirm</button></form><?php endif ?>
                                    <?php if ($status === 'Pending' || $status === 'Confirmed'): ?><form method="post" action="admin.php" data-confirm="Cancel booking <?= e($b['booking_reference']) ?>? The room becomes available again for those dates."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>"><button type="submit" class="btn btn--outline btn--sm">Cancel</button></form><?php endif ?>
                                    <?php if ($status === 'Cancelled' || $status === 'Expired'): ?><form method="post" action="admin.php" data-confirm="Delete booking <?= e($b['booking_reference']) ?> permanently? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>"><button type="submit" class="btn btn--quiet btn--sm">Delete</button></form><?php endif ?>
                                </div></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody></table></div>
                <?php endif ?>
            </section>

            <!-- ================= CALENDAR ================= -->
            <section data-panel="calendar" class="panel" hidden>
                <div class="cal-nav">
                    <a class="btn btn--sm btn--outline" href="admin.php?month=<?= e($mStart->modify('-1 month')->format('Y-m')) ?>#calendar">← Previous</a>
                    <h2 class="display"><?= e($mStart->format('F Y')) ?></h2>
                    <a class="btn btn--sm btn--outline" href="admin.php?month=<?= e($mStart->modify('+1 month')->format('Y-m')) ?>#calendar">Next →</a>
                </div>
                <div class="legend"><span><i class="cal-c"></i>Confirmed</span><span><i class="cal-p"></i>Pending</span><span><i class="cal-h"></i>Late-checkout hold</span><span>Each column is a night.</span></div>
                <div class="cal-wrap"><table class="cal">
                    <thead><tr><th class="rn">Room</th><?php for ($d = 1; $d <= $daysInMonth; $d++): ?><th class="<?= in_array((int) $mStart->modify('+' . ($d - 1) . ' day')->format('N'), [6, 7], true) ? 'we' : '' ?>"><?= $d ?></th><?php endfor ?></tr></thead>
                    <tbody><?php foreach ($allRooms as $rid => $r): ?>
                        <tr><td class="rn"><?= e($r['room_name']) ?><?= $r['active'] ? '' : ' <span class="pill off">Hidden</span>' ?></td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): $c = $cal[$rid][$d] ?? null; ?><td class="<?= $c ? 'cal-' . $c[0] : '' ?>" <?= $c ? 'title="' . e($c[1]) . '"' : '' ?>></td><?php endfor ?></tr>
                    <?php endforeach ?></tbody></table></div>
            </section>

            <!-- ================= ROOMS ================= -->
            <section data-panel="rooms" hidden>
                <p class="hint">Changes apply to the website immediately. Hide a room instead of deleting it so past bookings stay intact. Changing a price does not change existing bookings.</p>
                <details class="room-row"><summary><strong>+ Add a new room</strong></summary><?= room_form([]) ?></details>
                <?php foreach ($allRooms as $r): ?>
                    <details class="room-row"><summary><span><strong><?= e($r['room_name']) ?></strong> · <?= e(money($r['price'])) ?> / night · up to <?= (int) $r['capacity'] ?> guests</span><span><?= $r['is_suite'] ? '<span class="pill">Suite</span> ' : '' ?><span class="pill <?= $r['active'] ? '' : 'off' ?>"><?= $r['active'] ? 'Visible' : 'Hidden' ?></span></span></summary><?= room_form($r) ?></details>
                <?php endforeach ?>
            </section>

            <!-- ================= PRICING & PROMOS ================= -->
            <section data-panel="pricing" hidden>
                <div class="box" style="margin-bottom:20px"><h3>Rate rules &amp; settings</h3>
                    <form method="post" action="admin.php" class="form-grid"><input type="hidden" name="action" value="settings_save"><?= csrf_field() ?>
                        <label>Weekend adjustment % (Friday &amp; Saturday nights)<input type="number" name="weekend" step="0.5" min="-90" max="300" value="<?= e($weekendPct) ?>"></label>
                        <label>Pending bookings expire after (hours, 0 = never)<input type="number" name="expiry" min="0" max="720" value="<?= e($expiryHours) ?>"></label>
                        <button class="btn btn--solid btn--sm" type="submit">Save settings</button></form></div>

                <div class="box" style="margin-bottom:20px"><h3>Seasonal rates</h3>
                    <p class="hint">An adjustment applied to every night in the date range (e.g. +25 for peak season, -15 for low season). Overlapping seasons use the larger adjustment. It stacks with the weekend adjustment.</p>
                    <form method="post" action="admin.php" class="form-grid"><input type="hidden" name="action" value="season_add"><?= csrf_field() ?>
                        <label>Name<input name="name" maxlength="80" required placeholder="Christmas peak"></label>
                        <label>From<input type="date" name="start_date" required></label><label>To (inclusive)<input type="date" name="end_date" required></label>
                        <label>Adjustment %<input type="number" name="adjust_percent" step="0.5" min="-90" max="300" required placeholder="25"></label>
                        <button class="btn btn--solid btn--sm" type="submit">Add season</button></form>
                    <?php if ($seasons): ?><table class="tbl" style="margin-top:18px"><thead><tr><th>Season</th><th>Dates</th><th>Adjustment</th><th>Status</th><th></th></tr></thead><tbody>
                        <?php foreach ($seasons as $s): ?><tr><td data-label="Season"><?= e($s['name']) ?></td><td data-label="Dates"><?= e(short_date($s['start_date'])) ?> – <?= e(short_date($s['end_date'])) ?></td><td data-label="Adjustment"><?= $s['adjust_percent'] > 0 ? '+' : '' ?><?= e(rtrim(rtrim((string) $s['adjust_percent'], '0'), '.')) ?>%</td><td data-label="Status"><span class="pill <?= $s['active'] ? '' : 'off' ?>"><?= $s['active'] ? 'Active' : 'Off' ?></span></td>
                            <td><div class="actions"><form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="season_toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn--outline btn--sm"><?= $s['active'] ? 'Turn off' : 'Turn on' ?></button></form><form method="post" action="admin.php" data-confirm="Delete this season?"><?= csrf_field() ?><input type="hidden" name="action" value="season_delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn--quiet btn--sm">Delete</button></form></div></td></tr><?php endforeach ?></tbody></table><?php endif ?></div>

                <div class="box"><h3>Promo codes</h3>
                    <p class="hint">Discounts apply to the room charge only, not to extras. “Max uses” counts pending and confirmed bookings.</p>
                    <form method="post" action="admin.php" class="form-grid"><input type="hidden" name="action" value="promo_add"><?= csrf_field() ?>
                        <label>Code<input name="code" maxlength="30" required placeholder="SUMMER15" style="text-transform:uppercase"></label>
                        <label>Type<select name="type"><option value="percent">Percent off</option><option value="fixed">Fixed ₱ off</option></select></label>
                        <label>Value<input type="number" name="value" step="0.01" min="0.01" required></label>
                        <label>Min nights<input type="number" name="min_nights" min="1" max="30" value="1"></label>
                        <label>Valid from<input type="date" name="valid_from"></label><label>Valid to<input type="date" name="valid_to"></label>
                        <label>Max uses<input type="number" name="max_uses" min="1" placeholder="unlimited"></label>
                        <button class="btn btn--solid btn--sm" type="submit">Add promo</button></form>
                    <?php if ($promos): ?><table class="tbl" style="margin-top:18px"><thead><tr><th>Code</th><th>Discount</th><th>Rules</th><th>Status</th><th></th></tr></thead><tbody>
                        <?php foreach ($promos as $p): ?><tr><td data-label="Code"><span class="ref"><?= e($p['code']) ?></span></td><td data-label="Discount"><?= $p['type'] === 'percent' ? e(rtrim(rtrim((string) $p['value'], '0'), '.')) . '%' : e(money($p['value'])) ?> off</td>
                            <td data-label="Rules">Min <?= (int) $p['min_nights'] ?> night(s)<?= $p['valid_from'] || $p['valid_to'] ? ' · ' . e($p['valid_from'] ?: '…') . ' to ' . e($p['valid_to'] ?: '…') : '' ?><?= $p['max_uses'] !== null ? ' · max ' . (int) $p['max_uses'] : '' ?></td>
                            <td data-label="Status"><span class="pill <?= $p['active'] ? '' : 'off' ?>"><?= $p['active'] ? 'Active' : 'Off' ?></span></td>
                            <td><div class="actions"><form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="promo_toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn--outline btn--sm"><?= $p['active'] ? 'Turn off' : 'Turn on' ?></button></form><form method="post" action="admin.php" data-confirm="Delete promo <?= e($p['code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="promo_delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn--quiet btn--sm">Delete</button></form></div></td></tr><?php endforeach ?></tbody></table><?php endif ?></div>
            </section>

            <!-- ================= GUESTS ================= -->
            <section data-panel="guests" hidden>
                <div class="box" style="margin-bottom:20px"><h3>Registered accounts (<?= count($accounts) ?>)</h3>
                    <?php if (!$accounts): ?><p class="hint">No one has created an account yet.</p><?php else: ?><table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Joined</th><th>Stays</th></tr></thead><tbody>
                        <?php foreach ($accounts as $u): ?><tr><td data-label="Name"><?= e($u['full_name']) ?></td><td data-label="Email"><?= e($u['email']) ?></td><td data-label="Phone"><?= e($u['phone'] ?: '—') ?></td><td data-label="Joined"><?= e(short_date($u['created_at'])) ?></td><td data-label="Stays"><?= (int) $u['stays'] ?></td></tr><?php endforeach ?></tbody></table><?php endif ?></div>
                <div class="box"><h3>Guest history (everyone who has booked, by email)</h3>
                    <?php if (!$guestHistory): ?><p class="hint">No bookings yet.</p><?php else: ?><table class="tbl"><thead><tr><th>Guest</th><th>Email</th><th>Phone</th><th>Bookings</th><th>Confirmed spend</th><th>Last arrival</th></tr></thead><tbody>
                        <?php foreach ($guestHistory as $g): ?><tr><td data-label="Guest"><?= e($g['name']) ?></td><td data-label="Email"><?= e($g['email']) ?></td><td data-label="Phone"><?= e($g['phone']) ?></td><td data-label="Bookings"><?= (int) $g['stays'] ?></td><td data-label="Spend"><?= e(money($g['spent'])) ?></td><td data-label="Last arrival"><?= e(short_date($g['last_stay'])) ?></td></tr><?php endforeach ?></tbody></table><?php endif ?></div>
            </section>

            <!-- ================= REVIEWS ================= -->
            <section data-panel="reviews" hidden>
                <p class="hint">Hidden reviews disappear from the website but are kept here.</p>
                <?php if (!$reviews): ?><div class="box"><p class="hint" style="margin:0">No reviews yet. Guests can review a confirmed stay once it has finished.</p></div><?php endif ?>
                <?php foreach ($reviews as $rv): ?>
                    <div class="box" style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><span class="stars"><?= str_repeat('★', (int) $rv['rating']) . str_repeat('☆', 5 - (int) $rv['rating']) ?></span> <strong><?= e($rv['room_name']) ?></strong> <span class="muted" style="display:inline">· <?= e($rv['full_name']) ?> · <?= e(short_date($rv['created_at'])) ?></span> <span class="pill <?= $rv['status'] === 'Visible' ? '' : 'off' ?>"><?= e($rv['status']) ?></span></div>
                        <div class="actions"><form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="review_toggle"><input type="hidden" name="id" value="<?= (int) $rv['id'] ?>"><button class="btn btn--outline btn--sm"><?= $rv['status'] === 'Visible' ? 'Hide' : 'Show' ?></button></form><form method="post" action="admin.php" data-confirm="Delete this review permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="review_delete"><input type="hidden" name="id" value="<?= (int) $rv['id'] ?>"><button class="btn btn--quiet btn--sm">Delete</button></form></div></div>
                        <p style="margin:10px 0 0;font-size:14px"><?= e($rv['comment']) ?></p></div>
                <?php endforeach ?>
            </section>
        </div>
    </main>

    <script>
    // Tabs: only the chosen section is shown.
    const tabLinks = [...document.querySelectorAll('[data-tab]')], panels = [...document.querySelectorAll('[data-panel]')];
    function showTab() {
        let h = location.hash.replace('#', ''); if (!panels.some(p => p.dataset.panel === h)) h = 'overview';
        panels.forEach(p => { p.hidden = p.dataset.panel !== h; });
        tabLinks.forEach(a => a.dataset.tab === h ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'));
    }
    window.addEventListener('hashchange', showTab); showTab();

    function filterBookings(){
        const text=document.getElementById('admin-search').value.toLowerCase(),status=document.getElementById('admin-status').value,from=document.getElementById('admin-from').value,to=document.getElementById('admin-to').value;
        let shown=0;document.querySelectorAll('[data-booking]').forEach(row=>{row.hidden=!(row.textContent.toLowerCase().includes(text)&&(!status||row.dataset.status===status)&&(!from||row.dataset.date>=from)&&(!to||row.dataset.date<=to));if(!row.hidden)shown++;});
        document.getElementById('filter-count').textContent=shown+' reservation(s) shown';
    }
    ['admin-search','admin-status','admin-from','admin-to'].forEach(id=>document.getElementById(id).addEventListener('input',filterBookings));filterBookings();
    // Ask before cancelling / deleting (convenience only: the server checks every action).
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) { if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault(); });
    });
    </script>
<?php endif ?>
</body>
</html>
