-- R-17 (automatyczna synchronizacja): kolejka wysyłek do Kalendarza Google obsługuje teraz dwa rodzaje zadań:
-- 'upsert' (utwórz / zaktualizuj wpis) i 'delete' (usuń wpis). Zadanie 'delete' pamięta id wpisu Google,
-- bo Wydarzenie może w międzyczasie zostać usunięte lub odwołane.
-- @guard: column-missing google_calendar_outbox.action
ALTER TABLE google_calendar_outbox ADD COLUMN action ENUM('upsert','delete') NOT NULL DEFAULT 'upsert';

-- @guard: column-missing google_calendar_outbox.google_event_id
ALTER TABLE google_calendar_outbox ADD COLUMN google_event_id VARCHAR(255) NULL;
