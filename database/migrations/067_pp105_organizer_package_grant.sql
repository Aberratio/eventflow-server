-- PP.105: pakiet organizatora nadaje kredyty, rolę i slot wydarzenia dopiero po opłaceniu zamówienia.
--
-- Przenośne (MySQL 8.4 + MariaDB 10.4) i idempotentne: adnotacje @guard, bez
-- `ADD COLUMN IF NOT EXISTS`. Plik można uruchomić ponownie (także po przerwaniu w połowie).

-- Znacznik nadania: ile kredytów pakietu zostało nadanych z tego zamówienia (0 = nic).
-- Kolumna powstaje z tymczasowym DEFAULT -1 = "jeszcze nie przeliczone", żeby backfill poniżej
-- dotykał wyłącznie wierszy istniejących w chwili dodania kolumny (nigdy nowych zamówień,
-- także przy ponownym uruchomieniu pliku).
-- @guard: column-missing shop_orders.organizer_credits_granted
ALTER TABLE shop_orders ADD COLUMN organizer_credits_granted INT NOT NULL DEFAULT -1 AFTER total_price;

-- Backfill: zamówienia złożone przed PP.105 dostały kredyty już przy tworzeniu (w dowolnym
-- statusie), więc znacznik = liczba sztuk pakietu. Musi iść PRZED zmianą nazwy rodzaju usługi:
-- stare 'organizer_license' nigdy niczego nie nadawało i zostaje ze znacznikiem 0
-- (nadanie nastąpi przy oznaczeniu jako opłacone).
UPDATE shop_orders o
LEFT JOIN (SELECT i.order_id, SUM(i.quantity) AS qty
           FROM shop_order_items i JOIN shop_products p ON p.id = i.product_id
           WHERE p.service_kind = 'organizer_package'
           GROUP BY i.order_id) g ON g.order_id = o.id
SET o.organizer_credits_granted = COALESCE(g.qty, 0)
WHERE o.organizer_credits_granted = -1;

-- Docelowy DEFAULT 0 (nowe zamówienia nic jeszcze nie nadały).
ALTER TABLE shop_orders MODIFY organizer_credits_granted INT NOT NULL DEFAULT 0;

-- Jedna, kanoniczna nazwa rodzaju usługi (seed używał 'organizer_license').
UPDATE shop_products SET service_kind = 'organizer_package' WHERE service_kind = 'organizer_license';
