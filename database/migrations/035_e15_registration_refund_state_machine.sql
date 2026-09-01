-- E1.5: Maszyna stanów płatności — stan zwrotu żyje na Rozliczeniu (Zapisie), nie
-- jest osobnym "typem" na registrations.status (spec 13-platnosci-i-rozliczenia.md,
-- "Maszyna stanów płatności" + "Zwroty").
--
-- `registrations.status` (pending/confirmed/cancelled/removed/expired, E1.1/E1.4)
-- keeps meaning exactly what it already means — whether the Zapis itself is active.
-- A refund cycle is a state machine layered on TOP of an already-`confirmed`
-- (opłacona) Zapis: `requested` (zawodnik poprosił) -> `approved` (organizator/admin
-- zatwierdził — spec's "do zwrotu") -> `refunded` (zwrócona, trasa A: API bramki /
-- trasa B: organizator ręcznie). Kept as its own table rather than folded into
-- `registration_payment_details` because that table's write path
-- (`upsertPaymentDetails`) is a full DELETE+INSERT replace — reusing it here would
-- silently wipe refund history on every unrelated payment-details edit.
--
-- One row per Zapis at a time (PRIMARY KEY registration_id): a Zapis can only be
-- mid-refund-cycle once. A completed cycle (`refunded`) stays as history — the row
-- is only ever re-armed (status back to `requested`/`approved`) by starting a new
-- cycle once the previous one is `refunded`, supporting a later, independent
-- second partial refund on the same Zapis without losing the first one's audit
-- trail in application logic (see RegistrationRepository::requestRefund()).
CREATE TABLE IF NOT EXISTS registration_refunds (
  registration_id VARCHAR(36) PRIMARY KEY,
  status ENUM('requested','approved','refunded') NOT NULL,
  requested_at VARCHAR(64) NULL,
  requested_by VARCHAR(36) NULL,
  reason VARCHAR(255) NULL,
  approved_at VARCHAR(64) NULL,
  approved_by VARCHAR(36) NULL,
  -- Rozstrzygnięcie 3 (spec 13): kwota zwrotu to saldo dodatnie z Rozliczenia — ten
  -- sam mechanizm arytmetyczny dla pełnej rezygnacji i częściowego zwrotu. Pełny
  -- recompute-po-zmianie-Zapisu (07 pkt 12) jest osobnym tickietem (20); tu `amount`
  -- domyślnie = zamrożone `totalAmount` (pełny zwrot) i może zostać zawężone przez
  -- organizatora/admina przy zatwierdzaniu (częściowy zwrot, np. po zdjęciu gadżetu),
  -- nigdy podniesione ponad totalAmount.
  amount DECIMAL(10,2) NULL,
  -- Trasa (A: bramka Tpay/PayNow, B: przelew tradycyjny) zamrożona z Wydarzenia w
  -- chwili zatwierdzenia (payment_config.type) — nie zmienia się, jeśli organizator
  -- później przekonfiguruje płatności dla przyszłych Zapisów.
  route ENUM('gateway','manual') NULL,
  completed_at VARCHAR(64) NULL,
  completed_by VARCHAR(36) NULL,
  INDEX idx_registration_refunds_status (status),
  CONSTRAINT fk_registration_refunds_registration FOREIGN KEY (registration_id) REFERENCES registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
