<?php
/*
|--------------------------------------------------------------------------
| ELVORA HOTEL — lib.php (shared by index.php and admin.php)
|--------------------------------------------------------------------------
| Database connection, escaping, CSRF, pricing (weekend / seasonal rates),
| promo codes, availability, booking creation, booking changes and accounts.
*/
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/catalog.php';

const MAX_NIGHTS = 30;

/* ---------- Basics ---------- */

/** Plain error page. On localhost it also shows the real error and a setup link. */
function render_fatal(string $message, Throwable $e): void
{
    error_log('ELVORA: ' . $e->getMessage());
    http_response_code(500);
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>ELVORA HOTEL</title></head>'
        . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0A0A0A;color:#fff;font-family:Georgia,serif;text-align:center;padding:2rem">'
        . '<div><p style="letter-spacing:.35em;font-size:1.3rem;margin:0 0 1rem">ELVORA</p><p style="color:#A0A0A0;max-width:420px;margin:0 auto">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . ($local
            ? '<p style="color:#d9a066;max-width:520px;margin:1.5rem auto 0;font:13px monospace">Technical detail (localhost only): ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p style="margin-top:1rem"><a href="setup.php" style="color:#fff">Run automatic database setup</a></p>'
            : '')
        . '</div></body></html>';
    exit;
}

