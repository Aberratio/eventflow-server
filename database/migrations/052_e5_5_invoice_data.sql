-- E5.5: "Dane do faktury przy zamowieniu" (issue 16 pkt 1, umowa SS3.8-3.10;
-- SS6 recommendation lists "dane do faktury" as part of the minimal required
-- shop scope). This is data capture only -- no invoice PDF generation, no
-- numbering sequence, no accounting integration (explicitly out of scope,
-- see issue 16 "Czego to rozstrzygniecie swiadomie nie dotyka").
--
-- Mirrors shipping_address (migration 019): a single nullable JSON column,
-- optional on every order. Presence of invoiceData in the checkout payload
-- is itself the "chce fakture" signal -- same shape as shippingAddress,
-- which is gated by the selected delivery method rather than a separate
-- boolean column. No column is required here for backward compatibility:
-- an order that never sends invoiceData persists NULL and behaves exactly
-- as before E5.5.
ALTER TABLE shop_orders
    ADD COLUMN invoice_data JSON DEFAULT NULL AFTER shipping_address;
