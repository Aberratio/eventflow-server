-- E0.1: Zawodnik jako byt samodzielny + relacja Opieka (Konto N:M Zawodnik),
-- zastępująca Authorizations (Konto -> Konto).
--
-- Zawodnik to profil danych startowych, niezależny od Konta (loginu) — pozwala
-- rodzicowi zarejestrować dziecko bez zakładania dla niego konta. Opieka jest
-- relacją N:M: dwoje rodziców rejestrujących to samo dziecko tworzy dwie Opieki
-- nad jednym Zawodnikiem, nigdy duplikat Zawodnika.

CREATE TABLE IF NOT EXISTS competitors (
  id VARCHAR(36) PRIMARY KEY,
  first_name VARCHAR(120) NOT NULL,
  last_name VARCHAR(120) NOT NULL,
  birth_date VARCHAR(16) NULL,
  gender ENUM('M','K') NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(64) NULL,
  club VARCHAR(180) NULL,
  user_id VARCHAR(36) NULL COMMENT 'Konto właściciela profilu, jeśli Zawodnik ma też login',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_competitors_user (user_id),
  INDEX idx_competitors_dedup (last_name, first_name, birth_date),
  CONSTRAINT fk_competitors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS care_relations (
  id VARCHAR(36) PRIMARY KEY,
  konto_id VARCHAR(36) NOT NULL COMMENT 'Konto sprawujące opiekę (może zapisywać Zawodnika)',
  zawodnik_id VARCHAR(36) NOT NULL COMMENT 'Zawodnik, nad którym sprawowana jest opieka',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_care_konto_zawodnik (konto_id, zawodnik_id),
  INDEX idx_care_konto (konto_id),
  INDEX idx_care_zawodnik (zawodnik_id),
  CONSTRAINT fk_care_konto FOREIGN KEY (konto_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_care_zawodnik FOREIGN KEY (zawodnik_id) REFERENCES competitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: każde istniejące Konto dostaje własny profil Zawodnika (siebie samego),
-- tak żeby dotychczasowe rejestracje i upoważnienia miały do czego się podpiąć.
--
-- Liczony w PHP, nie w SQL — stał tu zwykły INSERT ... SELECT i był poprawny dokładnie
-- raz: przy pierwszym zastosowaniu 027, kiedy `competitors.identity_code` (E1.12,
-- migracja 040) jeszcze nie istniał. Migracje nie mają rejestru i wykonują się od nowa
-- przy każdym `composer migrate`, więc od pierwszego przebiegu po 040 ten sam statement
-- wstawiał do tabeli, w której `identity_code` jest NOT NULL bez DEFAULT i UNIQUE —
-- a że ta instalacja MariaDB nie ma STRICT_TRANS_TABLES, pominięta kolumna nie była
-- błędem, tylko cichym `''` w każdym wierszu i błędem 1062 na drugim z nich. Dopisanie
-- kolumny tutaj nie wchodzi w grę: na czystej bazie 027 wykonuje się trzynaście plików
-- przed jej powstaniem, więc krok musi móc *zapytać*, czy już jest.
-- @php: CompetitorProfileBackfill::run

-- Migracja participant_authorizations (Konto -> Konto) do care_relations (Konto -> Zawodnik).
-- Pierwotna semantyka: grantor = osoba, która ma być zapisywana; grantee = osoba
-- upoważniona do zapisywania. W nowym modelu: konto_id = grantee (opiekun),
-- zawodnik_id = profil Zawodnika należący do grantora.
INSERT INTO care_relations (id, konto_id, zawodnik_id, created_at)
SELECT UUID(), pa.grantee_id, c.id, pa.created_at
FROM participant_authorizations pa
INNER JOIN competitors c ON c.user_id = pa.grantor_id
WHERE NOT EXISTS (
  SELECT 1 FROM care_relations cr WHERE cr.konto_id = pa.grantee_id AND cr.zawodnik_id = c.id
);

-- Uwaga: participant_authorizations celowo NIE jest tu usuwana. Migrator uruchamia
-- wszystkie pliki przy każdym starcie (brak osobnego ledgera migracji) — DROP TABLE
-- w tym samym pliku zepsułby powyższy backfill przy kolejnym uruchomieniu, bo
-- kolejny przebieg czytałby z tabeli, której już nie ma. Aplikacja od E0.1 nie
-- korzysta już z tej tabeli; jej usunięcie zostawiamy osobnemu ticketowi porządkowemu.

-- registrations: Zawodnik jako źródło tożsamości zapisu (identyfikator, nie kopiowana nazwa).
-- user_name/user_email/user_id zostają jako cache do wyświetlania (Z2).
ALTER TABLE registrations
  ADD COLUMN IF NOT EXISTS competitor_id VARCHAR(36) NULL AFTER user_id;

ALTER TABLE registrations
  ADD INDEX IF NOT EXISTS idx_reg_competitor (competitor_id);

UPDATE registrations r
INNER JOIN competitors c ON c.user_id = r.user_id
SET r.competitor_id = c.id
WHERE r.competitor_id IS NULL;