function db(): PDO
{
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        render_fatal('We cannot reach the database. Start MySQL in XAMPP and make sure the database has been set up.', $e);
    }
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid($token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function money($amount): string
{
    $amount = (float) $amount;
    return '₱' . number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
}

function pretty_date(string $ymd): string { return date('F j, Y', strtotime($ymd)); }
function short_date(string $ymd): string { return date('M j, Y', strtotime($ymd)); }

function parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

function input_str(array $source, string $key): string
{
    return (isset($source[$key]) && is_string($source[$key])) ? trim($source[$key]) : '';
}

function setting(PDO $pdo, string $key, string $default = ''): string
{
    $q = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $q->execute([$key]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (string) $v;
}

/* ---------- Rooms, photos, ratings ---------- */

function get_rooms(PDO $pdo, bool $onlyActive = true): array
{
    $sql = 'SELECT id, room_name, price, capacity, beds, description, photo, is_suite, active FROM rooms'
         . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY price ASC, id ASC';
    return array_column($pdo->query($sql)->fetchAll(), null, 'id');
}

/** The 8 starter rooms. Used to repair an empty rooms table (existing rooms are never overwritten). */
function seed_default_rooms(PDO $pdo): void
{
    $rows = [
        [1, 'The Classic King', 3200, 2, '1 king bed', 'Soft linens, warm textures and a quiet corner to make your own. A considered space for two.', 0],
        [2, 'The Garden King', 3600, 2, '1 king bed', 'A bright, easygoing retreat with a king bed, a writing desk and space to slow down.', 0],
        [3, 'The Deluxe Retreat', 4500, 3, '1 king + daybed', 'A little more room to settle in. Enjoy a generous bedroom and a comfortable reading nook.', 0],
        [4, 'The Family Room', 5800, 4, '2 queen beds', 'Bring everyone along. Two queen beds and a relaxed lounge make family stays feel effortless.', 0],
        [5, 'The Junior Suite', 6500, 2, '1 king bed', 'An inviting suite with a separate sitting area, a deep bath and thoughtful details throughout.', 1],
        [6, 'The Residence', 8200, 6, '2 queens + sofa bed', 'Room for your whole circle, with a private living area and a kitchenette for longer stays.', 1],
        [7, 'The Signature Suite', 9800, 4, '1 king + sofa bed', 'Generous proportions, a refined living room and beautifully layered interiors for a special escape.', 1],
        [8, 'The Elvora Penthouse', 14500, 4, '2 king beds', 'Our most spacious stay. Two bedrooms, an expansive lounge and an unmistakable sense of occasion.', 1],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO rooms (id, room_name, price, capacity, beds, description, is_suite, active) VALUES (?,?,?,?,?,?,?,1)');
    foreach ($rows as $r) {
        $ins->execute($r);
    }
}

/** [total rooms, visible rooms] for diagnostics. */
function room_counts(PDO $pdo): array
{
    $row = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(active = 1), 0) AS visible FROM rooms')->fetch();
    return [(int) $row['total'], (int) $row['visible']];
}

function room_photo(array $room, int $width = 1000): string
{
    $custom = (string) ($room['photo'] ?? '');
    if ($custom !== '') {
        return $custom;
    }
    $photos = room_photos();
    return photo_url($photos[(int) $room['id']] ?? $photos[1], $width);
}

function room_ratings(PDO $pdo): array
{
    $rows = $pdo->query("SELECT room_id, AVG(rating) AS avg_rating, COUNT(*) AS total FROM reviews WHERE status = 'Visible' GROUP BY room_id")->fetchAll();
    return array_column($rows, null, 'room_id');
}

/* ---------- Pricing: weekend + seasonal rates, promo codes ---------- */

function load_pricing(PDO $pdo): array
{
    $seasons = $pdo->query("SELECT name, start_date, end_date, adjust_percent FROM seasonal_rates WHERE active = 1 ORDER BY start_date")->fetchAll();
    return ['weekend' => (float) setting($pdo, 'weekend_surcharge_percent', '0'), 'seasons' => $seasons];
}

/** The season adjustment (%) for one night. If seasons overlap, the largest adjustment wins. */
function season_percent(array $pricing, string $ymd): float
{
    $best = 0.0;
    foreach ($pricing['seasons'] as $s) {
        if ($ymd >= $s['start_date'] && $ymd <= $s['end_date']) {
            $p = (float) $s['adjust_percent'];
            if (abs($p) > abs($best)) {
                $best = $p;
            }
        }
    }
    return $best;
}

/** Rate for ONE night (the night that starts on $night). Friday and Saturday nights get the weekend surcharge. */
function nightly_rate(float $base, DateTimeImmutable $night, array $pricing): float
{
    $factor = 1.0;
    $dow = (int) $night->format('N');
    if ($dow === 5 || $dow === 6) {
        $factor *= 1 + $pricing['weekend'] / 100;
    }
    $factor *= 1 + season_percent($pricing, $night->format('Y-m-d')) / 100;
    return round($base * $factor, 2);
}

function price_stay(float $base, DateTimeImmutable $in, DateTimeImmutable $out, array $pricing): float
{
    $total = 0.0;
    for ($d = $in; $d < $out; $d = $d->modify('+1 day')) {
        $total += nightly_rate($base, $d, $pricing);
    }
    return round($total, 2);
}

/** Add-ons chosen by key. Returns [selected lines, total]. Unknown and duplicate keys are ignored. */
function addons_cost(array $keys, int $guests, int $nights): array
{
    $selected = [];
    $total = 0.0;
    foreach (addon_catalog() as $key => $addon) {
        if (in_array($key, $keys, true)) {
            $cost = $addon['price'] * ($addon['unit'] === 'guest/night' ? $guests * $nights : 1);
            $selected[] = ['key' => $key, 'name' => $addon['name'], 'amount' => $cost];
            $total += $cost;
        }
    }
    return [$selected, $total];
}

/** Add-on keys stored on a booking (older bookings only stored names, so map those back). */
function booking_addon_keys(array $booking): array
{
    $keys = [];
    foreach (json_decode((string) ($booking['addons_json'] ?? '[]'), true) ?: [] as $line) {
        if (!empty($line['key'])) {
            $keys[] = $line['key'];
            continue;
        }
        foreach (addon_catalog() as $key => $addon) {
            if ($addon['name'] === ($line['name'] ?? '')) {
                $keys[] = $key;
            }
        }
    }
    return $keys;
}

/** Returns ['promo' => row|null, 'error' => string]. An empty code is fine (no promo, no error). */
function find_promo(PDO $pdo, string $code, int $nights, ?int $ignoreBookingId = null): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['promo' => null, 'error' => ''];
    }
    $q = $pdo->prepare('SELECT * FROM promo_codes WHERE code = ? AND active = 1');
    $q->execute([$code]);
    $p = $q->fetch();
    $today = date('Y-m-d');
    if (!$p) {
        return ['promo' => null, 'error' => 'That promo code is not valid.'];
    }
    if (!empty($p['valid_from']) && $today < $p['valid_from']) {
        return ['promo' => null, 'error' => 'That promo code is not active yet.'];
    }
    if (!empty($p['valid_to']) && $today > $p['valid_to']) {
        return ['promo' => null, 'error' => 'That promo code has expired.'];
    }
    if ($nights < (int) $p['min_nights']) {
        return ['promo' => null, 'error' => 'That promo code needs a stay of at least ' . (int) $p['min_nights'] . ' night(s).'];
    }
    if ($p['max_uses'] !== null) {
        $u = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE promo_code = ? AND status IN ('Pending','Confirmed') AND id <> ?");
        $u->execute([$p['code'], $ignoreBookingId ?? 0]);
        if ((int) $u->fetchColumn() >= (int) $p['max_uses']) {
            return ['promo' => null, 'error' => 'That promo code has been fully redeemed.'];
        }
    }
    return ['promo' => $p, 'error' => ''];
}

