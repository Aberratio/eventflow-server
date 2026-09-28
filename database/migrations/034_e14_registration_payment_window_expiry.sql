-- E1.4: Okno płatności (per-Wydarzenie, dwie wartości) i wygasanie nieopłaconego
-- Zapisu (`expired`, różny od `cancelled`).
--
-- Rozstrzygnięcie: docs/07-model-cenowy-zapisu.md pkt 5 ("Cena zamraża się w chwili
-- utworzenia Zapisu i jest ważna przez okno płatności. Po jego upływie Zapis wygasa
-- i zwalnia slot.") oraz docs/20-... pkt 1 ("Okno płatności: dwie wartości,
-- konfigurowalne per Wydarzenie" — bramka domyślnie 30-60 min, przelew domyślnie
-- 3-5 dni roboczych, obie wartości edytowalne przez organizatora; po upływie okna
-- Zapis wygasa (stan `expired`, różny od `cancelled`) i zwalnia slot).
--
-- `events.payment_config` (JSON, już istnieje od dawna — 001_init.sql) jest
-- naturalnym miejscem na obie wartości okna: EventRepository::hydrate() domyśla je
-- (gatewayWindowMinutes = 45, transferWindowDays = 3) na czytaniu, więc żadna
-- migracja po stronie `events` nie jest potrzebna — organizator nadpisuje obie
-- wartości przez ten sam ogólny PATCH /events/{id}, którym już dziś edytuje resztę
-- paymentConfig (typ bramka/przelew, dane konta).
--
-- Pragmatyczna decyzja (E1.6-E1.8 jeszcze nie istnieją — Zapis nie pamięta jeszcze,
-- którą metodą płatności zamierza zapłacić): RegistrationRepository::create() zamraża
-- `payment_expires_at` używając zawsze krótszego okna bramki (gatewayWindowMinutes)
-- jako domyślnego terminu wygaśnięcia — to bezpieczna strona (zwalnia slot szybciej,
-- nigdy nie trzyma go dłużej niż trzeba), a gdy E1.6-E1.8 dodadzą realny wybór
-- metody płatności do Zapisu, dostaną tu gotowy, per-Wydarzenie skonfigurowany
-- fallback na okno przelewu do przełączenia się na.
ALTER TABLE registrations
  MODIFY status ENUM('pending','confirmed','cancelled','removed','expired') NOT NULL DEFAULT 'pending';

-- @guard: column-missing registrations.payment_expires_at
ALTER TABLE registrations ADD COLUMN payment_expires_at VARCHAR(64) NULL AFTER removed_at;

-- Speeds up the lazy expiry scan (status = 'pending' AND payment_expires_at < now)
-- the same way 033's idx_registrations_distance_status speeds up the capacity
-- population predicate.
-- @guard: index-missing registrations.idx_registrations_status_payment_expiry
ALTER TABLE registrations ADD INDEX idx_registrations_status_payment_expiry (status, payment_expires_at);

-- Deliberately no backfill: a Zapis created before this migration never had a real
-- payment window to freeze, so its `payment_expires_at` stays NULL — the lazy expiry
-- check below only ever considers rows where it is NOT NULL, so pre-existing pending
-- registrations are grandfathered (never auto-expire) rather than guessing a deadline
-- for them from a window that didn't apply when they were created. Every Zapis
-- created from this migration forward gets a real, frozen deadline in create().
