Files removed from the seed/migration pipeline, kept here because this project is not
under version control yet. Safe to delete once the new flow is confirmed working.

- 003_test_login_users.sql  was a MIGRATION that inserted five well-known accounts
  (including a superadmin with a committed password hash) on every environment,
  production included. The same accounts are seeded by database/seeders/001_demo.sql.
- 002_test_events.sql       generated output of database/seeders/generate_events.php;
  bin/seed.php now regenerates it into database/seeders/.generated/ at seed time.
- apply_seed.php            third parallel seeding path, superseded by bin/seed.php.
