-- Gender place ("miejsce wg płci") - the place among finishers of the same gender in the event+distance,
-- computed with the open and category places (0 = not classified / gender unknown).
-- @guard: column-missing results.gender_position
ALTER TABLE results ADD COLUMN gender_position INT NOT NULL DEFAULT 0;
