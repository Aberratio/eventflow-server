-- Registration (Zapis) form per Distance (Dystans). A form_fields row with `distance_id` set
-- is either an override of the inherited event-level entry (same `field_key`), or a field
-- available only on that Distance. The old unique key (event_id, field_key) forbade a
-- second row with the same key, and a UNIQUE with a NULL column does not guard event-level
-- rows - hence `distance_scope`: '' for the event, the Distance id for a Distance row.
-- Maintained by code (FormFieldRepository). When a Distance disappears (the FK sets distance_id to
-- NULL), `distance_scope` stays, so an orphaned row does not pose as an event-level entry.
-- @guard: column-missing form_fields.distance_scope
ALTER TABLE form_fields ADD COLUMN distance_scope VARCHAR(36) NOT NULL DEFAULT '' AFTER distance_id;

-- @guard: index-missing form_fields.uq_form_fields_event_key_scope
ALTER TABLE form_fields ADD UNIQUE KEY uq_form_fields_event_key_scope (event_id, field_key, distance_scope);

-- @guard: index-exists form_fields.uq_form_fields_event_key
ALTER TABLE form_fields DROP INDEX uq_form_fields_event_key;
