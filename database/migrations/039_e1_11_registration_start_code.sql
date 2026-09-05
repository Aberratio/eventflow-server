-- E1.11: Kod startowy QR (`pqr_`) na Zapisie, generowany automatycznie.
--
-- Rozstrzygnięcie: zc-second-brain/specyfikacje/zmierzymy-czas/issues/11-tozsamosc-zawodnika-i-kody-qr.md
-- (Answer) — "Kod startowy": należy do Zapisu (nie do Zawodnika/Konta — to jest
-- `zqr_`, ticket E1.12, poza zakresem tutaj), format identyczny z tym, co Biuro
-- Zawodów już dziś generuje samo (`pqr_` + 48 znaków hex z `random_bytes(24)`,
-- regex `^pqr_[a-f0-9]{48}$` bez zmian), ale generowany przez platformę, nie BZ —
-- `RegistrationRepository::create()` (po tej migracji) generuje go zawsze, więc
-- nigdy nie ma Zapisu bez kodu i nie ma osobnej operacji "wygeneruj kody".
--
-- Świadomie: kod nie ma własnego stanu (żadnej kolumny status/active/used) —
-- jego ważność wynika wyłącznie ze stanu `registrations.status`, dokładnie jak
-- spec każe ("żaden kod nie ma statusu"). Kolumna jest zwykłym VARCHAR, nie JSON,
-- bo to atomowy token, nie struktura.
--
-- Backfill: każdy Zapis sprzed tej migracji dostaje kod tak samo jak nowy —
-- `RANDOM_BYTES(24)` w SQL to ten sam algorytm (192 bity entropii z bezpiecznego
-- generatora), tylko wołany raz, wprost w bazie, zamiast przez PHP-owe
-- `random_bytes()` w create() — nie ma znaczenia skąd token pochodzi, tylko że
-- jest bezpiecznie losowy i pasuje do formatu BZ. Bez tego backfillu punkt 2
-- kryterium akceptacji ("nigdy nie ma Zapisu bez kodu") byłby złamany dla
-- każdego Zapisu utworzonego przed tym ticketem.
ALTER TABLE registrations
  ADD COLUMN IF NOT EXISTS qr_code VARCHAR(52) NULL AFTER bib_number;

UPDATE registrations
SET qr_code = CONCAT('pqr_', LOWER(HEX(RANDOM_BYTES(24))))
WHERE qr_code IS NULL;

ALTER TABLE registrations
  MODIFY qr_code VARCHAR(52) NOT NULL;

-- Unikalne per Zapis (jeden kod na Zapis) — świadomie różne od BZ-owego modelu z
-- 05/07 sekcji tego samego dokumentu, gdzie `qr_code` w bazie BZ jest unikalne
-- GLOBALNIE i to jest dokładnie ta sama kolumna/koncepcja co tutaj (kod startowy
-- BZ *jest* unikalny per Zapis z założenia — jedna osoba na jednym dystansie ma
-- jeden Zapis, jeden kod). Ta kolumna żyje w bazie platformy, nie BZ — kolumna
-- `identity_code` w bazie BZ (bez tego ograniczenia) to zupełnie inny byt i inny
-- system, poza zakresem tego ticketu.
ALTER TABLE registrations
  ADD UNIQUE INDEX IF NOT EXISTS uniq_registrations_qr_code (qr_code);
