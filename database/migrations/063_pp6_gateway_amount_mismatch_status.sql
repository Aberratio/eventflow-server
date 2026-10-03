-- PP.6: nowy status transakcji bramki `amount_mismatch` — podpisane powiadomienie
-- zgłosiło wpłatę, ale kwota (w groszach) różni się od zamrożonej `amount`.
-- Zapis NIE jest oznaczany jako opłacony; wymaga ręcznej weryfikacji.
-- Stan nieterminalny: późniejsze powiadomienie z poprawną kwotą może go zamknąć (paid).
-- Przenośne (MySQL 8.4 + MariaDB 10.4) i idempotentne: samo MODIFY, bez IF [NOT] EXISTS,
-- bez strażnika (MODIFY nie jest zmianą strukturalną w rozumieniu PP.13).
ALTER TABLE registration_gateway_transactions
  MODIFY status ENUM('created','paid','failed','amount_mismatch') NOT NULL DEFAULT 'created';
