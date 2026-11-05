-- Local hosted checkout method for environments without an external gateway.
-- It uses the same payment attempts, payments, receipts, and balance flow.

ALTER TABLE reservations
  MODIFY payment_method ENUM('cash','bank_transfer','gcash','card','paymongo','paypal','online') DEFAULT NULL;

ALTER TABLE payments
  MODIFY method ENUM('cash','bank_transfer','gcash','card','paymongo','paypal','online') NOT NULL;

ALTER TABLE payment_attempts
  MODIFY provider ENUM('paymongo','paypal','online') NOT NULL;
