-- M-01: reply-to maila (np. e-mail kontaktowy organizatora Wydarzenia, spec 17 §4) zapisywany w outboksie
-- razem z reszta tresci PRZED wywolaniem HTTP, zeby ponowienia (mail.outbox_retry) wysylaly identyczny
-- replyTo. NULL = brak replyTo (maile uwierzytelniania i systemowe) - Central Mailer uzyje wtedy globalnego.
--
-- Migrator wykonuje kazdy plik przy kazdym uruchomieniu, wiec migracja musi byc idempotentna (@guard).
-- @guard: column-missing central_mailer_outbox.reply_to_email
ALTER TABLE central_mailer_outbox ADD COLUMN reply_to_email VARCHAR(255) NULL AFTER attachments;
-- @guard: column-missing central_mailer_outbox.reply_to_name
ALTER TABLE central_mailer_outbox ADD COLUMN reply_to_name VARCHAR(255) NULL AFTER reply_to_email;
