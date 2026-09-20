-- E8.1 (issue 22, "Scalanie i przejmowanie profili Zawodnika"): trzecia warstwa
-- obrony przed duplikatami Zawodnika (pierwsze dwie — ostrzeżenie przy zapisie,
-- E0.1 AC3, i przejęcie profilu przez konto — słownik §1 — nie zmieniają się tu).
--
-- Wyłącznie Moderator/Admin scala (routes.php gates this with AuthMiddleware
-- ['admin', 'moderator']) — nigdy samoobsługa, nawet przez opiekuna sprawującego
-- Opiekę nad oboma profilami (spec Answer §1: dotyka widoczności kodu tożsamości
-- u INNYCH opiekunów, których zgłaszający może nie znać).
--
-- Wchłonięty profil dostaje stan `scalony` (nie jest usuwany — archiwalny rekord,
-- odsyłacz do przeżywającego), analogicznie do "żaden kod nie ma własnego stanu"
-- z ticketu 11: tu to Zawodnik (byt), nie kod, dostaje stan, bo scalanie jest
-- jednorazowym zdarzeniem administracyjnym, nie codzienną operacją.
ALTER TABLE competitors
  ADD COLUMN IF NOT EXISTS status ENUM('active', 'scalony') NOT NULL DEFAULT 'active' AFTER identity_code,
  ADD COLUMN IF NOT EXISTS merged_into_competitor_id VARCHAR(36) NULL AFTER status;

ALTER TABLE competitors
  ADD INDEX IF NOT EXISTS idx_competitors_status (status),
  ADD INDEX IF NOT EXISTS idx_competitors_merged_into (merged_into_competitor_id);

-- FK is intentionally self-referencing with ON DELETE SET NULL, not CASCADE —
-- competitors are never hard-deleted (E0.1), but if that ever changes, a deleted
-- survivor must not cascade-delete the archival absorbed row silently.
ALTER TABLE competitors
  ADD CONSTRAINT fk_competitors_merged_into
    FOREIGN KEY (merged_into_competitor_id) REFERENCES competitors (id) ON DELETE SET NULL;

-- Alias: kod tożsamości (`zqr_`) wchłoniętego profilu NIE jest unieważniany od razu
-- (spec Answer §3) — w przeciwieństwie do rotacji (11), która IS natychmiastowa,
-- bo tam świadomie żąda jej sam Zawodnik/opiekun. Scalanie inicjuje obsługa bez
-- wiedzy Zawodnika; natychmiastowe unieważnienie zepsułoby już wydrukowaną kartę
-- startową. Skan starego kodu w Biurze Zawodów (albo dowolny inny odczyt) nadal
-- ma trafić w profil przeżywający — stąd ta tabela: stary kod -> Zawodnik
-- przeżywający, wyłącznie ścieżka odczytu wstecznego, nigdy promowana w UI.
--
-- UNIQUE na old_identity_code: raz zaalaisowany kod nie może zaalaisować się
-- drugi raz gdzie indziej (jeden stary kod = jeden punkt przekierowania).
CREATE TABLE IF NOT EXISTS identity_code_aliases (
  id VARCHAR(36) PRIMARY KEY,
  old_identity_code VARCHAR(52) NOT NULL,
  competitor_id VARCHAR(36) NOT NULL COMMENT 'Zawodnik przeżywający w chwili scalenia (może się dalej łańcuchować przy kolejnym scaleniu — patrz CompetitorRepository::resolveIdentityCode())',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_identity_code_aliases_code (old_identity_code),
  INDEX idx_identity_code_aliases_competitor (competitor_id),
  CONSTRAINT fk_identity_code_aliases_competitor FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log aktywności (E0.8): scalanie jest jedynym typem zdarzenia w całej mapie, gdzie
-- kopiowanie pełnych danych (nie tylko identyfikatorów) do logu jest świadomie
-- akceptowane mimo zasady Z2 — po scaleniu źródłowe rekordy przestają istnieć jako
-- edytowalne byty, więc log jest jedynym miejscem, gdzie stan sprzed operacji
-- przetrwa (spec Answer §5). `insertActivityLog()`/`activityLogs()` już istnieją
-- (E0.8) — reużywamy je, dopisując tylko to jedno nullable pole zamiast wynajdywać
-- równoległy mechanizm logowania.
ALTER TABLE activity_logs
  ADD COLUMN IF NOT EXISTS metadata JSON NULL AFTER target_name;
