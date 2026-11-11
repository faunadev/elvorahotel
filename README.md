# ELVORA Hotel booking system (PHP + MySQL)

## Run with XAMPP
1. Copy the `elvora` folder to `C:/xampp/htdocs/`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/elvora/setup.php` once (or import `hotel.sql` in phpMyAdmin). It is safe on a fresh or older database, can be repeated, and never deletes bookings.
4. Website: `http://localhost/elvora/`   Staff: `http://localhost/elvora/admin.php` (admin / elvora123)

If the database is not ready, the error page on localhost shows the real MySQL error.

## Website (guests)
- Separate views: Home, Our rooms, The little extras, My reservation, Account. Each nav button shows only its own page (no long scroll); Back/Forward work.
- Rooms with photos, filters, sorting (incl. best rated), availability search, live itemized total.
- Weekend (Fri/Sat night) and seasonal rates, promo codes (room charge only), 6 add-ons.
- Guest accounts: sign up, log in/out, profile, change password, "My reservations", saved rooms kept on the account, booking form auto-filled.
- Change dates/guests on a Pending or Confirmed booking before arrival (re-checks availability, re-prices, returns to Pending for staff to reconfirm). Cancel before arrival.
- Reviews: logged-in guests can rate a stay after a confirmed booking has finished. Ratings show on room cards; click to read reviews.
- Booking without an account still works; look it up with reference + email.
- Pending bookings expire automatically (default 48 hours; set 0 to disable in Staff > Pricing & promos).

## Staff dashboard
Overview (revenue, pending value, 30-day occupancy, today's arrivals, revenue by month and by room) · Bookings (search, filters, confirm/cancel/delete, CSV) · Calendar (room x day availability grid) · Rooms (add, edit, price, photo, hide) · Pricing & promos (weekend %, seasons, promo codes, expiry hours) · Guests (accounts + booking history) · Reviews (hide/show/delete).

## Notes
- Existing bookings keep the price they were made at when you change a room price or rates.
- Accounts are not automatically linked to earlier guest bookings with the same email (the email is not verified). Guests can still find those with reference + email.
- Pay at the property only. No online payment, email/SMS or password-reset email is connected yet.
- Photos load from Unsplash unless you add `assets/room-1.jpg` ... `room-8.jpg`, or set a photo URL per room in the admin.

## Before public hosting
Delete `setup.php`. Set DB_HOST, DB_NAME, DB_USER, DB_PASS, ADMIN_USERNAME and ADMIN_PASSWORD_HASH as environment variables. Use HTTPS and a restricted database user.

## Files
`index.php` site · `admin.php` staff · `lib.php` shared logic · `config.php` · `catalog.php` add-ons/photos · `hotel.sql` database · `setup.php` one-click setup · `assets/` style.css, admin.css, app.js
