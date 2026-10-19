-- G-03: organizer's manual correction of a place (open / in category) must survive the renumbering
-- that follows every create/update in the event+distance. One flag per place, because the two can
-- be overridden independently; renumbering leaves a flagged place alone, PATCH with null clears it.
-- @guard: column-missing results.position_manual
ALTER TABLE results ADD COLUMN position_manual TINYINT(1) NOT NULL DEFAULT 0;
-- @guard: column-missing results.category_position_manual
ALTER TABLE results ADD COLUMN category_position_manual TINYINT(1) NOT NULL DEFAULT 0;
