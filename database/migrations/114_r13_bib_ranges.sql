-- Start (bib) number range per distance. Both NULL = no range (manual numbering only).
-- @guard: column-missing event_distances.bib_range_from
ALTER TABLE event_distances ADD COLUMN bib_range_from INT NULL AFTER participant_limit;
-- @guard: column-missing event_distances.bib_range_to
ALTER TABLE event_distances ADD COLUMN bib_range_to INT NULL AFTER bib_range_from;