/** Promo discounts apply to the room charge only (not to extras). */
function promo_discount(array $promo, float $roomTotal): float
{
    $d = $promo['type'] === 'percent' ? $roomTotal * (float) $promo['value'] / 100 : (float) $promo['value'];
    return round(min($d, $roomTotal), 2);
}

/* ---------- Availability, expiry ---------- */

/** Only Pending and Confirmed stays hold dates. $ignoreId lets a booking be moved without clashing with itself. */
function room_is_available(PDO $pdo, int $roomId, string $checkIn, string $checkOut, ?int $ignoreId = null): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings
         WHERE room_id = :room AND status IN ('Pending','Confirmed') AND id <> :ign
           AND check_in < :co AND COALESCE(occupied_until, check_out) > :ci"
    );
    $stmt->execute([':room' => $roomId, ':ign' => $ignoreId ?? 0, ':co' => $checkOut, ':ci' => $checkIn]);
    return (int) $stmt->fetchColumn() === 0;
}

/** Pending bookings older than the configured number of hours become Expired (0 = never). */
function expire_pending(PDO $pdo): void
{
    $hours = (int) setting($pdo, 'pending_expiry_hours', '0');
    if ($hours > 0) {
        $pdo->prepare("UPDATE bookings SET status = 'Expired' WHERE status = 'Pending' AND created_at < (NOW() - INTERVAL ? HOUR)")->execute([$hours]);
    }
}

function lock_room(PDO $pdo, int $roomId): void
{
    $sql = 'SELECT id FROM rooms WHERE id = :id' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
    $pdo->prepare($sql)->execute([':id' => $roomId]);
}

function make_reference(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE booking_reference = :ref');
    for ($i = 0; $i < 5; $i++) {
        $ref = 'ELV-' . strtoupper(bin2hex(random_bytes(4)));
        $check->execute([':ref' => $ref]);
        if ((int) $check->fetchColumn() === 0) {
            return $ref;
        }
    }
    throw new RuntimeException('Could not generate a unique booking reference.');
}

/* ---------- Booking ---------- */

/** Validate dates + guests against a room. Returns [errors, in, out, nights]. */
function check_stay(?array $room, string $inRaw, string $outRaw, $guests): array
{
    $errors = [];
    $today = new DateTimeImmutable('today');
    $in = parse_date($inRaw);
    $out = parse_date($outRaw);

    if ($in === null) {
        $errors['check_in'] = 'Choose a valid check-in date.';
    } elseif ($in < $today) {
        $errors['check_in'] = 'Check-in cannot be in the past.';
    }
    if ($out === null) {
        $errors['check_out'] = 'Choose a valid check-out date.';
    } elseif ($in !== null && $out <= $in) {
        $errors['check_out'] = 'Check-out must be after check-in.';
    }
    $nights = 0;
    if ($in !== null && $out !== null && $out > $in) {
        $nights = (int) $in->diff($out)->days;
        if ($nights > MAX_NIGHTS) {
            $errors['check_out'] = 'A single booking can be up to ' . MAX_NIGHTS . ' nights.';
        }
    }
    if ($guests === false || $guests === null || $guests < 1) {
        $errors['guests'] = 'Enter the number of guests (at least 1).';
    } elseif ($room !== null && $guests > (int) $room['capacity']) {
        $errors['guests'] = $room['room_name'] . ' sleeps up to ' . (int) $room['capacity'] . ' guests.';
    }
    return [$errors, $in, $out, $nights];
}

