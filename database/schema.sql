CREATE DATABASE IF NOT EXISTS eventease
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eventease;

CREATE TABLE IF NOT EXISTS users (
  user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('ADMIN','ORGANISER','CUSTOMER') NOT NULL DEFAULT 'CUSTOMER',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venues (
  venue_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venue_name VARCHAR(160) NOT NULL,
  address VARCHAR(255) NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_venue_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organiser_id BIGINT UNSIGNED NOT NULL,
  venue_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  category VARCHAR(80) NOT NULL,
  event_date DATETIME NOT NULL,
  ticket_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_tickets INT UNSIGNED NOT NULL,
  status ENUM('DRAFT','PUBLISHED','CANCELLED','COMPLETED') NOT NULL DEFAULT 'DRAFT',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_events_organiser FOREIGN KEY (organiser_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_events_venue FOREIGN KEY (venue_id) REFERENCES venues(venue_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_event_price CHECK (ticket_price >= 0),
  CONSTRAINT chk_event_tickets CHECK (total_tickets > 0),
  INDEX idx_events_date (event_date),
  INDEX idx_events_category (category),
  INDEX idx_events_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
  booking_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_reference VARCHAR(40) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  booking_status ENUM('PENDING','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_event FOREIGN KEY (event_id) REFERENCES events(event_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_booking_quantity CHECK (quantity > 0),
  CONSTRAINT chk_booking_amount CHECK (total_amount >= 0),
  INDEX idx_bookings_customer (customer_id),
  INDEX idx_bookings_event_status (event_id, booking_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  payment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(40) NOT NULL DEFAULT 'DEMO',
  payment_status ENUM('PENDING','SUCCESS','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  external_reference VARCHAR(120) NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_payment_amount CHECK (amount >= 0),
  INDEX idx_payments_booking (booking_id)
) ENGINE=InnoDB;

-- Security: store hashed tokens rather than raw bearer tokens.
CREATE TABLE IF NOT EXISTS api_tokens (
  token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_tokens_expiry (expires_at)
) ENGINE=InnoDB;
