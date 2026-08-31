-- E1.2: Algorytm cenowy Zapisu — krok 2 (Próg Wariantu) i krok 6 (NNW, oferta na
-- poziomie wydarzenia).
--
-- Rozstrzygnięcie: docs/07-model-cenowy-zapisu.md. Migracja 029 przeniosła schemat
-- w kierunku Wariantu opłaty, ale Wariant nie miał jeszcze własnych Progów (schemat
-- niósł tylko `price` — próg wciąż żył wyłącznie na Dystansie). Ten plik domyka
-- schemat pod pełny algorytm: Wariant dostaje ten sam kształt JSON co
-- `event_distances.price_tiers` (endDate/participantLimit/price/discountType/
-- discountValue), a wydarzenie dostaje tabelę łączącą do katalogu NNW, żeby
-- organizator mógł wybrać, które warianty globalne oferuje.

ALTER TABLE event_distance_pricing_variants
  ADD COLUMN IF NOT EXISTS price_tiers JSON NULL AFTER price;

CREATE TABLE IF NOT EXISTS event_insurance_variants (
  event_id VARCHAR(36) NOT NULL,
  insurance_variant_id VARCHAR(36) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (event_id, insurance_variant_id),
  INDEX idx_event_insurance_variants_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
