INSERT INTO users (id, name, email, password_hash, role, phone, birth_year, gender, club, company_name, status, event_limit)
VALUES
('test-participant', 'Test Participant', 'test.participant@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'participant', '510000001', 1992, 'M', 'Test Club', NULL, 'active', 0),
('test-organizer', 'Test Organizer', 'test.organizer@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'organizer', '510000002', NULL, NULL, NULL, 'Test Organizer Sp. z o.o.', 'active', 11),
('test-moderator', 'Test Moderator', 'test.moderator@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'moderator', '510000003', NULL, NULL, NULL, NULL, 'active', 0),
('test-admin', 'Test Admin', 'test.admin@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'admin', '510000004', NULL, NULL, NULL, NULL, 'active', 0),
('test-superadmin', 'Test Superadmin', 'test.superadmin@zmierzymyczas.pl', '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK', 'superadmin', '510000005', NULL, NULL, NULL, NULL, 'active', 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  phone = VALUES(phone),
  birth_year = VALUES(birth_year),
  gender = VALUES(gender),
  club = VALUES(club),
  company_name = VALUES(company_name),
  status = VALUES(status),
  event_limit = VALUES(event_limit);

INSERT INTO organizer_event_credit_batches (id, organizer_id, event_credit_kind, total_count, used_count, source_type, source_id, created_at, expires_at)
VALUES
('credit-test-organizer-standard-demo', 'test-organizer', 'standard', 8, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-test-organizer-virtual-demo', 'test-organizer', 'virtual', 2, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL),
('credit-test-organizer-timing-demo', 'test-organizer', 'timing', 1, 0, 'demo_seed', NULL, '2026-01-01T00:00:00+00:00', NULL)
ON DUPLICATE KEY UPDATE
  organizer_id = VALUES(organizer_id),
  event_credit_kind = VALUES(event_credit_kind),
  total_count = VALUES(total_count),
  source_type = VALUES(source_type),
  created_at = VALUES(created_at),
  expires_at = VALUES(expires_at);
