-- Online payment attempts for hosted PayMongo/PayPal checkouts.
-- Apply after database/schema.sql. This migration is additive and preserves
-- the existing manual payments and cash-at-pickup workflow.

ALTER TABLE reservations
  MODIFY payment_method ENUM('cash','bank_transfer','gcash','card','paymongo','paypal') DEFAULT NULL;

ALTER TABLE payments
  MODIFY method ENUM('cash','bank_transfer','gcash','card','paymongo','paypal') NOT NULL;

CREATE TABLE payment_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  provider ENUM('paymongo', 'paypal') NOT NULL,
  status ENUM('created', 'pending', 'paid', 'failed', 'cancelled', 'expired') NOT NULL DEFAULT 'created',
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PHP',
  provider_session_id VARCHAR(150) DEFAULT NULL,
  provider_payment_id VARCHAR(150) DEFAULT NULL,
  checkout_url TEXT DEFAULT NULL,
  failure_reason VARCHAR(255) DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_payment_attempt_provider_session (provider, provider_session_id),
  INDEX idx_payment_attempt_reservation_status (reservation_id, status),
  INDEX idx_payment_attempt_provider_payment (provider, provider_payment_id)
);

CREATE TABLE payment_webhook_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  provider ENUM('paymongo', 'paypal') NOT NULL,
  provider_event_id VARCHAR(150) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  payload JSON NOT NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_webhook_provider_event (provider, provider_event_id)
);
