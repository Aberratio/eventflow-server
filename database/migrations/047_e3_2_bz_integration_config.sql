-- E3.2: Token organizacji BZ per Wydarzenie, wlaczenie integracji.
--
-- Kontrakt: zc-second-brain, issue 12 "Kontrakt integracji z Biurem
-- Zawodow", sekcja 3 "Konfiguracja: token organizacji BZ per Wydarzenie".
-- Platforma nie tworzy organizacji w BZ automatycznie - organizator zaklada
-- ja poza platforma (jednorazowo) i przy wlaczaniu przelacznika "Biuro
-- Zawodow" dla Wydarzenia wkleja jej identyfikator/token. Ten ticket jest
-- czystym storage/plumbing - zadnego wywolania HTTP do BZ, zadnej walidacji
-- ze token jest prawdziwy (to E3.3+).
--
-- Ksztalt: dwie plaskie kolumny na `events`, nie kolejny JSON blob w stylu
-- `payment_config` (E1.4/E1.7). To pojedynczy prosty toggle + string, wiec
-- blizej ksztaltem do E1.1's `visibility`/`is_test` (plaskie kolumny) niz do
-- wieloczlonowego `payment_config`. `bz_organization_token` jest NULL-owalny
-- i celowo NIE jest czyszczony przy wylaczaniu integracji (patrz
-- EventRepository::eventData() - partial update per-field, wiec PATCH bez
-- `bzOrganizationToken` w body zostawia kolumne nietknieta) - ponowne
-- wlaczenie integracji nie wymaga ponownego wklejenia tokenu, tak samo jak
-- inne opcjonalne przelaczniki per-Wydarzenie w tym repo zachowuja swoja
-- powiazana wartosc.
--
-- Migracje nie maja ledgera i uruchamiaja sie przy kazdym starcie (patrz
-- Migrator.php) - ADD COLUMN IF NOT EXISTS jest tu bezpieczne do powtorzenia.

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS bz_integration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER insurance_option,
  ADD COLUMN IF NOT EXISTS bz_organization_token VARCHAR(255) NULL AFTER bz_integration_enabled;
