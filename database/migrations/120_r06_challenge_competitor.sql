-- R-06 (zakres P2, do potwierdzenia przez Zamawiającego): Uczestnictwo w Wyzwaniu
-- należy do Zawodnika, nie do Konta (słownik §6, ticket 15). Faza 1 (ta migracja):
-- nullable `competitor_id` na uczestnictwach i zgłoszeniach + backfill własnym
-- profilem Zawodnika Konta (competitors.user_id). `user_id` zostaje (to Konto, które
-- zapisało / dostanie mail) - nie jest usuwane. Wiersz bez profilu Zawodnika zostaje
-- z NULL i jest czytany po user_id (fallback w kodzie).
-- @guard: column-missing challenge_participations.competitor_id
ALTER TABLE challenge_participations ADD COLUMN competitor_id VARCHAR(36) NULL AFTER user_id;
-- @guard: column-missing challenge_entries.competitor_id
ALTER TABLE challenge_entries ADD COLUMN competitor_id VARCHAR(36) NULL AFTER user_id;

-- @guard: index-missing challenge_participations.idx_challenge_participations_competitor
ALTER TABLE challenge_participations ADD INDEX idx_challenge_participations_competitor (competitor_id);
-- @guard: index-missing challenge_entries.idx_challenge_entries_competitor
ALTER TABLE challenge_entries ADD INDEX idx_challenge_entries_competitor (competitor_id);

-- ON DELETE SET NULL: Zawodnicy nie są kasowani (scalanie = status), a gdyby tak się
-- stało, wiersz wraca do odczytu po user_id zamiast znikać razem z postępem.
-- @guard: foreign-key-missing challenge_participations.fk_challenge_participations_competitor
ALTER TABLE challenge_participations
  ADD CONSTRAINT fk_challenge_participations_competitor
    FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE SET NULL;
-- @guard: foreign-key-missing challenge_entries.fk_challenge_entries_competitor
ALTER TABLE challenge_entries
  ADD CONSTRAINT fk_challenge_entries_competitor
    FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE SET NULL;

-- Backfill (idempotent: dotyka wyłącznie wierszy z NULL).
UPDATE challenge_participations cp
INNER JOIN competitors c ON c.user_id = cp.user_id
SET cp.competitor_id = c.id
WHERE cp.competitor_id IS NULL;

UPDATE challenge_entries ce
INNER JOIN competitors c ON c.user_id = ce.user_id
SET ce.competitor_id = c.id
WHERE ce.competitor_id IS NULL;
