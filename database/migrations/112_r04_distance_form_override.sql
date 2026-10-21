-- R-04: formularz zapisow per Dystans (spec 10). Wiersz form_fields z ustawionym `distance_id`
-- jest albo nadpisaniem odziedziczonej pozycji wydarzenia (ten sam `field_key`), albo polem
-- dostepnym tylko na tym Dystansie. Stary klucz unikalny (event_id, field_key) zabraniał
-- drugiego wiersza o tym samym kluczu, a UNIQUE z kolumna NULL nie pilnuje wierszy
-- wydarzenia - stad `distance_scope`: '' dla wydarzenia, id Dystansu dla wiersza Dystansu.
-- Utrzymywana przez kod (FormFieldRepository). Gdy Dystans zniknie (FK ustawia distance_id na
-- NULL), `distance_scope` zostaje, wiec osierocony wiersz nie udaje pozycji wydarzenia.
-- @guard: column-missing form_fields.distance_scope
ALTER TABLE form_fields ADD COLUMN distance_scope VARCHAR(36) NOT NULL DEFAULT '' AFTER distance_id;

-- @guard: index-missing form_fields.uq_form_fields_event_key_scope
ALTER TABLE form_fields ADD UNIQUE KEY uq_form_fields_event_key_scope (event_id, field_key, distance_scope);

-- @guard: index-exists form_fields.uq_form_fields_event_key
ALTER TABLE form_fields DROP INDEX uq_form_fields_event_key;
