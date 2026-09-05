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
INSERT INTO form_fields (id, event_id, kind, field_key, storage_location, state, sort_order, public_list_visible, is_confidential)
SELECT UUID(), e.id, 'system', k.field_key, k.storage_location, k.default_state, k.sort_order, 0, 0
FROM events e
CROSS JOIN (
  SELECT 'first_name' AS field_key, 'competitor' AS storage_location, 'required' AS default_state, 1 AS sort_order
  UNION ALL SELECT 'last_name', 'competitor', 'required', 2
  UNION ALL SELECT 'contact_email', 'registration', 'required', 3
  UNION ALL SELECT 'birth_date', 'competitor', 'required', 4
  UNION ALL SELECT 'gender', 'competitor', 'required', 5
  UNION ALL SELECT 'anonymization', 'competitor', 'hidden', 6
  UNION ALL SELECT 'country', 'competitor', 'hidden', 7
  UNION ALL SELECT 'city', 'competitor', 'hidden', 8
  UNION ALL SELECT 'postal_code', 'competitor', 'hidden', 9
  UNION ALL SELECT 'voivodeship', 'competitor', 'hidden', 10
  UNION ALL SELECT 'address', 'competitor', 'hidden', 11
  UNION ALL SELECT 'phone', 'competitor', 'hidden', 12
  UNION ALL SELECT 'club', 'registration', 'hidden', 13
) k
WHERE NOT EXISTS (SELECT 1 FROM form_fields f WHERE f.event_id = e.id AND f.field_key = k.field_key);

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
-- (trzy typy: text|select|checkbox) do `form_fields` (kind = custom). Kolejność
-- zachowana z tablicy JSON (sort_order = 100 + indeks, żeby zawsze lądowały po
-- 13 pozycjach systemowych — wcześniejsza kolejność względna między własnymi
-- polami się liczyła, względna wobec systemowych nie była nigdzie zdefiniowana).
-- Mapowanie typu: text -> short_text (jedyny typ tekstowy w starym modelu, bez
-- rozróżnienia krótki/długi — zachowujemy jako short_text, organizator może
-- ręcznie przełączyć na long_text później), select -> single_choice (opcje bez
-- ceny, cena to nowy atrybut tego ticketu — domyślnie 0, seam #2), checkbox ->
-- consent. `required` z JSON mapuje na stan `required`, inaczej `shown` (pole
-- było już w formularzu, samo istnienie w customFields = widoczne).
INSERT INTO form_fields (
  id, event_id, kind, field_key, storage_location, state, sort_order,
  label_override, public_list_visible, field_type, validation_config, options, is_confidential
)
SELECT
  UUID(),
  e.id,
  'custom',
  CONCAT('custom_', jt.legacy_id),
  'registration',
  CASE WHEN jt.required = 1 THEN 'required' ELSE 'shown' END,
  100 + jt.idx,
  jt.label,
  0,
  CASE jt.type
    WHEN 'text' THEN 'short_text'
    WHEN 'select' THEN 'single_choice'
    WHEN 'checkbox' THEN 'consent'
    ELSE 'short_text'
  END,
  NULL,
  CASE
    WHEN jt.type = 'select' AND jt.options_json IS NOT NULL THEN
      (SELECT JSON_ARRAYAGG(JSON_OBJECT('key', opt.option_value, 'label', opt.option_value, 'price', 0))
       FROM JSON_TABLE(jt.options_json, '$[*]' COLUMNS (option_value VARCHAR(255) PATH '$')) AS opt)
    ELSE NULL
  END,
  0
FROM events e
JOIN JSON_TABLE(
  e.registration_form_config,
  '$.customFields[*]'
  COLUMNS (
    idx FOR ORDINALITY,
    legacy_id VARCHAR(80) PATH '$.id',
    label VARCHAR(255) PATH '$.label',
    type VARCHAR(20) PATH '$.type',
    required TINYINT(1) PATH '$.required',
    options_json JSON PATH '$.options'
  )
) AS jt ON true
WHERE e.registration_form_config IS NOT NULL
  AND JSON_EXTRACT(e.registration_form_config, '$.customFields') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM form_fields f WHERE f.event_id = e.id AND f.field_key = CONCAT('custom_', jt.legacy_id)
  );