/** Validate the booking form and save it. Nothing sent by the browser (including prices) is trusted. */
function process_booking(PDO $pdo, array $rooms, array $input, array $pricing, ?int $userId): array
{
    $errors = [];
    $name = input_str($input, 'full_name');
    $email = input_str($input, 'email');
    $phone = input_str($input, 'phone');
    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    $guests = filter_var($input['guests'] ?? null, FILTER_VALIDATE_INT);

    if ($name === '') {
        $errors['full_name'] = 'Enter your full name.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors['full_name'] = 'Your name must be between 2 and 100 characters.';
    }
    if ($email === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address, like name@example.com.';
    }
    if ($phone === '') {
        $errors['phone'] = 'Enter your phone number.';
    } elseif (!preg_match('/^[0-9+\-()\s]{7,20}$/', $phone)) {
        $errors['phone'] = 'Use 7 to 20 digits. You can include +, spaces, dashes and brackets.';
    }

    $room = null;
    if ($roomId === false || $roomId === null || !isset($rooms[$roomId])) {
        $errors['room_id'] = 'Choose a room.';
    } else {
        $room = $rooms[$roomId];
    }

    [$stayErrors, $in, $out, $nights] = check_stay($room, input_str($input, 'check_in'), input_str($input, 'check_out'), $guests);
    $errors += $stayErrors;

    $requests = input_str($input, 'requests');
    if (strlen($requests) > 1000) {
        $errors['requests'] = 'Special requests must be under 1,000 characters.';
    }

    $promo = null;
    if (!$errors) {
        $found = find_promo($pdo, input_str($input, 'promo'), $nights);
        if ($found['error'] !== '') {
            $errors['promo'] = $found['error'];
        }
        $promo = $found['promo'];
    }
    if ($errors) {
        return ['errors' => $errors, 'booking' => null];
    }

    $rawAddons = is_array($input['addons'] ?? null) ? $input['addons'] : [];
    [$selected, $extraTotal] = addons_cost($rawAddons, (int) $guests, $nights);
    $roomTotal = price_stay((float) $room['price'], $in, $out, $pricing);
    $discount = $promo ? promo_discount($promo, $roomTotal) : 0.0;
    $total = round($roomTotal - $discount + $extraTotal, 2);
    $checkIn = $in->format('Y-m-d');
    $checkOut = $out->format('Y-m-d');

    try {
        $pdo->beginTransaction();
        lock_room($pdo, (int) $roomId);   // a second person booking this room at the same moment waits here

        $occupiedUntil = in_array('late', $rawAddons, true) ? $out->modify('+1 day')->format('Y-m-d') : $checkOut;
        if (!room_is_available($pdo, (int) $roomId, $checkIn, $occupiedUntil)) {
            $pdo->rollBack();
            return ['errors' => ['general' => $room['room_name'] . ' is already booked for some or all of those dates. Choose different dates or another room.'], 'booking' => null];
        }

        $reference = make_reference($pdo);
        $pdo->prepare(
            "INSERT INTO bookings (booking_reference, user_id, guest_name, email, phone, room_id, check_in, check_out, guests, total_nights, total_amount, addons_json, special_requests, occupied_until, promo_code, discount_amount, status)
             VALUES (:ref, :uid, :name, :email, :phone, :room, :ci, :co, :guests, :nights, :total, :addons, :req, :occ, :promo, :disc, 'Pending')"
        )->execute([
            ':ref' => $reference, ':uid' => $userId, ':name' => $name, ':email' => $email, ':phone' => $phone, ':room' => $roomId,
            ':ci' => $checkIn, ':co' => $checkOut, ':guests' => $guests, ':nights' => $nights, ':total' => $total,
            ':addons' => json_encode($selected), ':req' => $requests, ':occ' => $occupiedUntil,
            ':promo' => $promo['code'] ?? null, ':disc' => $discount,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ELVORA booking failed: ' . $e->getMessage());
        return ['errors' => ['general' => 'Something went wrong while saving your booking. Please try again.'], 'booking' => null];
    }

    return ['errors' => [], 'booking' => [
        'reference' => $reference, 'guest' => $name, 'room' => $room['room_name'], 'check_in' => $checkIn, 'check_out' => $checkOut,
        'guests' => (int) $guests, 'nights' => $nights, 'total' => $total, 'status' => 'Pending', 'addons' => $selected,
        'promo' => $promo['code'] ?? null, 'discount' => $discount,
    ]];
}

/**
 * Change dates / guests on an existing booking. Re-checks availability (ignoring the booking itself),
 * re-prices it, keeps the extras and promo if still valid, and sends it back to Pending for staff to reconfirm.
 * Returns ['error' => string, 'notice' => string].
 */
function modify_booking(PDO $pdo, array $booking, array $input, array $pricing): array
{
    $today = date('Y-m-d');
    if (!in_array($booking['status'], ['Pending', 'Confirmed'], true) || $booking['check_in'] <= $today) {
        return ['error' => 'This reservation can no longer be changed online. Please contact reception.', 'notice' => ''];
    }
    $q = $pdo->prepare('SELECT id, room_name, price, capacity FROM rooms WHERE id = ?');
    $q->execute([$booking['room_id']]);
    $room = $q->fetch();
    if (!$room) {
        return ['error' => 'Room not found.', 'notice' => ''];
    }
    $guests = filter_var($input['new_guests'] ?? null, FILTER_VALIDATE_INT);
    [$errors, $in, $out, $nights] = check_stay($room, input_str($input, 'new_check_in'), input_str($input, 'new_check_out'), $guests);
    if ($errors) {
        return ['error' => implode(' ', $errors), 'notice' => ''];
    }
    $checkIn = $in->format('Y-m-d');
    $checkOut = $out->format('Y-m-d');
    if ($checkIn === $booking['check_in'] && $checkOut === $booking['check_out'] && (int) $guests === (int) $booking['guests']) {
        return ['error' => 'Nothing to change: those are your current dates and guest count.', 'notice' => ''];
    }

    $keys = booking_addon_keys($booking);
    $late = in_array('late', $keys, true);
    [$selected, $extraTotal] = addons_cost($keys, (int) $guests, $nights);
    $roomTotal = price_stay((float) $room['price'], $in, $out, $pricing);
    $promoNote = '';
    $promo = null;
    if (!empty($booking['promo_code'])) {
        $found = find_promo($pdo, (string) $booking['promo_code'], $nights, (int) $booking['id']);
        $promo = $found['promo'];
        if (!$promo) {
            $promoNote = ' The promo code no longer applies to the new dates, so it was removed.';
        }
    }
    $discount = $promo ? promo_discount($promo, $roomTotal) : 0.0;
    $total = round($roomTotal - $discount + $extraTotal, 2);
    $occupiedUntil = $late ? $out->modify('+1 day')->format('Y-m-d') : $checkOut;

    try {
        $pdo->beginTransaction();
        lock_room($pdo, (int) $room['id']);
        if (!room_is_available($pdo, (int) $room['id'], $checkIn, $occupiedUntil, (int) $booking['id'])) {
            $pdo->rollBack();
            return ['error' => $room['room_name'] . ' is not available for those dates. Your original dates are unchanged.', 'notice' => ''];
        }
        $pdo->prepare(
            "UPDATE bookings SET check_in = ?, check_out = ?, guests = ?, total_nights = ?, total_amount = ?, addons_json = ?, occupied_until = ?, promo_code = ?, discount_amount = ?, status = 'Pending'
             WHERE id = ?"
        )->execute([$checkIn, $checkOut, $guests, $nights, $total, json_encode($selected), $occupiedUntil, $promo['code'] ?? null, $discount, $booking['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ELVORA modify failed: ' . $e->getMessage());
        return ['error' => 'Something went wrong while changing your booking. Please try again.', 'notice' => ''];
    }
    return ['error' => '', 'notice' => 'Your stay was updated: ' . pretty_date($checkIn) . ' to ' . pretty_date($checkOut) . ', new total ' . money($total) . '. It is Pending again until the hotel reconfirms it.' . $promoNote];
}

/* ---------- Guest accounts ---------- */

/** Returns [errors, userId|null]. */
function register_user(PDO $pdo, array $in): array
{
    $errors = [];
    $name = input_str($in, 'full_name');
    $email = strtolower(input_str($in, 'email'));
    $phone = input_str($in, 'phone');
    $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
    $confirm = is_string($in['password2'] ?? null) ? $in['password2'] : '';

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Enter your full name (2 to 100 characters).';
    }
    if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-()\s]{7,20}$/', $phone)) {
        $errors[] = 'Phone: use 7 to 20 digits (you can include +, spaces, dashes and brackets).';
    }
    if (strlen($pass) < 8 || strlen($pass) > 72) {
        $errors[] = 'Choose a password of 8 to 72 characters.';
    } elseif ($pass !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if ($errors) {
        return [$errors, null];
    }
    try {
        $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash) VALUES (?,?,?,?)')
            ->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            return [['An account with that email already exists. Try logging in.'], null];
        }
        throw $e;
    }
    return [[], (int) $pdo->lastInsertId()];
}

function find_user(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare('SELECT id, full_name, email, phone FROM users WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** "Fiona Faye Magbanua" -> "Fiona M." (reviews never show full names). */
function public_name(string $full): string
{
    $parts = preg_split('/\s+/', trim($full));
    return count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $parts[0];
}
