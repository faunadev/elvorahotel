-- ELVORA hotel database. Safe on a fresh server OR on an older ELVORA database.
-- Idempotent: can be run any number of times. Never drops tables or deletes reservations.
-- Works on MySQL 5.7+ and MariaDB 10.3+. Import in phpMyAdmin, or just open setup.php.
CREATE DATABASE IF NOT EXISTS elvora_hotel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE elvora_hotel;

-- 1. Tables (created only if missing) ---------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 room_name VARCHAR(100) NOT NULL,
 price DECIMAL(10,2) NOT NULL,
 capacity INT NOT NULL,
 beds VARCHAR(100) NOT NULL,
 description TEXT NOT NULL,
 photo VARCHAR(255) NULL,
 is_suite TINYINT(1) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(100) NOT NULL,
 email VARCHAR(150) NOT NULL UNIQUE,
 phone VARCHAR(20) NOT NULL DEFAULT '',
 password_hash VARCHAR(255) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_reference VARCHAR(30) NOT NULL UNIQUE,
 user_id INT UNSIGNED NULL,
 guest_name VARCHAR(100) NOT NULL,
 email VARCHAR(150) NOT NULL,
 phone VARCHAR(20) NOT NULL,
 room_id INT UNSIGNED NOT NULL,
 check_in DATE NOT NULL,
 check_out DATE NOT NULL,
 guests INT NOT NULL,
 total_nights INT NOT NULL,
 total_amount DECIMAL(12,2) NOT NULL,
 addons_json TEXT NOT NULL,
 special_requests TEXT NOT NULL,
 occupied_until DATE NULL,
 promo_code VARCHAR(30) NULL,
 discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 status ENUM('Pending','Confirmed','Cancelled','Expired') NOT NULL DEFAULT 'Pending',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (room_id) REFERENCES rooms(id),
 INDEX availability (room_id,status,check_in,check_out),
 INDEX booking_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS saved_rooms (
 user_id INT UNSIGNED NOT NULL,
 room_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (user_id, room_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id INT UNSIGNED NOT NULL UNIQUE,
 room_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 rating TINYINT NOT NULL,
 comment TEXT NOT NULL,
 status ENUM('Visible','Hidden') NOT NULL DEFAULT 'Visible',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX review_room (room_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS promo_codes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(30) NOT NULL UNIQUE,
 type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
 value DECIMAL(10,2) NOT NULL,
 min_nights INT NOT NULL DEFAULT 1,
 valid_from DATE NULL,
 valid_to DATE NULL,
 max_uses INT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seasonal_rates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 adjust_percent DECIMAL(6,2) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
 setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- 2. Upgrade older tables: each step runs only if needed --------------------
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='room_name')=0,
  'ALTER TABLE rooms ADD COLUMN room_name VARCHAR(100) NOT NULL DEFAULT ''', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='price')=0,
  'ALTER TABLE rooms ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='capacity')=0,
  'ALTER TABLE rooms ADD COLUMN capacity INT NOT NULL DEFAULT 2', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='beds')=0,
  'ALTER TABLE rooms ADD COLUMN beds VARCHAR(100) NOT NULL DEFAULT ''', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='description')=0,
  'ALTER TABLE rooms ADD COLUMN description TEXT NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='photo')=0,
  'ALTER TABLE rooms ADD COLUMN photo VARCHAR(255) NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='active')=0,
  'ALTER TABLE rooms ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @suite_new := (SELECT COUNT(*)=0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='is_suite');
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms' AND COLUMN_NAME='is_suite')=0,
  'ALTER TABLE rooms ADD COLUMN is_suite TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
UPDATE rooms SET is_suite = 1 WHERE id >= 5 AND @suite_new = 1;
UPDATE rooms SET description = '' WHERE description IS NULL;
ALTER TABLE rooms MODIFY description TEXT NOT NULL;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='addons_json')=0,
  'ALTER TABLE bookings ADD COLUMN addons_json TEXT NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='special_requests')=0,
  'ALTER TABLE bookings ADD COLUMN special_requests TEXT NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='occupied_until')=0,
  'ALTER TABLE bookings ADD COLUMN occupied_until DATE NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='user_id')=0,
  'ALTER TABLE bookings ADD COLUMN user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='promo_code')=0,
  'ALTER TABLE bookings ADD COLUMN promo_code VARCHAR(30) NULL', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='discount_amount')=0,
  'ALTER TABLE bookings ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND INDEX_NAME='availability')=0,
  'ALTER TABLE bookings ADD INDEX availability (room_id,status,check_in,check_out)', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND INDEX_NAME='booking_user')=0,
  'ALTER TABLE bookings ADD INDEX booking_user (user_id)', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rooms')<>'InnoDB',
  'ALTER TABLE rooms ENGINE=InnoDB', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s := IF((SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings')<>'InnoDB',
  'ALTER TABLE bookings ENGINE=InnoDB', 'SELECT 1');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
ALTER TABLE bookings MODIFY status ENUM('Pending','Confirmed','Cancelled','Expired') NOT NULL DEFAULT 'Pending';
UPDATE bookings SET addons_json = '[]' WHERE addons_json IS NULL;
UPDATE bookings SET special_requests = '' WHERE special_requests IS NULL;
UPDATE bookings SET occupied_until = check_out WHERE occupied_until IS NULL;
ALTER TABLE bookings MODIFY addons_json TEXT NOT NULL, MODIFY special_requests TEXT NOT NULL;

-- 3. Rooms: adds any of the 8 rooms that are missing; existing names/prices are kept
INSERT INTO rooms (id,room_name,price,capacity,beds,description,is_suite) VALUES
(1,'The Classic King',3200,2,'1 king bed','Soft linens, warm textures and a quiet corner to make your own. A considered space for two.',0),
(2,'The Garden King',3600,2,'1 king bed','A bright, easygoing retreat with a king bed, a writing desk and space to slow down.',0),
(3,'The Deluxe Retreat',4500,3,'1 king + daybed','A little more room to settle in. Enjoy a generous bedroom and a comfortable reading nook.',0),
(4,'The Family Room',5800,4,'2 queen beds','Bring everyone along. Two queen beds and a relaxed lounge make family stays feel effortless.',0),
(5,'The Junior Suite',6500,2,'1 king bed','An inviting suite with a separate sitting area, a deep bath and thoughtful details throughout.',1),
(6,'The Residence',8200,6,'2 queens + sofa bed','Room for your whole circle, with a private living area and a kitchenette for longer stays.',1),
(7,'The Signature Suite',9800,4,'1 king + sofa bed','Generous proportions, a refined living room and beautifully layered interiors for a special escape.',1),
(8,'The Elvora Penthouse',14500,4,'2 king beds','Our most spacious stay. Two bedrooms, an expansive lounge and an unmistakable sense of occasion.',1)
ON DUPLICATE KEY UPDATE
 beds = IF(rooms.beds = '', VALUES(beds), rooms.beds),
 description = IF(rooms.description = '', VALUES(description), rooms.description);

-- 4. Default settings and a sample promo code (existing values are kept)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('weekend_surcharge_percent','0'), ('pending_expiry_hours','48');
INSERT IGNORE INTO promo_codes (code,type,value,min_nights,active) VALUES ('WELCOME10','percent',10,1,1);
