-- PP.54: token zaproszenia do Drużyny (spec 23 §1 "link do zaproszenia").
--
-- Problem: id Drużyny jest publiczne (GET /events/{e}/distances/{d}/teams je
-- wypisuje), a dołączenie do istniejącej Drużyny sprawdzało tylko jej
-- istnienie, dystans i limit składu. Każdy, kto znał id, mógł wejść do cudzej
-- Drużyny (a przy "za drużynę" dostawał od razu Zapis potwierdzony).
--
-- Rozwiązanie: losowy, nieodgadywalny `invite_token`, czytelny tylko dla
-- kapitana i organizatora wydarzenia (GET /teams/{id}/invite). Dołączenie
-- wymaga tokenu albo działania samego kapitana.
--
-- Tokeny są generowane w PHP (TeamRepository::newInviteToken()); starsze
-- Drużyny zostają z NULL i dostają token przy pierwszym odczycie zaproszenia.
-- Brak backfillu w SQL (SQL nie ma CSPRNG). Unikalny indeks dopuszcza wiele
-- NULL-i zarówno na MariaDB 10.4, jak i na MySQL 8.4.
--
-- Przenośne i idempotentne: sprawdzenie w information_schema + PREPARE/EXECUTE
-- (jak w 001_init.sql), bez `ADD COLUMN IF NOT EXISTS` (MySQL 8.4 go odrzuca).

-- Add the invitation token column unless it is already there.
SET @pp54_invite_col_present = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'teams' AND column_name = 'invite_token'
);
SET @pp54_invite_col_sql = IF(
  @pp54_invite_col_present = 0,
  'ALTER TABLE teams ADD COLUMN invite_token VARCHAR(64) NULL AFTER special_category',
  'DO 0'
);
PREPARE pp54_invite_col_stmt FROM @pp54_invite_col_sql;
EXECUTE pp54_invite_col_stmt;
DEALLOCATE PREPARE pp54_invite_col_stmt;

-- Add the unique index unless it is already there.
SET @pp54_invite_idx_present = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'teams' AND index_name = 'uq_teams_invite_token'
);
SET @pp54_invite_idx_sql = IF(
  @pp54_invite_idx_present = 0,
  'ALTER TABLE teams ADD UNIQUE INDEX uq_teams_invite_token (invite_token)',
  'DO 0'
);
PREPARE pp54_invite_idx_stmt FROM @pp54_invite_idx_sql;
EXECUTE pp54_invite_idx_stmt;
DEALLOCATE PREPARE pp54_invite_idx_stmt;
