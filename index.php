<?php
/*
|--------------------------------------------------------------------------
| ELVORA HOTEL — index.php (customer side)
|--------------------------------------------------------------------------
| Views (Home, Rooms, Extras, Reserve, My reservation, Account) are switched
| in the browser by assets/app.js, so the nav never scrolls one long page.
| Requires PHP 7.4+ (PHP 8.2+ recommended).
*/
declare(strict_types=1);

session_name('ELVORA_SITE');
session_set_cookie_params(['secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

require __DIR__ . '/lib.php';

$pdo = db();
try {
    expire_pending($pdo);
    if (room_counts($pdo)[0] === 0) {   // empty rooms table (e.g. an interrupted import): restore the starter rooms
        seed_default_rooms($pdo);
    }
    $rooms = get_rooms($pdo);
    $pricing = load_pricing($pdo);
    $ratings = room_ratings($pdo);
} catch (PDOException $e) {
    render_fatal('The hotel database is not set up or needs updating. Run setup.php (or import hotel.sql in phpMyAdmin), then reload.', $e);
}

$user = !empty($_SESSION['uid']) ? find_user($pdo, (int) $_SESSION['uid']) : null;
if (!$user) {
    unset($_SESSION['uid']);
}
$today = date('Y-m-d');

/* ---------- Small helpers ---------- */

function go(string $view, string $type = '', string $message = ''): void
{
    if ($message !== '') {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
    header('Location: index.php#' . $view);
    exit;
}

function json_out(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function lookup_throttled(): bool
{
    $now = time();
    if (($_SESSION['lookup_reset'] ?? 0) < $now) {
        $_SESSION['lookup_count'] = 0;
        $_SESSION['lookup_reset'] = $now + 300;
    }
    if (($_SESSION['lookup_count'] ?? 0) >= 15) {
        return true;
    }
    $_SESSION['lookup_count'] = ($_SESSION['lookup_count'] ?? 0) + 1;
    return false;
}

function booking_by_ref(PDO $pdo, string $ref, string $email): ?array
{
    $q = $pdo->prepare('SELECT b.*, r.room_name FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.booking_reference = ? AND b.email = ?');
    $q->execute([strtoupper($ref), $email]);
    return $q->fetch() ?: null;
}

function booking_for_user(PDO $pdo, int $id, int $uid): ?array
{
    $q = $pdo->prepare('SELECT b.*, r.room_name FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = ? AND b.user_id = ?');
    $q->execute([$id, $uid]);
    return $q->fetch() ?: null;
}

function cancel_booking_row(PDO $pdo, int $id, string $today): bool
{
    $c = $pdo->prepare("UPDATE bookings SET status = 'Cancelled' WHERE id = ? AND status IN ('Pending','Confirmed') AND check_in > ?");
    $c->execute([$id, $today]);
    return $c->rowCount() > 0;
}

/** One reservation as a card, with cancel / change-dates / review forms. mode = 'account' or 'lookup'. */
function booking_card(array $b, array $o): string
{
    $mode = $o['mode'];
    $action = 'index.php#' . ($mode === 'account' ? 'account' : 'manage');
    $ids = $mode === 'account'
        ? '<input type="hidden" name="booking_id" value="' . (int) $b['id'] . '">'
        : '<input type="hidden" name="reference" value="' . e($b['booking_reference']) . '"><input type="hidden" name="lookup_email" value="' . e($b['email']) . '">';
    $csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    $changeable = in_array($b['status'], ['Pending', 'Confirmed'], true) && $b['check_in'] > $o['today'];
    ob_start(); ?>
    <div class="receipt booking-card" role="group">
        <strong><?= e($b['booking_reference']) ?> · <span class="status status-<?= e(strtolower($b['status'])) ?>"><?= e($b['status']) ?></span></strong>
        <span><?= e($b['room_name']) ?></span>
        <span><?= e(pretty_date($b['check_in'])) ?> → <?= e(pretty_date($b['check_out'])) ?> · <?= (int) $b['guests'] ?> guest(s) · <?= (int) $b['total_nights'] ?> night(s)</span>
        <?php foreach (json_decode((string) $b['addons_json'], true) ?: [] as $a): ?><span><?= e($a['name']) ?> — <?= e(money($a['amount'])) ?></span><?php endforeach ?>
        <?php if ((float) $b['discount_amount'] > 0): ?><span>Promo <?= e($b['promo_code']) ?> — −<?= e(money($b['discount_amount'])) ?></span><?php endif ?>
        <strong><?= e(money($b['total_amount'])) ?></strong>
        <?php if ($b['status'] === 'Expired'): ?><span class="muted">This request expired because it was not confirmed in time. You are welcome to book again.</span><?php endif ?>
        <?php if ($changeable): ?>
            <details class="change-box"><summary>Change dates or guests</summary>
                <form method="post" action="<?= $action ?>" class="change-form">
                    <input type="hidden" name="action" value="modify_booking"><?= $csrf . $ids ?>
                    <label>Check in<input type="date" name="new_check_in" min="<?= e($o['today']) ?>" value="<?= e($b['check_in']) ?>" required></label>
                    <label>Check out<input type="date" name="new_check_out" min="<?= e($o['today']) ?>" value="<?= e($b['check_out']) ?>" required></label>
                    <label>Guests<input type="number" name="new_guests" min="1" max="6" value="<?= (int) $b['guests'] ?>" required></label>
                    <button class="button dark small">Save changes</button>
                    <p class="field-hint">Availability and price are re-checked. A changed booking returns to Pending until the hotel reconfirms it.</p>
                </form>
            </details>
            <form method="post" action="<?= $action ?>" onsubmit="return confirm('Cancel this reservation? Your dates will be released.');">
                <input type="hidden" name="action" value="cancel_booking"><?= $csrf . $ids ?>
                <button class="button outline small">Cancel reservation</button>
            </form>
        <?php endif ?>
        <?php if (!empty($o['canReview'])): ?>
            <details class="change-box"><summary>Rate your stay</summary>
                <form method="post" action="index.php#account" class="change-form">
                    <input type="hidden" name="action" value="review"><?= $csrf ?><input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                    <label>Rating<select name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?></option><?php endfor ?></select></label>
                    <label class="full">Your review<textarea name="comment" rows="3" maxlength="600" required placeholder="What made your stay memorable?"></textarea></label>
                    <button class="button dark small">Post review</button>
                </form>
            </details>
        <?php endif ?>
    </div>
    <?php return ob_get_clean();
}

/* ---------- JSON / API requests ---------- */

if (($_GET['api'] ?? '') === 'reviews') {
    $rid = (int) ($_GET['room'] ?? 0);
    $q = $pdo->prepare("SELECT r.rating, r.comment, r.created_at, u.full_name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.room_id = ? AND r.status = 'Visible' ORDER BY r.created_at DESC LIMIT 50");
    $q->execute([$rid]);
    $list = [];
    foreach ($q->fetchAll() as $row) {
        $list[] = ['name' => public_name($row['full_name']), 'rating' => (int) $row['rating'], 'comment' => $row['comment'], 'date' => short_date($row['created_at'])];
    }
    $rt = $ratings[$rid] ?? null;
    json_out(['reviews' => $list, 'avg' => $rt ? round((float) $rt['avg_rating'], 1) : null, 'count' => $rt ? (int) $rt['total'] : 0]);
}

/* ---------- Form submissions ---------- */

$lookup = null;
$lookupMsg = ['type' => '', 'text' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input_str($_POST, 'action');
    $isJson = in_array($action, ['toggle_save', 'check_promo'], true);

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        if ($isJson) {
            json_out(['ok' => false, 'message' => 'Your session expired. Please reload the page.']);
        }
        if (in_array($action, ['lookup', 'cancel_booking', 'modify_booking'], true) && !isset($_POST['booking_id'])) {
            $lookupMsg = ['type' => 'error', 'text' => 'Session expired. Please try again.'];
            $action = '';
        } else {
            go($action === 'book' ? 'reserve' : 'account', 'error', 'Your session expired. Please try again.');
        }
    }

    switch ($action) {
        case 'book':
            $result = process_booking($pdo, $rooms, $_POST, $pricing, $user ? (int) $user['id'] : null);
            if ($result['booking'] !== null) {
                $_SESSION['confirmation'] = $result['booking'];
                header('Location: index.php?confirmed=1#reserve');
            } else {
                $_SESSION['flash_errors'] = $result['errors'];
                $_SESSION['flash_old'] = [];
                foreach (['full_name', 'email', 'phone', 'room_id', 'check_in', 'check_out', 'guests', 'requests', 'promo'] as $key) {
                    $_SESSION['flash_old'][$key] = input_str($_POST, $key);
                }
                $_SESSION['flash_old']['addons'] = is_array($_POST['addons'] ?? null) ? $_POST['addons'] : [];
                header('Location: index.php#reserve');
            }
            exit;

        case 'register':
            [$errs, $uid] = register_user($pdo, $_POST);
            if ($errs) {
                $_SESSION['auth_old'] = ['full_name' => input_str($_POST, 'full_name'), 'email' => input_str($_POST, 'email'), 'phone' => input_str($_POST, 'phone')];
                go('account', 'error', implode(' ', $errs));
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            go('account', 'success', 'Welcome to ELVORA. Your account is ready.');

        case 'login':
            $now = time();
            if (($_SESSION['login_locked_until'] ?? 0) > $now) {
                go('account', 'error', 'Too many attempts. Try again in ' . ($_SESSION['login_locked_until'] - $now) . ' seconds.');
            }
            $email = strtolower(input_str($_POST, 'email'));
            $pass = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $q = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
            $q->execute([$email]);
            $row = $q->fetch();
            // Always run password_verify so the response time does not reveal whether the email exists.
            $ok = password_verify($pass, $row['password_hash'] ?? '$2y$10$k/ncuNcvdJDJyRezP6e0.OsPjKsUY79IGUHZt09feJblpDvh7TaQy') && $row;
            if ($ok) {
                session_regenerate_id(true);
                unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
                $_SESSION['uid'] = (int) $row['id'];
                go('account', 'success', 'Welcome back.');
            }
            $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
            if ($_SESSION['login_fails'] >= 5) {
                $_SESSION['login_fails'] = 0;
                $_SESSION['login_locked_until'] = $now + 60;
                go('account', 'error', 'Too many attempts. Try again in 60 seconds.');
            }
            go('account', 'error', 'Incorrect email or password.');

        case 'logout':
            unset($_SESSION['uid']);
            session_regenerate_id(true);
            go('home', 'success', 'You have been logged out.');

        case 'profile':
            $user || go('account', 'error', 'Please log in first.');
            $name = input_str($_POST, 'full_name');
            $phone = input_str($_POST, 'phone');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                go('account', 'error', 'Enter your full name (2 to 100 characters).');
            }
            if ($phone !== '' && !preg_match('/^[0-9+\-()\s]{7,20}$/', $phone)) {
                go('account', 'error', 'Phone: use 7 to 20 digits (you can include +, spaces, dashes and brackets).');
            }
            $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $user['id']]);
            go('account', 'success', 'Your profile was updated.');

        case 'password':
            $user || go('account', 'error', 'Please log in first.');
            $q = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $q->execute([$user['id']]);
            $hash = (string) $q->fetchColumn();
            $cur = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
            $new = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (!password_verify($cur, $hash)) {
                go('account', 'error', 'Your current password is not correct.');
            }
            if (strlen($new) < 8 || strlen($new) > 72 || $new !== ($_POST['password2'] ?? null)) {
                go('account', 'error', 'Choose a new password of 8 to 72 characters, entered twice the same.');
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            go('account', 'success', 'Your password was changed.');

        case 'lookup':
            if (lookup_throttled()) {
                $lookupMsg = ['type' => 'error', 'text' => 'Too many attempts. Please try again in a few minutes.'];
                break;
            }
            $lookup = booking_by_ref($pdo, input_str($_POST, 'reference'), input_str($_POST, 'lookup_email'));
            if (!$lookup) {
                $lookupMsg = ['type' => 'error', 'text' => 'No matching reservation. Check your reference and email.'];
            }
            break;

        case 'cancel_booking':
        case 'modify_booking':
            $fromAccount = isset($_POST['booking_id']);
            if ($fromAccount) {
                $b = $user ? booking_for_user($pdo, (int) $_POST['booking_id'], (int) $user['id']) : null;
            } elseif (lookup_throttled()) {
                $lookupMsg = ['type' => 'error', 'text' => 'Too many attempts. Please try again in a few minutes.'];
                break;
            } else {
                $b = booking_by_ref($pdo, input_str($_POST, 'reference'), input_str($_POST, 'lookup_email'));
            }
            if (!$b) {
                $fromAccount ? go('account', 'error', 'Reservation not found.') : ($lookupMsg = ['type' => 'error', 'text' => 'No matching reservation.']);
                break;
            }
            if ($action === 'cancel_booking') {
                $done = cancel_booking_row($pdo, (int) $b['id'], $today);
                $res = ['error' => $done ? '' : 'This reservation cannot be cancelled online. Please contact reception.', 'notice' => $done ? $b['booking_reference'] . ' was cancelled. Your dates were released.' : ''];
            } else {
                $res = modify_booking($pdo, $b, $_POST, $pricing);
            }
            if ($fromAccount) {
                go('account', $res['error'] ? 'error' : 'success', $res['error'] ?: $res['notice']);
            }
            $lookupMsg = $res['error'] ? ['type' => 'error', 'text' => $res['error']] : ['type' => 'success', 'text' => $res['notice']];
            $lookup = booking_by_ref($pdo, $b['booking_reference'], $b['email']);
            break;

        case 'review':
            $user || go('account', 'error', 'Please log in first.');
            $b = booking_for_user($pdo, (int) ($_POST['booking_id'] ?? 0), (int) $user['id']);
            $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
            $comment = input_str($_POST, 'comment');
            if (!$b || $b['status'] !== 'Confirmed' || $b['check_out'] > $today) {
                go('account', 'error', 'You can review a confirmed stay once it has finished.');
            }
            if ($rating === false || $rating < 1 || $rating > 5 || $comment === '' || mb_strlen($comment) > 600) {
                go('account', 'error', 'Choose a rating and write a short review (up to 600 characters).');
            }
            try {
                $pdo->prepare('INSERT INTO reviews (booking_id, room_id, user_id, rating, comment) VALUES (?,?,?,?,?)')
                    ->execute([$b['id'], $b['room_id'], $user['id'], $rating, $comment]);
            } catch (PDOException $e) {
                go('account', 'error', 'You have already reviewed that stay.');
            }
            go('account', 'success', 'Thank you. Your review is now live.');

        case 'toggle_save':
            if (!$user) {
                json_out(['ok' => false, 'message' => 'Log in to save rooms to your account.']);
            }
            $rid = (int) ($_POST['room_id'] ?? 0);
            if (!isset($rooms[$rid])) {
                json_out(['ok' => false, 'message' => 'Unknown room.']);
            }
            $has = $pdo->prepare('SELECT COUNT(*) FROM saved_rooms WHERE user_id = ? AND room_id = ?');
            $has->execute([$user['id'], $rid]);
            if ((int) $has->fetchColumn() > 0) {
                $pdo->prepare('DELETE FROM saved_rooms WHERE user_id = ? AND room_id = ?')->execute([$user['id'], $rid]);
                json_out(['ok' => true, 'saved' => false]);
            }
            $pdo->prepare('INSERT INTO saved_rooms (user_id, room_id) VALUES (?, ?)')->execute([$user['id'], $rid]);
            json_out(['ok' => true, 'saved' => true]);

        case 'check_promo':
            $in = parse_date(input_str($_POST, 'check_in'));
            $out = parse_date(input_str($_POST, 'check_out'));
            $nights = ($in && $out && $out > $in) ? (int) $in->diff($out)->days : 0;
            $found = find_promo($pdo, input_str($_POST, 'promo'), max(1, $nights));
            if (!$found['promo']) {
                json_out(['ok' => false, 'message' => $found['error'] ?: 'Enter a promo code.']);
            }
            $p = $found['promo'];
            json_out(['ok' => true, 'code' => $p['code'], 'type' => $p['type'], 'value' => (float) $p['value'], 'min' => (int) $p['min_nights'],
                'message' => 'Promo ' . $p['code'] . ' applied: ' . ($p['type'] === 'percent' ? rtrim(rtrim((string) $p['value'], '0'), '.') . '% off the room charge' : money($p['value']) . ' off the room charge') . '.']);
    }
}

/* ---------- Page state ---------- */

$confirmation = null;
if (isset($_GET['confirmed']) && !empty($_SESSION['confirmation'])) {
    $confirmation = $_SESSION['confirmation'];
} else {
    unset($_SESSION['confirmation']);
}
$errors = $_SESSION['flash_errors'] ?? [];
$old = $_SESSION['flash_old'] ?? [];
$flash = $_SESSION['flash'] ?? null;
$authOld = $_SESSION['auth_old'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_old'], $_SESSION['flash'], $_SESSION['auth_old']);

if ($user) {   // prefill the booking form from the account
    $old += ['full_name' => $user['full_name'], 'email' => $user['email'], 'phone' => $user['phone']];
}

$searchIn = input_str($_GET, 'arrival');
$searchOut = input_str($_GET, 'departure');
$searchGuests = max(1, min(6, (int) ($_GET['guests'] ?? 1)));
$searchError = '';
$available = [];
$hasSearch = isset($_GET['arrival']);
if ($hasSearch) {
    $a = parse_date($searchIn);
    $d = parse_date($searchOut);
    if (!$a || !$d || $searchIn < $today || $d <= $a || $a->diff($d)->days > MAX_NIGHTS) {
        $searchError = 'Choose valid dates from today, for a stay of 1–30 nights.';
    } else {
        foreach ($rooms as $id => $r) {
            $available[$id] = $r['capacity'] >= $searchGuests && room_is_available($pdo, (int) $id, $searchIn, $searchOut);
        }
    }
}

$myBookings = [];
$reviewedIds = [];
$savedIds = [];
if ($user) {
    $q = $pdo->prepare('SELECT b.*, r.room_name FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.user_id = ? ORDER BY b.check_in DESC, b.id DESC');
    $q->execute([$user['id']]);
    $myBookings = $q->fetchAll();
    $q = $pdo->prepare('SELECT booking_id FROM reviews WHERE user_id = ?');
    $q->execute([$user['id']]);
    $reviewedIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $q = $pdo->prepare('SELECT room_id FROM saved_rooms WHERE user_id = ?');
    $q->execute([$user['id']]);
    $savedIds = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
}

$elv = [
    'weekend' => $pricing['weekend'],
    'seasons' => array_map(fn($s) => ['s' => $s['start_date'], 'e' => $s['end_date'], 'p' => (float) $s['adjust_percent']], $pricing['seasons']),
    'loggedIn' => (bool) $user,
    'saved' => $savedIds,
    'csrf' => csrf_token(),
];
$hasDynamicPricing = $pricing['weekend'] != 0 || $pricing['seasons'];
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$searchbar = function () use ($today, $tomorrow, $searchIn, $searchOut, $searchGuests): void { ?>
    <form class="searchbar" method="get" action="index.php#rooms">
        <label>CHECK IN<input type="date" name="arrival" min="<?= $today ?>" value="<?= e($searchIn ?: $today) ?>" required></label>
        <label>CHECK OUT<input type="date" name="departure" min="<?= $tomorrow ?>" value="<?= e($searchOut ?: $tomorrow) ?>" required></label>
        <label>GUESTS<select name="guests"><?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>" <?= $searchGuests === $i ? 'selected' : '' ?>><?= $i ?> guest<?= $i > 1 ? 's' : '' ?></option><?php endfor ?></select></label>
        <button class="button dark">Check availability ↗</button>
    </form>
<?php };
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Find your quiet escape at Elvora. Explore rooms, personalize your stay and reserve directly."><title>ELVORA — A little further from ordinary</title><link rel="stylesheet" href="assets/style.css"><script>window.ELV = <?= json_encode($elv, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script><script src="assets/app.js" defer></script></head>
<body>
<header class="nav">
    <a class="brand" href="#home">ELVORA<span>HOTEL & RESIDENCES</span></a>
    <nav aria-label="Main navigation">
        <a href="#home" data-nav="home">Home</a>
        <a href="#rooms" data-nav="rooms">Our rooms</a>
        <a href="#extras" data-nav="extras">The little extras</a>
        <a href="#manage" data-nav="manage">My reservation</a>
        <a href="#account" data-nav="account"><?= $user ? 'Hi, ' . e(explode(' ', $user['full_name'])[0]) : 'Log in / Sign up' ?></a>
    </nav>
    <a class="button dark small" href="#reserve" data-nav="reserve">Reserve a stay ↗</a>
</header>
<main>
<?php if ($flash): ?><div class="wrap"><p class="alert flash <?= $flash['type'] === 'error' ? 'error' : '' ?>" role="status"><?= e($flash['message']) ?></p></div><?php endif ?>

<!-- ============ HOME ============ -->
<div data-view="home">
<section class="hero"><img src="<?= e(room_photo($rooms[1] ?? ['id' => 1], 2000)) ?>" alt="Warmly lit hotel bedroom with a spacious bed and comfortable seating" fetchpriority="high"><div class="hero-shade"></div><div class="hero-copy"><p class="eyebrow">A QUIETER KIND OF LUXURY</p><h1>A little further<br>from <em>ordinary.</em></h1><p>Space to switch off. Details to fall in love with.<br>Make yourself at home, somewhere special.</p><a href="#rooms" class="text-link">Find your kind of stay <span>↘</span></a></div><span class="hero-note">THOUGHTFULLY DESIGNED. BEAUTIFULLY UNHURRIED.</span></section>
<?php $searchbar() ?>
<div class="perks"><span>◈ &nbsp; Book directly, stay simply</span><span>⌁ &nbsp; Complimentary Wi-Fi</span><span>◷ &nbsp; Check-in 2 PM · Checkout noon</span></div>
<section class="wrap faq"><p class="eyebrow">A FEW GOOD THINGS TO KNOW</p><h2>Before you <em>arrive.</em></h2>
<details><summary>When can I check in and out?</summary><p>Check-in begins at 2 PM and checkout is at noon. Add late checkout for a guaranteed 2 PM departure. Late checkout blocks this room for the rest of the departure date to protect your stay.</p></details>
<details><summary>How does payment work?</summary><p>Your request is sent to the hotel for confirmation. Payment is handled at the property. This website does not collect cards or process online payments.</p></details>
<details><summary>Can I change or cancel my reservation?</summary><p>Yes. Open <a href="#manage"><u>My reservation</u></a> (or your account) to change dates and guests or to cancel before your arrival date. A changed booking returns to Pending until the hotel reconfirms it. For same-day changes, contact reception.</p></details>
<details><summary>Do I need an account?</summary><p>No. You can book as a guest and look your booking up with its reference and email. An account keeps all your stays in one place, saves your favourite rooms, and lets you review a stay.</p></details>
<details><summary>What is included in my room?</summary><p>All listed rooms include Wi-Fi, air conditioning and an ensuite bathroom. Breakfast and other extras are optional and itemized in your reservation total.</p></details>
<details><summary>Do weekends or holidays cost more?</summary><p>Rates can include a weekend surcharge (Friday and Saturday nights) and seasonal adjustments. Your total always shows the exact price for your dates before you reserve.</p></details></section>
</div>

<!-- ============ ROOMS ============ -->
<div data-view="rooms" hidden>
<?php $searchbar() ?>
<section class="wrap rooms-section" id="rooms"><div class="section-heading"><div><p class="eyebrow">FIND YOUR OWN LITTLE ESCAPE</p><h2>Good days begin<br>with a <em>great stay.</em></h2></div><p>From a weekend for two to a gathering of your favorite people. Find a space that feels like you.</p></div>
<?php if ($searchError): ?><p class="alert error" role="alert"><?= e($searchError) ?></p><?php elseif ($hasSearch): ?><p class="alert"><?= count(array_filter($available)) ?> rooms available · <?= e($searchIn) ?> to <?= e($searchOut) ?> · <?= $searchGuests ?> guests. Availability is checked again when you reserve. <a href="index.php#rooms">Clear dates</a></p><?php endif ?>
<div class="room-toolbar"><div class="chips" role="group" aria-label="Filter rooms"><button class="chip active" type="button" data-filter="all" aria-pressed="true">All rooms</button><button class="chip" type="button" data-filter="couples" aria-pressed="false">For two</button><button class="chip" type="button" data-filter="family" aria-pressed="false">Family & friends</button><button class="chip" type="button" data-filter="suite" aria-pressed="false">Suites & residences</button><button class="chip" type="button" data-filter="saved" aria-pressed="false">♡ Saved</button></div><label class="sort-label">Sort <select id="sort"><option value="low">Price: low to high</option><option value="high">Price: high to low</option><option value="capacity">Most guests</option><option value="rating">Best rated</option></select></label></div>
<div class="room-grid" id="room-grid"><?php foreach ($rooms as $id => $r): $isAvailable = !$hasSearch || $searchError || ($available[$id] ?? false); $rt = $ratings[$id] ?? null; ?>
<article class="room-card" data-id="<?= $id ?>" data-price="<?= e($r['price']) ?>" data-capacity="<?= (int) $r['capacity'] ?>" data-suite="<?= $r['is_suite'] ? '1' : '0' ?>" data-rating="<?= $rt ? e(round((float) $rt['avg_rating'], 2)) : '0' ?>" data-available="<?= $isAvailable ? '1' : '0' ?>"><div class="room-image"><img src="<?= e(room_photo($r)) ?>" alt="Illustrative interior for <?= e($r['room_name']) ?>" loading="lazy"><span class="image-tag"><?= $r['is_suite'] ? 'THE SUITE COLLECTION' : 'REST, BEAUTIFULLY' ?></span><button class="heart" type="button" data-save="<?= $id ?>" aria-label="Save <?= e($r['room_name']) ?>" aria-pressed="false">♡</button></div><div class="room-content"><div class="room-meta">UP TO <?= (int) $r['capacity'] ?> GUESTS <span>·</span> <?= e($r['beds']) ?></div><h3><?= e($r['room_name']) ?></h3>
<button type="button" class="rating-btn" data-reviews="<?= $id ?>" data-name="<?= e($r['room_name']) ?>"><?= $rt ? '★ ' . e(number_format((float) $rt['avg_rating'], 1)) . ' · ' . (int) $rt['total'] . ' review' . ((int) $rt['total'] === 1 ? '' : 's') : 'No reviews yet' ?></button>
<p><?= e($r['description']) ?></p><div class="amenities">Wi-Fi &nbsp; / &nbsp; Air conditioning &nbsp; / &nbsp; Ensuite</div><div class="room-bottom"><div><strong><?= e(money($r['price'])) ?></strong><span> / night<?= $hasDynamicPricing ? ' · base rate' : '' ?></span></div><button type="button" class="room-book" data-book="<?= $id ?>" <?= !$isAvailable ? 'disabled' : '' ?>><?= $isAvailable ? 'Choose room ↗' : 'Unavailable' ?></button></div></div></article><?php endforeach ?></div>
<?php if (!$rooms): ?><p class="empty">No rooms are available to book right now.<?php if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)): [$rt, $rv] = room_counts($pdo); ?><br><small>Diagnostic (localhost only): database “<?= e(DB_NAME) ?>” has <?= $rt ?> room(s), <?= $rv ?> visible. Open <a href="setup.php"><u>setup.php</u></a> to repair, or turn rooms on in Admin → Rooms.</small><?php endif ?></p><?php endif ?>
<p id="empty-rooms" class="empty" hidden>No rooms match this filter. Try another category or save a room with the heart button.</p>
<p class="photo-note">Room photography is illustrative, sourced online from Unsplash. Layouts and furnishings may vary.<?= $user ? '' : ' Log in to keep saved rooms on your account.' ?></p></section>
</div>

<!-- ============ EXTRAS ============ -->
<div data-view="extras" hidden>
<section class="extras-section" id="extras"><div class="wrap"><div class="section-heading"><div><p class="eyebrow">MAKE IT A LITTLE MORE YOU</p><h2>It's the little <em>extras.</em></h2></div><p>A slow breakfast. A thoughtful surprise.<br>Personalize your stay when you reserve.</p></div><div class="extras-grid"><?php foreach (addon_catalog() as $a): ?><div class="extra"><span class="extra-icon"><?= $a['icon'] ?></span><h3><?= e($a['name']) ?></h3><p><?= e($a['description']) ?></p><strong><?= e(money($a['price'])) ?> <small>/ <?= e($a['unit']) ?></small></strong></div><?php endforeach ?></div><p style="margin-top:36px"><a class="button dark" href="#reserve">Reserve a stay ↗</a></p></div></section>
</div>

<!-- ============ RESERVE ============ -->
<div data-view="reserve" hidden>
<?php if ($confirmation): ?><section class="confirmation wrap" role="status"><p class="eyebrow">YOUR NEXT CHAPTER STARTS HERE</p><h2>You're on the guest list.</h2><p>Your reservation request is <strong>Pending</strong>. The hotel will review it; no payment has been collected.</p><div class="receipt"><strong><?= e($confirmation['reference']) ?></strong><span><?= e($confirmation['guest']) ?> · <?= e($confirmation['room']) ?></span><span><?= e(pretty_date($confirmation['check_in'])) ?> → <?= e(pretty_date($confirmation['check_out'])) ?></span><span><?= (int) $confirmation['guests'] ?> guests · <?= (int) $confirmation['nights'] ?> nights</span><?php foreach ($confirmation['addons'] ?? [] as $extra): ?><span><?= e($extra['name']) ?> — <?= e(money($extra['amount'])) ?></span><?php endforeach ?><?php if (!empty($confirmation['discount'])): ?><span>Promo <?= e($confirmation['promo']) ?> — −<?= e(money($confirmation['discount'])) ?></span><?php endif ?><strong>Total: <?= e(money($confirmation['total'])) ?></strong></div><p><?= $user ? 'This stay is saved in your account under My reservation.' : 'Save your reference and use your email to check the status under My reservation.' ?></p><button class="button outline" type="button" onclick="window.print()">Print confirmation</button></section><?php endif ?>
<section class="wrap reserve-section" id="reserve"><div class="section-heading"><div><p class="eyebrow">SOMETHING TO LOOK FORWARD TO</p><h2>Your stay,<br><em>your way.</em></h2></div><p>Choose your dates, add a little something, and leave the everyday behind.</p></div>
<?php if ($errors): ?><div class="alert error" role="alert"><strong>Please check your reservation.</strong><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach ?></div><?php endif ?>
<form method="post" action="index.php" id="booking-form"><input type="hidden" name="action" value="book"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="booking-layout"><div><div class="form-panel"><h3><span class="step">01</span> The essentials</h3><div class="fields"><label class="full">Your room<select name="room_id" id="room-select" required><option value="">Choose your room</option><?php foreach ($rooms as $id => $r): ?><option value="<?= $id ?>" data-rate="<?= e($r['price']) ?>" data-capacity="<?= (int) $r['capacity'] ?>" <?= ($old['room_id'] ?? '') == (string) $id ? 'selected' : '' ?>><?= e($r['room_name']) ?> — <?= e(money($r['price'])) ?>/night</option><?php endforeach ?></select></label><label>Check in<input type="date" id="book-in" name="check_in" min="<?= $today ?>" value="<?= e($old['check_in'] ?? ($searchIn ?: $today)) ?>" required></label><label>Check out<input type="date" id="book-out" name="check_out" min="<?= $tomorrow ?>" value="<?= e($old['check_out'] ?? ($searchOut ?: $tomorrow)) ?>" required></label><label>Guests<input type="number" name="guests" id="book-guests" min="1" max="6" value="<?= e($old['guests'] ?? $searchGuests) ?>" required></label><div class="field-hint" id="capacity-hint">Choose a room to see its guest capacity.</div></div></div>
<div class="form-panel"><h3><span class="step">02</span> Make it special</h3><div class="addon-list"><?php foreach (addon_catalog() as $key => $a): ?><label class="addon"><input type="checkbox" name="addons[]" value="<?= e($key) ?>" data-price="<?= $a['price'] ?>" data-unit="<?= $a['unit'] ?>" <?= in_array($key, $old['addons'] ?? [], true) ? 'checked' : '' ?>><span><strong><?= e($a['name']) ?></strong><small><?= e(money($a['price'])) ?> / <?= e($a['unit']) ?></small></span></label><?php endforeach ?></div>
<div class="promo-row"><label>Promo code<input name="promo" id="promo-input" maxlength="30" value="<?= e($old['promo'] ?? '') ?>" placeholder="e.g. WELCOME10" autocomplete="off"></label><button class="button outline small" type="button" id="promo-apply">Apply</button></div><p class="field-hint" id="promo-msg" role="status"></p></div>
<div class="form-panel"><h3><span class="step">03</span> A few details about you</h3><?php if (!$user): ?><p class="field-hint" style="margin:-8px 0 16px">Have an account? <a href="#account"><u>Log in</u></a> to fill this in automatically and keep the booking on your account.</p><?php endif ?><div class="fields"><label class="full">Full name<input name="full_name" autocomplete="name" minlength="2" maxlength="100" value="<?= e($old['full_name'] ?? '') ?>" placeholder="As it appears on your ID" required></label><label>Email address<input type="email" name="email" autocomplete="email" maxlength="150" value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com" required></label><label>Phone number<input type="tel" name="phone" autocomplete="tel" maxlength="20" value="<?= e($old['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" required></label><label class="full">Special requests <span class="muted">(optional)</span><textarea name="requests" maxlength="1000" rows="3" placeholder="Arrival time, accessibility needs, or something to celebrate…"><?= e($old['requests'] ?? '') ?></textarea></label></div><p class="field-hint">Special requests are subject to availability. Please do not enter card details.</p></div></div>
<aside class="summary"><p class="eyebrow">YOUR LITTLE ESCAPE</p><h3 id="summary-room">A stay to look forward to</h3><p id="summary-dates">Choose your room and dates</p><div class="summary-row"><span id="night-label">Room subtotal</span><strong id="room-subtotal">₱0</strong></div><div id="rate-note" class="field-hint" hidden></div><div id="extra-lines"></div><div id="discount-line" class="summary-row" hidden><span>Promo discount</span><strong id="discount-amount">−₱0</strong></div><div class="summary-row total"><span>Total stay</span><strong id="grand-total">₱0</strong></div><p class="field-hint">Displayed rates are inclusive. No additional booking fee. Pay at the property; no online payment is collected.</p><button class="button dark" id="reserve-button">Request reservation ↗</button><p class="field-hint">Reservations start as pending until confirmed by the hotel. You can change or cancel before arrival under My reservation.</p><div class="summary-footer">◈ &nbsp; Your next good memory starts here.</div></aside></div></form></section>
</div>

<!-- ============ MY RESERVATION (guest lookup) ============ -->
<div data-view="manage" hidden>
<section class="manage-section" id="manage"><div class="wrap manage-layout"><div><p class="eyebrow">ALREADY LOOKING FORWARD TO IT?</p><h2>Find your <em>reservation.</em></h2><p>Check your booking details and current confirmation status. Booked with an account? <a href="#account"><u>Your stays are listed there</u></a>.</p></div><div><form method="post" action="index.php#manage" class="lookup-form"><input type="hidden" name="action" value="lookup"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Booking reference<input name="reference" placeholder="ELV-XXXXXXXX" maxlength="30" required></label><label>Booking email<input type="email" name="lookup_email" maxlength="150" placeholder="you@example.com" required></label><button class="button dark">Find reservation ↗</button></form>
<?php if ($lookupMsg['text']): ?><p class="alert <?= $lookupMsg['type'] === 'error' ? 'error' : '' ?>" role="alert"><?= e($lookupMsg['text']) ?></p><?php endif ?>
<?php if ($lookup): echo booking_card($lookup, ['mode' => 'lookup', 'today' => $today]); endif ?></div></div></section>
</div>

<!-- ============ ACCOUNT ============ -->
<div data-view="account" hidden>
<section class="wrap account-section" id="account">
<?php if (!$user): ?>
    <div class="section-heading"><div><p class="eyebrow">YOUR ELVORA ACCOUNT</p><h2>Welcome <em>in.</em></h2></div><p>Keep every stay in one place, save favourite rooms, and review your stays. Booking without an account still works.</p></div>
    <div class="auth-grid">
        <form method="post" action="index.php#account" class="form-panel auth-form"><h3>Log in</h3><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>Email<input type="email" name="email" autocomplete="email" maxlength="150" required></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="button dark">Log in ↗</button></form>
        <form method="post" action="index.php#account" class="form-panel auth-form"><h3>Create an account</h3><input type="hidden" name="action" value="register"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>Full name<input name="full_name" autocomplete="name" maxlength="100" value="<?= e($authOld['full_name'] ?? '') ?>" required></label>
            <label>Email<input type="email" name="email" autocomplete="email" maxlength="150" value="<?= e($authOld['email'] ?? '') ?>" required></label>
            <label>Phone <span class="muted">(optional)</span><input type="tel" name="phone" autocomplete="tel" maxlength="20" value="<?= e($authOld['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX"></label>
            <label>Password <span class="muted">(8+ characters)</span><input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <label>Confirm password<input type="password" name="password2" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <button class="button dark">Create account ↗</button></form>
    </div>
<?php else: ?>
    <div class="section-heading"><div><p class="eyebrow">YOUR ELVORA ACCOUNT</p><h2>Hello, <em><?= e(explode(' ', $user['full_name'])[0]) ?>.</em></h2></div>
        <form method="post" action="index.php#account"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="button outline small">Log out</button></form></div>
    <h3 class="account-h">My reservations</h3>
    <?php if (!$myBookings): ?><p class="empty">No reservations yet. <a href="#reserve"><u>Reserve a stay</u></a> and it will appear here.</p><?php endif ?>
    <div class="booking-list"><?php foreach ($myBookings as $b): echo booking_card($b, ['mode' => 'account', 'today' => $today, 'canReview' => $b['status'] === 'Confirmed' && $b['check_out'] <= $today && !in_array((int) $b['id'], $reviewedIds, true)]); endforeach ?></div>
    <div class="auth-grid" style="margin-top:48px">
        <form method="post" action="index.php#account" class="form-panel auth-form"><h3>Profile</h3><input type="hidden" name="action" value="profile"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>Full name<input name="full_name" maxlength="100" value="<?= e($user['full_name']) ?>" required></label>
            <label>Email <span class="muted">(cannot be changed)</span><input value="<?= e($user['email']) ?>" disabled></label>
            <label>Phone<input type="tel" name="phone" maxlength="20" value="<?= e($user['phone']) ?>" placeholder="+63 9XX XXX XXXX"></label>
            <button class="button dark">Save profile</button></form>
        <form method="post" action="index.php#account" class="form-panel auth-form"><h3>Change password</h3><input type="hidden" name="action" value="password"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
            <label>New password <span class="muted">(8+ characters)</span><input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <label>Confirm new password<input type="password" name="password2" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <button class="button dark">Change password</button></form>
    </div>
<?php endif ?>
</section>
</div>
</main>
<footer><a class="brand" href="#home">ELVORA<span>HOTEL & RESIDENCES</span></a><p>A place to pause. A reason to return.</p><div><span>© <?= date('Y') ?> Elvora Hotel</span><a href="admin.php">Staff portal ↗</a></div></footer>
<dialog id="reviews-modal" aria-labelledby="reviews-title"><div class="modal-head"><h3 id="reviews-title">Reviews</h3><button type="button" class="button outline small" id="reviews-close">Close</button></div><div id="reviews-body"></div></dialog>
<div id="toast" role="status" class="toast" hidden></div>
</body></html>
