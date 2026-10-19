-- G-04 (spec 23 Answer §2): osobny, późniejszy termin graniczny wymiany zawodnika w składzie
-- Drużyny sztafetowej (kontuzje zdarzają się bliżej startu niż zwykła rezygnacja). Do tej daty
-- (włącznie) kapitan może wymienić zawodnika samodzielnie; po niej tylko organizator/Zespół
-- Wydarzenia. NULL = bez osobnego terminu: kapitan może wymieniać do dnia poprzedzającego start.
--
-- Przenośne i idempotentne (adnotacja @guard, bez `ADD COLUMN IF NOT EXISTS`).

-- @guard: column-missing event_distances.relay_swap_deadline
ALTER TABLE event_distances ADD COLUMN relay_swap_deadline DATE NULL AFTER relay_team_completion_deadline;
