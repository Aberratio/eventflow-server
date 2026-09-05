-- E1.12: Kod tożsamości QR (`zqr_`) na Zawodniku, generowany automatycznie.
--
-- Rozstrzygnięcie: zc-second-brain/specyfikacje/zmierzymy-czas/issues/11-tozsamosc-zawodnika-i-kody-qr.md
-- (Answer, sekcje 3-4) — dwa kody, ten sam kształt składniowy, różny prefiks i byt:
-- `pqr_` (kod startowy, E1.11) należy do Zapisu; `zqr_` (kod tożsamości, tu) należy
-- do Zawodnika. Ten sam algorytm co E1.11: `random_bytes(24)` (192 bity entropii),
-- 48 znaków hex, tylko inny prefiks — mirror `039_e1_11_registration_start_code.sql`.
--
-- Świadomie osobna kolumna, nie współdzielona z `qr_code` gdziekolwiek — spec §7
-- ostrzega wprost, że dzielenie jednej kolumny między "kod per Zapis" (UNIQUE
-- globalnie) i "kod per Zawodnik, powtarzalny przy każdym starcie tej samej osoby"
-- wysadziłoby to ograniczenie przy drugim starcie. `identity_code` NIE jest UNIQUE
-- względem `registrations.qr_code` — to inna tabela, inny byt, żadnego konfliktu
-- z natury; ale jest UNIQUE względem samego siebie (jeden token = jeden Zawodnik).
--
-- Generowany zawsze, przy tworzeniu Zawodnika (`CompetitorRepository::create()`),
-- nigdy przez BZ (synchronizacja `identity_code` do bazy BZ to E3.7, poza zakresem
-- tutaj) — więc backfill dla istniejących Zawodników tym samym `RANDOM_BYTES(24)`
-- w SQL, jak w 039, żeby żaden istniejący profil nie został bez kodu.
--
-- Świadomie: brak kolumny statusu/wygaśnięcia — spec §4 mówi wprost "żaden kod nie
-- ma statusu"; ważność kodu tożsamości jest stała, nie wygasa, żyje dokładnie tak
-- długo jak wiersz Zawodnika (usunięcie/anonimizacja profilu usuwa kod razem z nim,
-- bez osobnego mechanizmu do zbudowania teraz).
ALTER TABLE competitors
  ADD COLUMN IF NOT EXISTS identity_code VARCHAR(52) NULL AFTER club;

UPDATE competitors
SET identity_code = CONCAT('zqr_', LOWER(HEX(RANDOM_BYTES(24))))
WHERE identity_code IS NULL;

ALTER TABLE competitors
  MODIFY identity_code VARCHAR(52) NOT NULL;

-- Unikalne per Zawodnik (jeden aktywny kod na Zawodnika w danym momencie) — rotacja
-- (spec §2, "Rotacja na żądanie") nadpisuje tę samą kolumnę, bez historii starych
-- kodów (świadomie: unieważnienie = po prostu już nie ma go nigdzie w bazie).
ALTER TABLE competitors
  ADD UNIQUE INDEX IF NOT EXISTS uniq_competitors_identity_code (identity_code);
