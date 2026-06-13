SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE password_resets;
TRUNCATE TABLE online_sessions;
TRUNCATE TABLE security_settings;
TRUNCATE TABLE activity_logs;
TRUNCATE TABLE partners;
TRUNCATE TABLE info_pages;
TRUNCATE TABLE team_results;
TRUNCATE TABLE results;
TRUNCATE TABLE registration_payment_details;
TRUNCATE TABLE registration_merchandise_orders;
TRUNCATE TABLE registrations;
TRUNCATE TABLE event_result_documents;
TRUNCATE TABLE event_gallery_albums;
TRUNCATE TABLE event_discount_codes;
TRUNCATE TABLE event_merchandise;
TRUNCATE TABLE event_payment_methods;
TRUNCATE TABLE event_distances;
TRUNCATE TABLE challenge_entries;
TRUNCATE TABLE challenge_participations;
TRUNCATE TABLE challenge_products;
TRUNCATE TABLE challenge_events;
TRUNCATE TABLE challenges;
TRUNCATE TABLE series_products;
TRUNCATE TABLE shop_products;
TRUNCATE TABLE shop_categories;
TRUNCATE TABLE series_events;
TRUNCATE TABLE organizer_event_credit_usages;
TRUNCATE TABLE organizer_event_credit_batches;
TRUNCATE TABLE events;
TRUNCATE TABLE series;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS=1;

