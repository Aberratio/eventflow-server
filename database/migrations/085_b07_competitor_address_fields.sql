-- B-07: pola systemowe sekcji tożsamości (spec 10, model-formularza-zapisow §2)
-- bez dotychczasowego miejsca zapisu. Sekcja tożsamości to projekcja atrybutów
-- Zawodnika, więc kraj, miejscowość, kod pocztowy, województwo i adres żyją na
-- `competitors` (telefon, klub i anonimizacja już tam są). Wszystko NULL —
-- istniejące profile nie mają tych danych.
-- @guard: column-missing competitors.country
ALTER TABLE competitors ADD COLUMN country VARCHAR(100) NULL AFTER club;
-- @guard: column-missing competitors.city
ALTER TABLE competitors ADD COLUMN city VARCHAR(120) NULL AFTER country;
-- @guard: column-missing competitors.postal_code
ALTER TABLE competitors ADD COLUMN postal_code VARCHAR(20) NULL AFTER city;
-- @guard: column-missing competitors.voivodeship
ALTER TABLE competitors ADD COLUMN voivodeship VARCHAR(100) NULL AFTER postal_code;
-- @guard: column-missing competitors.address
ALTER TABLE competitors ADD COLUMN address VARCHAR(255) NULL AFTER voivodeship;
