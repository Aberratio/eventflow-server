-- E1.6: Integracja bramki Tpay — webhook, weryfikacja podpisu, idempotencja.
--
-- Trasa A (bramka) potrzebuje jednego dodatkowego miejsca do zapisu: każda sesja
-- płatności rozpoczęta u Tpay (przy tworzeniu Zapisu i przy retryPayment()) zostaje
-- tu zapisana jako jeden wiersz, kluczowany po `transaction_id` nadanym przez Tpay
-- (nie po naszym id) — to właśnie ten klucz robi webhook idempotentnym: powtórne
-- powiadomienie o tym samym `transaction_id` (retry po timeoutcie po stronie Tpay)
-- odnajduje ten sam wiersz zamiast tworzyć nowy efekt uboczny.
--
-- `amount` jest tu tylko do audytu/porównania z tym, co przyszło w webhooku —
-- SPRAWDZANE, że pochodzi z zamrożonego Rozliczenia w chwili tworzenia sesji
-- (RegistrationRepository::totalAmountFor()), nigdy z klienta.
--
-- Jeden Zapis może mieć więcej niż jedną sesję w czasie (retryPayment po expired
-- otwiera nową), dlatego PRIMARY KEY to transaction_id, nie registration_id —
-- historia poprzednich prób zostaje.
CREATE TABLE IF NOT EXISTS registration_gateway_transactions (
  transaction_id VARCHAR(64) PRIMARY KEY,
  registration_id VARCHAR(36) NOT NULL,
  gateway VARCHAR(32) NOT NULL DEFAULT 'tpay',
  amount DECIMAL(10,2) NOT NULL,
  redirect_url VARCHAR(1024) NULL,
  -- created: sesja otwarta u bramki, czekamy na wpłatę.
  -- paid: webhook potwierdził wpłatę (terminal, jedyny stan uruchamiający efekty).
  -- failed: webhook potwierdził odrzucenie/błąd (terminal).
  status ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
  created_at VARCHAR(64) NOT NULL,
  processed_at VARCHAR(64) NULL,
  raw_notification JSON NULL,
  INDEX idx_registration_gateway_transactions_registration (registration_id),
  CONSTRAINT fk_registration_gateway_transactions_registration FOREIGN KEY (registration_id) REFERENCES registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
