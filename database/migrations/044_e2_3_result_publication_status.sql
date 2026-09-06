-- E2.3: Publikacja wynikow: wstepne -> oficjalne, korekta zawsze mozliwa ze
-- sladem.
--
-- Rozstrzygniecie: zc-second-brain/specyfikacje/.../14-wyniki-live-rekordy.md,
-- sekcja 6. "Organizator publikuje wyniki wstepne od razu po imporcie/recznym
-- wpisaniu ... Po weryfikacji ... organizator oznacza wyniki jako oficjalne.
-- Korekta jest mozliwa zawsze, takze po ogloszeniu oficjalnych."
--
-- Granulacja (nie doprecyzowana wprost w specyfikacji - patrz docblock
-- ResultRepository::markOfficial() dla pelnego uzasadnienia): per
-- event+distance (klasyfikacja), ta sama jednostka co juz uzywa
-- renumberEventDistance()/ResultImportRepository::positions(). Kolumna zyje
-- mimo to na kazdym wierszu `results` (denormalizacja, ten sam wzorzec co
-- juz obecne tam distance_id/distance_name) zamiast w osobnej tabeli
-- event+distance -> status, zeby GET /results i pochodne nie potrzebowaly
-- dodatkowego JOINa/lookupu przy kazdym odczycie.
--
-- Domyslna wartosc 'preliminary' dla nowych i juz istniejacych wierszy: wyniki
-- sa widoczne od razu po imporcie/wpisaniu bez zadnej dodatkowej akcji
-- organizatora (kryterium akceptacji 2) - "oficjalne" jest wylacznie
-- podniesieniem stanu przez nowy endpoint, nigdy stanem startowym.
--
-- Migracje nie maja ledgera i uruchamiaja sie przy kazdym starcie (patrz
-- Migrator.php) - ADD COLUMN IF NOT EXISTS jest tu bezpieczne do powtorzenia.

ALTER TABLE results
  ADD COLUMN IF NOT EXISTS publication_status ENUM('preliminary','official') NOT NULL DEFAULT 'preliminary' AFTER status;