INSERT INTO users (id, name, email, password_hash, role, phone, birth_year, gender, club, company_name, status, event_limit) VALUES
('u-participant', 'Jan Kowalski', 'jan@example.com', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'participant', '500100200', 1990, 'M', 'Biegacze Warszawy', NULL, 'active', 0),
('u-anna', 'Anna Zielinska', 'anna@example.com', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'participant', '501101202', 1994, 'K', 'Run Club Lodz', NULL, 'active', 0),
('u-piotr', 'Piotr Wisniewski', 'piotr@example.com', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'participant', '502202303', 1986, 'M', 'Tri Team Gdansk', NULL, 'active', 0),
('u-kasia', 'Katarzyna Wozniak', 'kasia@example.com', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'participant', '503303404', 1998, 'K', 'Rowerowy Krakow', NULL, 'active', 0),
('u-organizer', 'Marek Nowak', 'marek@timing.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'organizer', '500200300', NULL, NULL, NULL, 'Timing Pro', 'active', 11),
('u-organizer-2', 'Ewa Mazur', 'ewa@runpoland.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'organizer', '504404505', NULL, NULL, NULL, 'RunPoland', 'active', 11),
('u-organizer-3', 'Tomasz Lewandowski', 'tomasz@sportevents.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'organizer', '505505606', NULL, NULL, NULL, 'Sportowe Wydarzenia', 'active', 11),
('u-moderator', 'Monika Zielinska', 'monika@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'moderator', NULL, NULL, NULL, NULL, NULL, 'active', 0),
('u-admin', 'Admin ZmierzymyCzas', 'admin@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'admin', NULL, NULL, NULL, NULL, NULL, 'active', 0),
('u-superadmin', 'Superadmin ZmierzymyCzas', 'superadmin@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'superadmin', NULL, NULL, NULL, NULL, NULL, 'active', 0),
('test-participant', 'Test Participant', 'test.participant@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'participant', '510000001', 1992, 'M', 'Test Club', NULL, 'active', 0),
('test-organizer', 'Test Organizer', 'test.organizer@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'organizer', '510000002', NULL, NULL, NULL, 'Test Organizer Sp. z o.o.', 'active', 11),
('test-moderator', 'Test Moderator', 'test.moderator@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'moderator', '510000003', NULL, NULL, NULL, NULL, 'active', 0),
('test-admin', 'Test Admin', 'test.admin@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'admin', '510000004', NULL, NULL, NULL, NULL, 'active', 0),
('test-superadmin', 'Test Superadmin', 'test.superadmin@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'superadmin', '510000005', NULL, NULL, NULL, NULL, 'active', 0);

INSERT INTO organizer_event_credit_batches (id, organizer_id, event_credit_kind, total_count, used_count, source_type, source_id, created_at, expires_at) VALUES
('credit-u-organizer-standard-demo', 'u-organizer', 'standard', 8, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-virtual-demo', 'u-organizer', 'virtual', 2, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-timing-demo', 'u-organizer', 'timing', 1, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-2-standard-demo', 'u-organizer-2', 'standard', 8, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-2-virtual-demo', 'u-organizer-2', 'virtual', 2, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-2-timing-demo', 'u-organizer-2', 'timing', 1, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-3-standard-demo', 'u-organizer-3', 'standard', 8, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-3-virtual-demo', 'u-organizer-3', 'virtual', 2, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-u-organizer-3-timing-demo', 'u-organizer-3', 'timing', 1, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-test-organizer-standard-demo', 'test-organizer', 'standard', 8, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-test-organizer-virtual-demo', 'test-organizer', 'virtual', 2, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-test-organizer-timing-demo', 'test-organizer', 'timing', 1, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL);

INSERT INTO series (id, name, slug, type, description, organizer_id, organizer_name, rules, start_date, end_date, status) VALUES
('s-road-cup', 'Puchar Szosowy 2026', 'puchar-szosowy-2026', 'cup', 'Cykl lokalnych zawodow dla amatorow i klubow.', 'u-organizer', 'Marek Nowak', 'Punktacja wedlug miejsc open.', '2026-03-01', '2026-10-31', 'active'),
('s-city-running', 'City Running Tour 2026', 'city-running-tour-2026', 'cup', 'Miejskie biegi na 5 km, 10 km i polmaraton.', 'u-organizer-2', 'Ewa Mazur', 'Do klasyfikacji licza sie trzy najlepsze starty.', '2026-04-01', '2026-11-30', 'active'),
('s-tri-masters', 'Tri Masters 2026', 'tri-masters-2026', 'championship', 'Cykl triathlonowy dla zawodnikow indywidualnych i sztafet.', 'u-organizer-3', 'Tomasz Lewandowski', 'Punktacja open oraz kategorie wiekowe.', '2026-06-01', '2026-09-30', 'upcoming');

INSERT INTO events (id, name, slug, discipline, date, location, city, description, image_url, series_id, organizer_id, organizer_name, organizer_contact, status, registration_deadline, featured, is_live, registration_status, is_virtual, payment_config, rules_html, require_final_acceptance, is_private, registration_form_config, display_options)
VALUES
('e-warsaw-10k', 'Warszawska Dycha 2026', 'warszawska-dycha-2026', 'running', '2026-06-14', 'Park Skaryszewski', 'Warszawa', 'Szybki bieg uliczny na 10 km.', 'https://images.unsplash.com/photo-1502904550040-7534597429ae', 's-road-cup', 'u-organizer', 'Marek Nowak', JSON_OBJECT('email','marek@timing.pl','phone','500200300'), 'active', '2026-06-07', 1, 0, 'open', 0, JSON_OBJECT('type','organizer_bank','bankAccountNumber','12 3456 7890 1234 5678 9012 3456','bankAccountHolder','Timing Pro','transferTitle','Warszawska Dycha'), '<p>Regulamin wydarzenia.</p>', 1, 0, JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',true,'collectInsurance',true,'collectMerchandise',true,'customFields',JSON_ARRAY()), JSON_OBJECT('defaultSort','bib','uppercaseNames',false,'hideUnpaid',false));

INSERT INTO events (id, name, slug, discipline, date, location, city, description, image_url, series_id, organizer_id, organizer_name, organizer_contact, status, registration_deadline, featured, live_results_url, is_live, registration_status, is_virtual, start_date, end_date, payment_config, rules_html, require_final_acceptance, is_private, registration_embed, registration_form_config, display_options)
VALUES
('e-lodz-live', 'City Trail Lodz LIVE 2026', 'city-trail-lodz-live-2026', 'running', '2026-05-02', 'Park im. Poniatowskiego', 'Lodz', 'Bieg przelajowy transmitowany z wynikami na zywo.', 'https://images.unsplash.com/photo-1571008887538-b36bb32f4571?w=1200', 's-city-running', 'u-organizer-2', 'Ewa Mazur', JSON_OBJECT('email','ewa@runpoland.pl','phone','504404505','website','https://runpoland.pl'), 'active', '2026-04-29', 1, 'https://wyniki.example.com/city-trail-lodz-live', 1, 'closed', 0, NULL, NULL, JSON_OBJECT('type','admin_gateway'), '<p>Start falami co 5 minut. Wyniki sa aktualizowane po kazdym punkcie pomiarowym.</p>', 1, 0, JSON_OBJECT('enabled',true,'startDate','2026-02-01','endDate','2026-04-29'), JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',false,'collectInsurance',true,'collectMerchandise',true,'customFields',JSON_ARRAY(JSON_OBJECT('id','ice','label','Telefon ICE','type','text','required',true))), JSON_OBJECT('defaultSort','distance','uppercaseNames',true,'hideUnpaid',false)),
('e-krakow-bike', 'Krakowski Klasyk Rowerowy 2026', 'krakowski-klasyk-rowerowy-2026', 'cycling', '2026-07-05', 'Blonia Krakowskie', 'Krakow', 'Szosowy wyscig dla amatorow z pomiarem czasu na dwoch dystansach.', 'https://images.unsplash.com/photo-1541625602330-2277a4c46182?w=1200', 's-road-cup', 'u-organizer', 'Marek Nowak', JSON_OBJECT('email','marek@timing.pl','phone','500200300'), 'active', '2026-06-28', 1, NULL, 0, 'open', 0, NULL, NULL, JSON_OBJECT('type','organizer_bank','bankAccountNumber','98 7654 3210 9876 5432 1098 7654','bankAccountHolder','Timing Pro','transferTitle','Krakowski Klasyk'), '<p>Kask jest obowiazkowy na calej trasie.</p>', 1, 0, JSON_OBJECT('enabled',true,'startDate','2026-03-01','endDate','2026-06-28'), JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',true,'collectInsurance',false,'collectMerchandise',true,'customFields',JSON_ARRAY()), JSON_OBJECT('defaultSort','bib','uppercaseNames',false,'hideUnpaid',false)),
('e-mazury-tri', 'Triathlon Mazury 2026', 'triathlon-mazury-2026', 'triathlon', '2026-08-15', 'Marina Gizycko', 'Gizycko', 'Triathlon nad jeziorem Niegocin z dystansem olimpijskim i sprintem.', 'https://images.unsplash.com/photo-1530549387789-4c1017266635?w=1200', 's-tri-masters', 'u-organizer-3', 'Tomasz Lewandowski', JSON_OBJECT('email','tomasz@sportevents.pl','phone','505505606'), 'active', '2026-08-08', 1, NULL, 0, 'open', 0, NULL, NULL, JSON_OBJECT('type','admin_gateway'), '<p>Obowiazuje odprawa techniczna przed startem.</p>', 1, 0, JSON_OBJECT('enabled',true,'startDate','2026-04-01','endDate','2026-08-08'), JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',true,'collectInsurance',true,'collectMerchandise',false,'customFields',JSON_ARRAY()), JSON_OBJECT('defaultSort','distance','uppercaseNames',false,'hideUnpaid',false)),
('e-gdynia-half', 'Polmaraton Gdynia 2026', 'polmaraton-gdynia-2026', 'running', '2026-11-08', 'Skwer Kosciuszki', 'Gdynia', 'Jesienny polmaraton z szybka trasa wzdluz wybrzeza.', 'https://images.unsplash.com/photo-1513593771513-7b58b6c4af38?w=1200', 's-city-running', 'u-organizer-2', 'Ewa Mazur', JSON_OBJECT('email','ewa@runpoland.pl','phone','504404505'), 'active', '2026-11-01', 1, NULL, 0, 'open', 0, NULL, NULL, JSON_OBJECT('type','admin_gateway'), '<p>Limit czasu wynosi 3 godziny.</p>', 0, 0, JSON_OBJECT('enabled',true,'startDate','2026-05-01','endDate','2026-11-01'), JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',false,'collectInsurance',true,'collectMerchandise',true,'customFields',JSON_ARRAY()), JSON_OBJECT('defaultSort','bib','uppercaseNames',false,'hideUnpaid',false)),
('e-poznan-five', 'Poznanska Piatka 2026', 'poznanska-piatka-2026', 'running', '2026-04-12', 'Malta', 'Poznan', 'Zakonczony bieg na 5 km z kompletem wynikow open.', 'https://images.unsplash.com/photo-1552674605-db6ffd4facb5?w=1200', 's-city-running', 'u-organizer-2', 'Ewa Mazur', JSON_OBJECT('email','ewa@runpoland.pl','phone','504404505'), 'past', '2026-04-05', 0, NULL, 0, 'closed', 0, NULL, NULL, JSON_OBJECT('type','admin_gateway'), '<p>Wyniki zostaly zatwierdzone przez sedziego glownego.</p>', 0, 0, JSON_OBJECT('enabled',false), JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',false,'collectInsurance',false,'collectMerchandise',false,'customFields',JSON_ARRAY()), JSON_OBJECT('defaultSort','time','uppercaseNames',false,'hideUnpaid',false)),
('e-wroclaw-night', 'Nocny Bieg Wroclaw 2026', 'nocny-bieg-wroclaw-2026', 'running', '2026-03-21', 'Hala Stulecia', 'Wroclaw', 'Zakonczony nocny bieg uliczny z galeria i wynikami.', 'https://images.unsplash.com/photo-1551632811-561732d1e306?w=1200', NULL, 'u-organizer-3', 'Tomasz Lewandowski', JSON_OBJECT('email','tomasz@sportevents.pl','phone','505505606'), 'past', '2026-03-14', 0, NULL, 0, 'closed', 0, NULL, NULL, JSON_OBJECT('type','organizer_bank','bankAccountNumber','44 1111 2222 3333 4444 5555 6666','bankAccountHolder','Sportowe Wydarzenia','transferTitle','Nocny Bieg Wroclaw'), '<p>Trasa atestowana PZLA.</p>', 0, 0, JSON_OBJECT('enabled',false), JSON_OBJECT('collectPhone',true,'collectClub',false,'collectBirthDate',true,'collectTeam',false,'collectInsurance',false,'collectMerchandise',false,'customFields',JSON_ARRAY()), JSON_OBJECT('defaultSort','time','uppercaseNames',true,'hideUnpaid',false)),
('e-virtual-spring', 'Wirtualny Maraton Wiosenny 2026', 'wirtualny-maraton-wiosenny-2026', 'running', '2026-05-15', 'Wydarzenie wirtualne', 'Caly swiat', 'Wyzwanie wirtualne z mozliwoscia przeslania wyniku z aplikacji sportowej.', 'https://images.unsplash.com/photo-1544191696-15693072e0b5?w=1200', NULL, 'u-organizer-2', 'Ewa Mazur', JSON_OBJECT('email','ewa@runpoland.pl','phone','504404505'), 'active', '2026-05-20', 0, NULL, 0, 'open', 1, '2026-05-15', '2026-05-31', JSON_OBJECT('type','admin_gateway'), '<p>Wynik mozna dodac do konca maja.</p>', 1, 0, JSON_OBJECT('enabled',true,'startDate','2026-04-01','endDate','2026-05-20'), JSON_OBJECT('collectPhone',true,'collectClub',true,'collectBirthDate',true,'collectTeam',false,'collectInsurance',false,'collectMerchandise',true,'customFields',JSON_ARRAY(JSON_OBJECT('id','app','label','Aplikacja pomiarowa','type','select','required',false,'options',JSON_ARRAY('Garmin','Strava','Polar','Inna')))), JSON_OBJECT('defaultSort','distance','uppercaseNames',false,'hideUnpaid',false));

UPDATE events SET event_credit_kind = 'virtual' WHERE is_virtual = 1;

INSERT INTO series_events (series_id, event_id, sort_order) VALUES
('s-road-cup', 'e-warsaw-10k', 0),
('s-road-cup', 'e-krakow-bike', 1),
('s-city-running', 'e-poznan-five', 0),
('s-city-running', 'e-lodz-live', 1),
('s-city-running', 'e-gdynia-half', 2),
('s-tri-masters', 'e-mazury-tri', 0);

INSERT INTO shop_categories (id, name, sort_order, status) VALUES
('apparel', 'Odziez', 0, 'active'),
('accessories', 'Akcesoria', 1, 'active'),
('nutrition', 'Odzywianie', 2, 'active'),
('services', 'Uslugi', 3, 'active'),
('rewards', 'Nagrody', 4, 'active');

INSERT INTO shop_products (id, name, slug, description, price, original_price, category, brand, sizes, colors, image_url, images, in_stock, stock_count, rating, review_count, featured, is_service, service_details, validity_days, visible_to_roles, service_kind, status) VALUES
('prod-korona-medal', 'Medal Korony Polmaratonow', 'medal-korony-polmaratonow', 'Ekskluzywny medal z grawerem dla uczestnikow wyzwania.', 45.00, NULL, 'rewards', 'ZmierzymyCzas', JSON_ARRAY(), JSON_ARRAY(JSON_OBJECT('id','gold','name','Zloty','hex','#D4AF37')), 'https://images.unsplash.com/photo-1567427017947-545c5f8d16ad?w=800', JSON_ARRAY(), 1, 500, 4.90, 24, 1, 0, NULL, NULL, JSON_ARRAY('participant'), NULL, 'active'),
('prod-korona-dyplom', 'Dyplom Korony PDF', 'dyplom-korony-pdf', 'Darmowy dyplom PDF z lista ukonczonych wydarzen.', 0.00, NULL, 'rewards', 'ZmierzymyCzas', JSON_ARRAY(), JSON_ARRAY(), 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?w=800', JSON_ARRAY(), 1, NULL, 5.00, 12, 0, 1, 'Generowany po ukonczeniu wyzwania.', NULL, JSON_ARRAY('participant'), NULL, 'active'),
('prod-5dych-shirt', 'Koszulka 5 Dych', 'koszulka-5-dych', 'Techniczna koszulka dla finalistow wyzwania 5 Dych.', 35.00, NULL, 'apparel', 'ZmierzymyCzas', JSON_ARRAY('S','M','L','XL'), JSON_ARRAY(JSON_OBJECT('id','black','name','Czarny','hex','#111111')), 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?w=800', JSON_ARRAY(), 1, 200, 4.70, 31, 1, 0, NULL, NULL, JSON_ARRAY('participant'), NULL, 'active'),
('prod-city-pack', 'Pakiet City Running Tour', 'pakiet-city-running-tour', 'Pakiet gadzetow dla uczestnikow serii miejskiej.', 79.00, 99.00, 'accessories', 'RunPoland', JSON_ARRAY(), JSON_ARRAY(), 'https://images.unsplash.com/photo-1506629905607-d9c297d3a5a4?w=800', JSON_ARRAY(), 1, 150, 4.60, 18, 1, 0, NULL, NULL, JSON_ARRAY('participant'), NULL, 'active'),
('prod-organizer-license', 'Pakiet organizatora START', 'pakiet-organizatora-start', 'Aktywacja dodatkowych slotow wydarzen dla organizatora.', 499.00, NULL, 'services', 'ZmierzymyCzas', JSON_ARRAY(), JSON_ARRAY(), 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800', JSON_ARRAY(), 1, NULL, NULL, NULL, 0, 1, 'Pakiet aktywowany po oplaceniu zamowienia.', 365, JSON_ARRAY('organizer','admin','superadmin'), 'organizer_license', 'active');

INSERT INTO series_products (series_id, product_id, purpose, sort_order) VALUES
('s-city-running', 'prod-city-pack', 'reward', 0),
('s-road-cup', 'prod-organizer-license', 'addon', 0);

INSERT INTO challenges (id, name, slug, description, image_url, series_id, min_completed, max_time, start_date, end_date, status, created_by) VALUES
('ch-korona-half', 'Korona Polmaratonow 2026', 'korona-polmaratonow-2026', 'Ukoncz wybrane polmaratony z kalendarza i zdobadz medal oraz dyplom.', 'https://images.unsplash.com/photo-1552674605-db6ffd4facb5?w=1200', 's-city-running', 2, NULL, '2026-01-01', '2026-12-31', 'active', 'u-admin'),
('ch-5-dych', 'Wyzwanie 5 Dych', 'wyzwanie-5-dych', 'Ukoncz biegi na krotszych dystansach i odbierz okolicznosciowa koszulke.', 'https://images.unsplash.com/photo-1461896836934-bd45ba20b2a7?w=1200', 's-city-running', 2, '01:15:00', '2026-03-01', '2026-12-31', 'active', 'u-admin');

INSERT INTO challenge_events (challenge_id, event_id, sort_order) VALUES
('ch-korona-half', 'e-gdynia-half', 0),
('ch-korona-half', 'e-lodz-live', 1),
('ch-korona-half', 'e-poznan-five', 2),
('ch-5-dych', 'e-warsaw-10k', 0),
('ch-5-dych', 'e-poznan-five', 1),
('ch-5-dych', 'e-wroclaw-night', 2);

INSERT INTO challenge_products (challenge_id, product_id, purpose, sort_order) VALUES
('ch-korona-half', 'prod-korona-medal', 'reward', 0),
('ch-korona-half', 'prod-korona-dyplom', 'reward', 1),
('ch-5-dych', 'prod-5dych-shirt', 'reward', 0);

INSERT INTO challenge_participations (id, challenge_id, user_id, user_name, joined_at, reward_granted, reward_granted_at, selected_rewards, assigned_number) VALUES
('cp-jan-korona', 'ch-korona-half', 'u-participant', 'Jan Kowalski', '2026-02-01T10:00:00+01:00', 0, NULL, JSON_ARRAY(JSON_OBJECT('rewardId','prod-korona-medal')), 42),
('cp-anna-korona', 'ch-korona-half', 'u-anna', 'Anna Zielinska', '2026-02-05T10:00:00+01:00', 1, '2026-04-13T12:00:00+02:00', JSON_ARRAY(JSON_OBJECT('rewardId','prod-korona-dyplom')), 43),
('cp-jan-5dych', 'ch-5-dych', 'u-participant', 'Jan Kowalski', '2026-03-15T10:00:00+01:00', 0, NULL, JSON_ARRAY(JSON_OBJECT('rewardId','prod-5dych-shirt','size','M')), NULL);

INSERT INTO challenge_entries (id, challenge_id, user_id, event_id, status, submitted_time, submitted_position, proof_url, submitted_at, reviewed_at, reviewed_by, rejection_reason, source) VALUES
('ce-jan-korona-poznan', 'ch-korona-half', 'u-participant', 'e-poznan-five', 'approved', '00:19:48', 3, NULL, '2026-04-12T14:00:00+02:00', '2026-04-12T14:00:00+02:00', 'system', NULL, 'result'),
('ce-anna-korona-poznan', 'ch-korona-half', 'u-anna', 'e-poznan-five', 'approved', '00:21:05', 7, NULL, '2026-04-12T14:00:00+02:00', '2026-04-12T14:00:00+02:00', 'system', NULL, 'result'),
('ce-anna-korona-lodz', 'ch-korona-half', 'u-anna', 'e-lodz-live', 'approved', '00:32:18', 8, NULL, '2026-05-02T14:00:00+02:00', '2026-05-02T14:00:00+02:00', 'system', NULL, 'result'),
('ce-jan-5dych-warsaw', 'ch-5-dych', 'u-participant', 'e-warsaw-10k', 'approved', '00:44:12', 1, NULL, '2026-06-14T14:00:00+02:00', '2026-06-14T14:00:00+02:00', 'system', NULL, 'result'),
('ce-jan-5dych-manual', 'ch-5-dych', 'u-participant', 'e-wroclaw-night', 'pending', '00:43:30', 10, 'https://wyniki.example.com/nocny-bieg-wroclaw/101', '2026-03-22T10:00:00+01:00', NULL, NULL, NULL, 'manual');

INSERT INTO event_distances (id, event_id, name, length, unit, categories, participant_limit, registered_count, price, price_tier_mode, participant_count_type, price_tiers, sort_order)
VALUES
('d-10k', 'e-warsaw-10k', '10 km', 10.000, 'km', JSON_ARRAY('OPEN','M30','K30'), 500, 1, 89.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt1','name','Early bird','discountType','fixed','discountValue',20,'endDate','2026-05-31')), 0);

INSERT INTO event_distances (id, event_id, name, length, unit, categories, age_categories, special_categories, participant_limit, registered_count, price, price_tier_mode, participant_count_type, price_tiers, vip_prices, route_map_url, route_elevation_profile, route_gpx_url, route_files, sort_order)
VALUES
('d-lodz-15k', 'e-lodz-live', '15 km', 15.000, 'km', JSON_ARRAY('OPEN','M18','M30','M40','K18','K30','K40'), JSON_ARRAY('18-29','30-39','40+'), JSON_ARRAY('Najlepszy lodzianin'), 600, 238, 90.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-lodz-1','name','Cena regularna','discountType','percent','discountValue',0,'endDate','2026-04-20')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-lodz-7k', 'e-lodz-live', '7 km', 7.000, 'km', JSON_ARRAY('OPEN','M','K'), JSON_ARRAY('Open'), JSON_ARRAY(), 800, 462, 60.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-lodz-2','name','Cena regularna','discountType','percent','discountValue',0,'endDate','2026-04-20')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 1),
('d-bike-90', 'e-krakow-bike', '90 km', 90.000, 'km', JSON_ARRAY('OPEN','M40','K40'), JSON_ARRAY('18-39','40+'), JSON_ARRAY('Druzyny'), 350, 121, 149.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-bike-1','name','Wczesna rezerwacja','discountType','fixed','discountValue',30,'endDate','2026-05-31')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-bike-45', 'e-krakow-bike', '45 km', 45.000, 'km', JSON_ARRAY('OPEN','M','K'), JSON_ARRAY('Open'), JSON_ARRAY(), 500, 247, 99.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-bike-2','name','Wczesna rezerwacja','discountType','fixed','discountValue',20,'endDate','2026-05-31')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 1),
('d-tri-olimp', 'e-mazury-tri', 'Olimpijski', 51.500, 'km', JSON_ARRAY('OPEN','M30','M40','K30','K40'), JSON_ARRAY('18-29','30-39','40+'), JSON_ARRAY('Sztafety'), 700, 318, 320.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-tri-1','name','Early bird','discountType','fixed','discountValue',60,'endDate','2026-06-30')), JSON_ARRAY(JSON_OBJECT('id','vip-tri-1','name','Mistrzostwa klubowe','discountType','percent','discountValue',20)), NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-tri-sprint', 'e-mazury-tri', 'Sprint', 25.750, 'km', JSON_ARRAY('OPEN','M','K'), JSON_ARRAY('Open'), JSON_ARRAY('Debiut'), 900, 451, 220.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-tri-2','name','Early bird','discountType','fixed','discountValue',40,'endDate','2026-06-30')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 1),
('d-gdynia-21k', 'e-gdynia-half', 'Polmaraton', 21.097, 'km', JSON_ARRAY('OPEN','M30','M40','M50','K30','K40','K50'), JSON_ARRAY('18-29','30-39','40-49','50+'), JSON_ARRAY(), 6000, 1234, 120.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-gdynia-1','name','Wczesna rezerwacja','discountType','percent','discountValue',25,'endDate','2026-08-31')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-gdynia-10k', 'e-gdynia-half', '10 km', 10.000, 'km', JSON_ARRAY('OPEN','M','K'), JSON_ARRAY('Open'), JSON_ARRAY(), 3000, 567, 75.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-gdynia-2','name','Wczesna rezerwacja','discountType','fixed','discountValue',15,'endDate','2026-08-31')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 1),
('d-poznan-5k', 'e-poznan-five', '5 km', 5.000, 'km', JSON_ARRAY('OPEN','M','K'), JSON_ARRAY('Open'), JSON_ARRAY(), 1000, 642, 55.00, NULL, NULL, JSON_ARRAY(), NULL, NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-wroclaw-10k', 'e-wroclaw-night', '10 km', 10.000, 'km', JSON_ARRAY('OPEN','M30','K30'), JSON_ARRAY('18-29','30-39','40+'), JSON_ARRAY('Najszybszy team'), 2000, 987, 85.00, NULL, NULL, JSON_ARRAY(), NULL, NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-virtual-10k', 'e-virtual-spring', '10 km', 10.000, 'km', JSON_ARRAY('OPEN'), JSON_ARRAY('Open'), JSON_ARRAY(), 1000, 156, 45.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-virt-1','name','Cena startowa','discountType','percent','discountValue',0,'endDate','2026-05-20')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 0),
('d-virtual-21k', 'e-virtual-spring', 'Polmaraton', 21.097, 'km', JSON_ARRAY('OPEN'), JSON_ARRAY('Open'), JSON_ARRAY(), 500, 87, 65.00, 'date', 'registered', JSON_ARRAY(JSON_OBJECT('id','pt-virt-2','name','Cena startowa','discountType','percent','discountValue',0,'endDate','2026-05-20')), NULL, NULL, NULL, NULL, JSON_ARRAY(), 1);

INSERT INTO event_payment_methods (id, event_id, name, enabled, sort_order) VALUES
('pm-bank', 'e-warsaw-10k', 'Przelew bankowy', 1, 0),
('pm-lodz-card', 'e-lodz-live', 'Karta platnicza', 1, 0),
('pm-lodz-blik', 'e-lodz-live', 'BLIK', 1, 1),
('pm-bike-bank', 'e-krakow-bike', 'Przelew bankowy', 1, 0),
('pm-tri-card', 'e-mazury-tri', 'Karta platnicza', 1, 0),
('pm-gdynia-card', 'e-gdynia-half', 'Karta platnicza', 1, 0),
('pm-virtual-card', 'e-virtual-spring', 'Karta platnicza', 1, 0);

INSERT INTO event_merchandise (id, event_id, name, description, price, sizes, colors, image_url, available, sort_order) VALUES
('m-shirt', 'e-warsaw-10k', 'Koszulka techniczna', 'Lekka koszulka biegowa.', 59.00, JSON_ARRAY('S','M','L','XL'), JSON_ARRAY(JSON_OBJECT('id','black','name','Czarny','hex','#111111')), NULL, 1, 0),
('m-lodz-shirt', 'e-lodz-live', 'Koszulka City Trail', 'Oddychajaca koszulka techniczna.', 79.00, JSON_ARRAY('XS','S','M','L','XL'), JSON_ARRAY(JSON_OBJECT('id','blue','name','Niebieski','hex','#2563EB'), JSON_OBJECT('id','white','name','Bialy','hex','#FFFFFF')), NULL, 1, 0),
('m-bike-bidon', 'e-krakow-bike', 'Bidon kolarski', 'Bidon 750 ml z logo wyscigu.', 35.00, JSON_ARRAY(), JSON_ARRAY(JSON_OBJECT('id','clear','name','Przezroczysty','hex','#E5E7EB')), NULL, 1, 0),
('m-gdynia-medal', 'e-gdynia-half', 'Grawer medalu', 'Personalizowany grawer na medalu.', 29.00, JSON_ARRAY(), JSON_ARRAY(), NULL, 1, 0),
('m-virtual-medal', 'e-virtual-spring', 'Medal z wysylka', 'Medal finishera wysylany poczta.', 49.00, JSON_ARRAY(), JSON_ARRAY(JSON_OBJECT('id','gold','name','Zloty','hex','#D4AF37')), NULL, 1, 0);

INSERT INTO event_discount_codes (id, event_id, code, discount_type, discount_value, max_uses, used_count, distance_ids, active, sort_order) VALUES
('dc-save10', 'e-warsaw-10k', 'SAVE10', 'percent', 10.00, 100, 0, JSON_ARRAY('d-10k'), 1, 0),
('dc-lodz-team', 'e-lodz-live', 'TEAM15', 'percent', 15.00, 50, 12, JSON_ARRAY('d-lodz-15k','d-lodz-7k'), 1, 0),
('dc-bike-club', 'e-krakow-bike', 'KLUB20', 'fixed', 20.00, 80, 9, JSON_ARRAY('d-bike-90','d-bike-45'), 1, 0),
('dc-gdynia-early', 'e-gdynia-half', 'GDYNIA25', 'percent', 25.00, 200, 33, JSON_ARRAY('d-gdynia-21k'), 1, 0);

INSERT INTO event_gallery_albums (id, event_id, name, url, platform, photographer, sort_order) VALUES
('ga-poznan', 'e-poznan-five', 'Poznanska Piatka 2026 - album oficjalny', 'https://photos.example.com/poznanska-piatka-2026', 'google_photos', 'Foto Sport Poznan', 0),
('ga-wroclaw', 'e-wroclaw-night', 'Nocny Bieg Wroclaw - meta', 'https://photos.example.com/nocny-bieg-wroclaw-2026', 'google_photos', 'Night Run Studio', 0),
('ga-lodz', 'e-lodz-live', 'City Trail Lodz - backstage', 'https://instagram.com/city-trail-lodz-2026', 'instagram', 'RunPoland Media', 0);

INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, bib_number, price_paid)
VALUES
('r-jan-10k', 'e-warsaw-10k', 'Warszawska Dycha 2026', 'u-participant', 'Jan Kowalski', 'jan@example.com', 'd-10k', '10 km', 'M30', '2026-05-02T10:00:00+02:00', 'confirmed', '101', 89.00),
('r-anna-lodz', 'e-lodz-live', 'City Trail Lodz LIVE 2026', 'u-anna', 'Anna Zielinska', 'anna@example.com', 'd-lodz-7k', '7 km', 'K30', '2026-04-20T09:15:00+02:00', 'confirmed', '215', 60.00),
('r-piotr-lodz', 'e-lodz-live', 'City Trail Lodz LIVE 2026', 'u-piotr', 'Piotr Wisniewski', 'piotr@example.com', 'd-lodz-15k', '15 km', 'M30', '2026-04-18T18:40:00+02:00', 'confirmed', '42', 90.00),
('r-kasia-bike', 'e-krakow-bike', 'Krakowski Klasyk Rowerowy 2026', 'u-kasia', 'Katarzyna Wozniak', 'kasia@example.com', 'd-bike-45', '45 km', 'K30', '2026-05-01T11:20:00+02:00', 'confirmed', '305', 99.00),
('r-jan-poznan', 'e-poznan-five', 'Poznanska Piatka 2026', 'u-participant', 'Jan Kowalski', 'jan@example.com', 'd-poznan-5k', '5 km', 'M30', '2026-04-01T10:00:00+02:00', 'confirmed', '18', 55.00),
('r-anna-poznan', 'e-poznan-five', 'Poznanska Piatka 2026', 'u-anna', 'Anna Zielinska', 'anna@example.com', 'd-poznan-5k', '5 km', 'K30', '2026-04-01T10:05:00+02:00', 'confirmed', '19', 55.00),
('r-piotr-wroclaw', 'e-wroclaw-night', 'Nocny Bieg Wroclaw 2026', 'u-piotr', 'Piotr Wisniewski', 'piotr@example.com', 'd-wroclaw-10k', '10 km', 'M30', '2026-03-10T14:00:00+01:00', 'confirmed', '77', 85.00),
('r-kasia-wroclaw', 'e-wroclaw-night', 'Nocny Bieg Wroclaw 2026', 'u-kasia', 'Katarzyna Wozniak', 'kasia@example.com', 'd-wroclaw-10k', '10 km', 'K30', '2026-03-10T14:10:00+01:00', 'confirmed', '78', 85.00),
('r-jan-virtual', 'e-virtual-spring', 'Wirtualny Maraton Wiosenny 2026', 'u-participant', 'Jan Kowalski', 'jan@example.com', 'd-virtual-10k', '10 km', 'OPEN', '2026-05-02T12:00:00+02:00', 'pending', NULL, 45.00);

INSERT INTO registration_merchandise_orders (id, registration_id, merchandise_id, merchandise_name, size, color, color_hex, quantity, price) VALUES
('rmo-jan-shirt', 'r-jan-10k', 'm-shirt', 'Koszulka techniczna', 'M', 'Czarny', '#111111', 1, 59.00),
('rmo-anna-lodz', 'r-anna-lodz', 'm-lodz-shirt', 'Koszulka City Trail', 'S', 'Niebieski', '#2563EB', 1, 79.00),
('rmo-kasia-bike', 'r-kasia-bike', 'm-bike-bidon', 'Bidon kolarski', NULL, 'Przezroczysty', '#E5E7EB', 2, 35.00);

INSERT INTO registration_payment_details (registration_id, start_fee_paid, start_fee_paid_at, start_fee_amount, insurance_paid, merchandise_payments)
VALUES
('r-jan-10k', 1, '2026-05-02T10:10:00+02:00', 89.00, 0, JSON_ARRAY(JSON_OBJECT('merchandiseId','m-shirt','paid',true,'amount',59))),
('r-anna-lodz', 1, '2026-04-20T09:20:00+02:00', 60.00, 1, JSON_ARRAY(JSON_OBJECT('merchandiseId','m-lodz-shirt','paid',true,'amount',79))),
('r-piotr-lodz', 1, '2026-04-18T18:45:00+02:00', 90.00, 0, JSON_ARRAY()),
('r-kasia-bike', 1, '2026-05-01T11:30:00+02:00', 99.00, 0, JSON_ARRAY(JSON_OBJECT('merchandiseId','m-bike-bidon','paid',true,'amount',70))),
('r-jan-poznan', 1, '2026-04-01T10:05:00+02:00', 55.00, 0, JSON_ARRAY()),
('r-anna-poznan', 1, '2026-04-01T10:10:00+02:00', 55.00, 0, JSON_ARRAY()),
('r-piotr-wroclaw', 1, '2026-03-10T14:05:00+01:00', 85.00, 0, JSON_ARRAY()),
('r-kasia-wroclaw', 1, '2026-03-10T14:20:00+01:00', 85.00, 0, JSON_ARRAY()),
('r-jan-virtual', 0, NULL, 45.00, 0, JSON_ARRAY());

INSERT INTO results (id, event_id, event_name, distance_id, distance_name, user_id, user_name, bib_number, category, time, time_in_seconds, position, category_position, status, gender, birth_year)
VALUES
('res-jan-10k', 'e-warsaw-10k', 'Warszawska Dycha 2026', 'd-10k', '10 km', 'u-participant', 'Jan Kowalski', '101', 'M30', '00:44:12', 2652, 1, 1, 'finished', 'M', 1990),
('res-anna-lodz', 'e-lodz-live', 'City Trail Lodz LIVE 2026', 'd-lodz-7k', '7 km', 'u-anna', 'Anna Zielinska', '215', 'K30', '00:32:18', 1938, 8, 2, 'finished', 'K', 1994),
('res-piotr-lodz', 'e-lodz-live', 'City Trail Lodz LIVE 2026', 'd-lodz-15k', '15 km', 'u-piotr', 'Piotr Wisniewski', '42', 'M30', '01:04:55', 3895, 4, 1, 'finished', 'M', 1986),
('res-jan-poznan', 'e-poznan-five', 'Poznanska Piatka 2026', 'd-poznan-5k', '5 km', 'u-participant', 'Jan Kowalski', '18', 'M30', '00:19:48', 1188, 3, 1, 'finished', 'M', 1990),
('res-anna-poznan', 'e-poznan-five', 'Poznanska Piatka 2026', 'd-poznan-5k', '5 km', 'u-anna', 'Anna Zielinska', '19', 'K30', '00:21:05', 1265, 7, 1, 'finished', 'K', 1994),
('res-piotr-wroclaw', 'e-wroclaw-night', 'Nocny Bieg Wroclaw 2026', 'd-wroclaw-10k', '10 km', 'u-piotr', 'Piotr Wisniewski', '77', 'M30', '00:41:20', 2480, 5, 2, 'finished', 'M', 1986),
('res-kasia-wroclaw', 'e-wroclaw-night', 'Nocny Bieg Wroclaw 2026', 'd-wroclaw-10k', '10 km', 'u-kasia', 'Katarzyna Wozniak', '78', 'K30', '00:45:44', 2744, 12, 3, 'finished', 'K', 1998);

INSERT INTO team_results (id, event_id, distance_id, distance_name, team_name, members, total_time, total_time_in_seconds, position) VALUES
('team-wroclaw-1', 'e-wroclaw-night', 'd-wroclaw-10k', '10 km', 'Szybki Wroclaw', JSON_ARRAY(JSON_OBJECT('name','Piotr Wisniewski','time','00:41:20'), JSON_OBJECT('name','Katarzyna Wozniak','time','00:45:44')), '01:27:04', 5224, 1);

INSERT INTO event_result_documents (id, event_id, name, type, distance_id, distance_name, file_url, uploaded_at, uploaded_by, description, sort_order)
VALUES
('doc-open', 'e-warsaw-10k', 'Wyniki OPEN', 'open', 'd-10k', '10 km', 'https://example.com/results.pdf', '2026-06-14T14:00:00+02:00', 'Marek Nowak', 'Klasyfikacja open', 0),
('doc-poznan-open', 'e-poznan-five', 'Wyniki OPEN 5 km', 'open', 'd-poznan-5k', '5 km', 'https://example.com/poznan-5k-open.pdf', '2026-04-12T13:00:00+02:00', 'Ewa Mazur', 'Pelna klasyfikacja open', 0),
('doc-wroclaw-open', 'e-wroclaw-night', 'Wyniki OPEN 10 km', 'open', 'd-wroclaw-10k', '10 km', 'https://example.com/wroclaw-night-open.pdf', '2026-03-21T23:30:00+01:00', 'Tomasz Lewandowski', 'Klasyfikacja open i kategorie', 0),
('doc-lodz-live', 'e-lodz-live', 'Wyniki czastkowe LIVE', 'open', 'd-lodz-15k', '15 km', 'https://example.com/lodz-live-split.pdf', '2026-05-02T12:30:00+02:00', 'Ewa Mazur', 'Wyniki aktualizowane w trakcie zawodow', 0);

INSERT INTO info_pages (id, title, slug, icon, description, content, published, show_in_footer, show_in_header, sort_order, created_at, updated_at)
VALUES
('page-about', 'O nas', 'o-nas', 'Info', 'Informacje o platformie.', JSON_ARRAY(JSON_OBJECT('id','hero','type','hero','title','Zmierzymy Czas','content','Platforma zapisow i wynikow sportowych.')), 1, 1, 1, 0, '2026-05-02T10:00:00+02:00', '2026-05-02T10:00:00+02:00'),
('page-offer', 'Oferta', 'oferta', 'Briefcase', 'Pomiar czasu, zapisy i wyniki dla organizatorow.', JSON_ARRAY(JSON_OBJECT('id','hero','type','hero','title','Oferta dla organizatorow','content','Obsluga zapisow, pakietow startowych i wynikow LIVE.'), JSON_OBJECT('id','pricing','type','pricing','title','Pakiety','items',JSON_ARRAY(JSON_OBJECT('name','Start','price','od 499 zl'), JSON_OBJECT('name','Pro','price','wycena indywidualna')))), 1, 1, 1, 1, '2026-05-02T10:00:00+02:00', '2026-05-02T10:00:00+02:00'),
('page-contact', 'Kontakt', 'kontakt', 'Mail', 'Dane kontaktowe zespolu Zmierzymy Czas.', JSON_ARRAY(JSON_OBJECT('id','contact','type','text','title','Napisz do nas','content','E-mail: kontakt@zmierzymyczas.pl Telefon: 500 600 700')), 1, 1, 1, 2, '2026-05-02T10:00:00+02:00', '2026-05-02T10:00:00+02:00');

INSERT INTO partners (id, name, logo_url, website_url, featured, sort_order) VALUES
('partner-pzla', 'Polski Zwiazek Lekkiej Atletyki', 'https://via.placeholder.com/200x80?text=PZLA', 'https://pzla.pl', 1, 0),
('partner-garmin', 'Garmin', 'https://via.placeholder.com/200x80?text=Garmin', 'https://garmin.com', 1, 1),
('partner-salomon', 'Salomon', 'https://via.placeholder.com/200x80?text=Salomon', 'https://salomon.com', 1, 2),
('partner-4f', '4F', 'https://via.placeholder.com/200x80?text=4F', 'https://4f.com.pl', 1, 3),
('partner-compressport', 'Compressport', 'https://via.placeholder.com/200x80?text=Compressport', 'https://compressport.com', 1, 4),
('partner-local', 'Miasto Warszawa', 'https://via.placeholder.com/200x80?text=Warszawa', 'https://warszawa.pl', 0, 5);

INSERT INTO security_settings (id, two_factor_enabled, last_password_change, password_expiry_days, session_timeout_minutes, trusted_devices, login_history)
VALUES ('security-default', 0, '2026-05-02T10:00:00+02:00', 90, 60, JSON_ARRAY(), JSON_ARRAY());

INSERT INTO activity_logs (id, user_id, action, description, target_type, target_id, target_name, timestamp)
VALUES ('log-seed', 'u-admin', 'settings_change', 'Seed demo database', 'settings', NULL, 'Database', '2026-05-02T10:00:00+02:00');

INSERT INTO online_sessions (id, user_id, user_name, role, login_at, last_active_at, ip_address, browser, location)
VALUES ('sess-admin', 'u-admin', 'Admin ZmierzymyCzas', 'admin', '2026-05-02T10:00:00+02:00', '2026-05-02T10:30:00+02:00', '127.0.0.1', 'Local', 'Warszawa'),
('sess-superadmin', 'u-superadmin', 'Superadmin ZmierzymyCzas', 'superadmin', '2026-05-02T10:05:00+02:00', '2026-05-02T10:35:00+02:00', '127.0.0.1', 'Local', 'Warszawa');
