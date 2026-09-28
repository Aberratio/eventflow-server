-- E1.13: Konfigurowalny formularz zapisów — jeden byt "Pozycja formularza"
-- dla pól systemowych i własnych (jedna uporządkowana lista), zastępujący
-- `events.registration_form_config` (JSON: collectPhone/collectClub/
-- collectBirthDate/collectTeam/collectInsurance/collectMerchandise +
-- customFields[] o trzech typach text|select|checkbox).
--
-- Rozstrzygnięcie: zc-second-brain/specyfikacje/zmierzymy-czas/sources/
-- model-formularza-zapisow.md. §1-§5, §9.
--
-- `events.registration_form_config` NIE jest tu usuwana ani czyszczona —
-- klient (React) wciąż go czyta/pisze dla starego ekranu ustawień formularza
-- (EventFormStep2.tsx) i ten ticket świadomie nie przerabia tego UI (patrz
-- commit message / raport tego ticketu: zakres server-only, jak E1.6/E1.7/E1.9).
-- Nowa tabela `form_fields` jest dodatkowym, docelowym źródłem prawdy; oba
-- mechanizmy współistnieją do czasu, aż osobny ticket przełączy UI.
--
-- Kolumny-szwy (seam, nie funkcja — patrz docblocki w kodzie PHP):
--   distance_id           — seam #1 (nadpisywanie per Dystans, model §7): zawsze
--                           NULL dzisiaj, obecna wyłącznie żeby wiązanie per
--                           Dystans było później zmianą addytywną, nie
--                           restrukturyzacją schematu.
--   options (price)       — seam #2 (wycenione warianty, model §4.1): kolumna
--                           trzyma cenę, ale nic jej nigdzie nie odczytuje do
--                           rozliczenia.
--   is_confidential       — seam #3 (pole poufne, model §4.2): flaga + wykluczenie
--                           z publicznej listy działają; eksport/Zespół-only/
--                           retencja — nie.
CREATE TABLE IF NOT EXISTS form_fields (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  -- Seam #1 (model §7, "poza umową — do ustalenia"): nullable scoping column
  -- for a future per-Dystans override/addition. Resolution logic ("Dystans
  -- overrides a Wydarzenie-level Pozycja" / "Dystans adds Pozycja visible only
  -- there") and any UI for it are explicitly NOT built this ticket — every row
  -- this ticket ever writes keeps this NULL, i.e. every Pozycja behaves as
  -- Wydarzenie-scoped only. The column exists purely so wiring the real
  -- override later is additive (new rows with distance_id set + resolver code),
  -- not a schema change.
  distance_id VARCHAR(36) NULL,
  kind ENUM('system','custom') NOT NULL,
  -- Systemowa: jeden z 13 kluczy zamkniętego katalogu (FormFieldCatalog::KEYS).
  -- Własna: nadany przez organizatora, stabilny od utworzenia (model §5 — klucz
  -- nigdy się nie zmienia, nawet gdy etykieta jest nadpisywana).
  field_key VARCHAR(80) NOT NULL,
  storage_location ENUM('competitor','registration') NOT NULL,
  state ENUM('hidden','shown','required') NOT NULL DEFAULT 'hidden',
  sort_order INT NOT NULL DEFAULT 0,
  label_override VARCHAR(255) NULL,
  tooltip_override VARCHAR(500) NULL,
  -- Domyślnie Nie (model §4.2, §9) dla każdego pola, systemowego i własnego.
  public_list_visible TINYINT(1) NOT NULL DEFAULT 0,
  -- Tylko dla `kind = custom` — jeden z siedmiu typów (model §4). NULL dla
  -- pozycji systemowych (ich "typ" to stały atrybut Zawodnika/Zapisu, nie
  -- konfigurowalny).
  field_type ENUM('short_text','long_text','number','date','single_choice','multi_choice','consent') NULL,
  -- Walidacja wynika wyłącznie z typu (model §4): {"maxLength": n} dla tekstów,
  -- {"min": x, "max": y} dla liczby/daty. NULL gdy typ nie ma parametrów
  -- (wybory, zgoda).
  validation_config JSON NULL,
  -- Warianty dla typów wyboru: [{"key": "...", "label": "...", "price": 0}].
  -- Seam #2 — `price` jest przechowywana, nigdy odczytywana przy rozliczeniu.
  options JSON NULL,
  -- Seam #3 (model §4.2): flaga "pole poufne". Zbudowane i egzekwowane tu:
  -- nigdy nie trafia na publiczną listę startową (patrz PublicStartList::
  -- visibleColumns() — wyklucza confidential niezależnie od public_list_visible).
  -- NIE zbudowane (patrz FormFieldRepository docblock i model §4.2): wykluczenie
  -- z domyślnego eksportu, ograniczenie odczytu do Zespołu (nie Moderatora),
  -- automatyczne kasowanie odpowiedzi po zakończeniu Wydarzenia.
  is_confidential TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_fields_event_key (event_id, field_key),
  INDEX idx_form_fields_event (event_id),
  INDEX idx_form_fields_distance (distance_id),
  CONSTRAINT fk_form_fields_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_form_fields_distance FOREIGN KEY (distance_id) REFERENCES event_distances (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Odpowiedzi na pola własne. Model F1: sekcja startu (a więc każde pole własne)
-- zbiera odpowiedzi żyjące na Zapisie, nigdy na Zawodniku — stąd jeden klucz
-- obcy do registrations, bez rozgałęzienia na competitor_id.
CREATE TABLE IF NOT EXISTS form_field_answers (
  id VARCHAR(36) PRIMARY KEY,
  form_field_id VARCHAR(36) NOT NULL,
  registration_id VARCHAR(36) NOT NULL,
  -- Skalarne typy (short_text/long_text/number/date/single_choice/consent)
  -- trzymane jako tekst; multi_choice jako JSON-owa lista kluczy wariantów.
  value_text VARCHAR(2000) NULL,
  value_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_form_field_answers_field_registration (form_field_id, registration_id),
  INDEX idx_form_field_answers_registration (registration_id),
  CONSTRAINT fk_form_field_answers_field FOREIGN KEY (form_field_id) REFERENCES form_fields (id) ON DELETE CASCADE,
  CONSTRAINT fk_form_field_answers_registration FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: każde istniejące Wydarzenie dostaje wszystkie 13 pozycji
-- systemowych (model §2). Domyślne stany:
--   - Imię/Nazwisko/E-mail kontaktowy: `required` (nieusuwalne, tylko ten stan
--     istnieje — model §3).
--   - Data urodzenia/Płeć: `required` (klasa "napędzająca logikę", model §3).
--     Odblokowanie Nie/Pokaż wymaga sprawdzenia, czy Dystans klasyfikuje
--     wiekowo — ten mechanizm nie istnieje jeszcze w kodzie (grepped: brak
--     jakiegokolwiek odczytu `age_categories` poza samym zapisem kolumny), więc
--     zgodnie z ticketem zostają zawsze wymagane, z odblokowaniem odłożonym
--     (FormFieldRepository::assertStateAllowed() dokumentuje to jako gap).
--   - Anonimizacja i reszta swobodnych: `hidden` (Nie), zgodnie z dotychczasowym
--     domyślnym brakiem tych pól w formularzu.
-- PP.13: moved to ZmierzymyCzas\Database\FormFieldsCatalogBackfill::run(), called
-- unconditionally from Migrator::applyAll() (see that class's docblock) — same
-- reasoning as 027/CompetitorProfileBackfill and 054/ChallengeConditionTreeBackfill:
-- this needs to keep catching events inserted directly by seeders/fixtures after
-- migrations (and the ledger) have already run, not just events that existed the
-- first time this file applied.
-- @php: FormFieldsCatalogBackfill::run

-- Backfill: pole "Telefon" i "Klub" jako `shown`, jeśli stary JSON już je zbierał
-- (`collectPhone`/`collectClub`). "Data urodzenia" świadomie NIE jest tu
-- odblokowywana z `collectBirthDate = false` — patrz uwaga wyżej, control-class
-- "napędzająca logikę" wygrywa nad odziedziczonym ustawieniem legacy.
UPDATE form_fields f
INNER JOIN events e ON e.id = f.event_id
SET f.state = 'shown'
WHERE f.field_key = 'phone'
  AND f.state = 'hidden'
  AND JSON_EXTRACT(e.registration_form_config, '$.collectPhone') = true;

UPDATE form_fields f
INNER JOIN events e ON e.id = f.event_id
SET f.state = 'shown'
WHERE f.field_key = 'club'
  AND f.state = 'hidden'
  AND JSON_EXTRACT(e.registration_form_config, '$.collectClub') = true;

-- Backfill: migracja pól własnych z `events.registration_form_config.customFields`
-- (trzy typy: text|select|checkbox) do `form_fields` (kind = custom).
--
-- E1.14 verification fix (ten fragment, mimo że dotyczy migracji 041, jest
-- naprawiany w ramach E1.14 — patrz commit message tego ticketu): ten backfill
-- pierwotnie żył tutaj jako pojedynczy INSERT ... JOIN JSON_TABLE(...) z
-- zagnieżdżonym JSON_ARRAYAGG(JSON_OBJECT(...)). Oba wymagają MariaDB, którego
-- ta instalacja nie ma: JSON_TABLE dopiero od 10.6.0, JSON_ARRAYAGG dopiero od
-- 10.5.0, a `SELECT VERSION()` tu zwraca 10.4.32 — więc ten statement nigdy nie
-- parsował się na tej bazie (błąd składni), co ubijało CAŁY przebieg migracji
-- (Migrator::applyFile() nie ma obsługi błędów per-statement), blokując także
-- 042 (E1.14 samo) na zawsze. Przeniesione do PHP:
-- `ZmierzymyCzas\Database\CustomFormFieldsBackfill::run()`, wołane z
-- `Migrator::applyAll()` po zastosowaniu wszystkich plików .sql. Ta sama
-- semantyka (kolejność z tablicy JSON, sort_order = 100 + indeks, mapowanie
-- typu text->short_text/select->single_choice/checkbox->consent, `required`
-- z JSON -> stan `required` inaczej `shown`, opcje jako string[] -> [{key,
-- label, price:0}]) i ta sama idempotencja (pomija field_key już istniejący na
-- danym evencie), tylko przez zwykłe json_decode() + parametryzowane INSERTy
-- zamiast JSON_TABLE/JSON_ARRAYAGG — działa niezależnie od wersji MariaDB/MySQL.
