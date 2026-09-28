<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\MigrationGuard;
use ZmierzymyCzas\Database\Migrator;

/**
 * Static lint over every file in database/migrations, run with no database at all.
 *
 * Production and staging run MySQL 8.4; MariaDB-only `IF [NOT] EXISTS` on an ALTER
 * sub-clause is a syntax error there. This is a ratchet: it must keep passing as new
 * migrations are added by any lane.
 */
final class MigrationPortabilityTest extends TestCase
{
    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(Migrator::migrationsDir() . '/*.sql') ?: [];
        sort($files);

        self::assertNotEmpty($files, 'Sanity check: the migrations directory must not be empty.');

        return $files;
    }

    public function testNoMariaDbOnlyIfExistsClauses(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);

            foreach (Migrator::statements($sql) as $statement) {
                if (!preg_match('/^\s*(CREATE|DROP)\s+TABLE/i', $statement)) {
                    self::assertDoesNotMatchRegularExpression(
                        '/\bIF\s+(NOT\s+)?EXISTS\b/i',
                        $statement,
                        sprintf(
                            "%s has a MariaDB-only \"IF [NOT] EXISTS\" clause outside CREATE/DROP TABLE:\n%s",
                            basename($file),
                            $statement
                        )
                    );
                }
            }
        }
    }

    public function testEveryStructuralAlterIsSingleClauseAndGuarded(): void
    {
        $structuralVerbPattern = '/\b(ADD\s+COLUMN|DROP\s+COLUMN|ADD\s+(UNIQUE\s+)?(INDEX|KEY)'
            . '|DROP\s+(INDEX|KEY)|ADD\s+CONSTRAINT|DROP\s+FOREIGN\s+KEY)\b/i';

        foreach ($this->migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);
            $steps = Migrator::parse($sql);

            foreach ($steps as $step) {
                if ($step['type'] !== 'sql') {
                    continue;
                }

                $statement = $step['statement'];
                if (!preg_match('/^\s*ALTER\s+TABLE/i', $statement)) {
                    continue;
                }

                $clauseCount = preg_match_all($structuralVerbPattern, $statement);
                if ($clauseCount === 0) {
                    // MODIFY-only / other non-structural ALTERs are not guardable and don't need one.
                    continue;
                }

                self::assertSame(
                    1,
                    $clauseCount,
                    sprintf(
                        "%s has an ALTER with %d structural clauses; each must be its own single-clause ALTER:\n%s",
                        basename($file),
                        $clauseCount,
                        $statement
                    )
                );

                self::assertNotNull(
                    $step['guard'],
                    sprintf("%s has an unguarded structural ALTER:\n%s", basename($file), $statement)
                );

                $guard = $step['guard'];
                self::assertContains(
                    $guard['kind'],
                    MigrationGuard::KINDS,
                    sprintf('%s: unknown guard kind "%s"', basename($file), $guard['kind'])
                );

                self::assertMatchesRegularExpression(
                    '/ALTER\s+TABLE\s+`?' . preg_quote($guard['table'], '/') . '`?\b/i',
                    $statement,
                    sprintf('%s: guard table "%s" does not match the ALTER statement', basename($file), $guard['table'])
                );

                self::assertStringContainsStringIgnoringCase(
                    $guard['object'],
                    $statement,
                    sprintf('%s: guard object "%s" does not occur in the clause', basename($file), $guard['object'])
                );

                $expectedKind = match (true) {
                    (bool) preg_match('/\bADD\s+COLUMN\b/i', $statement) => 'column-missing',
                    (bool) preg_match('/\bDROP\s+COLUMN\b/i', $statement) => 'column-exists',
                    (bool) preg_match('/\bADD\s+(UNIQUE\s+)?(INDEX|KEY)\b/i', $statement) => 'index-missing',
                    (bool) preg_match('/\bDROP\s+(INDEX|KEY)\b/i', $statement) => 'index-exists',
                    (bool) preg_match('/\bADD\s+CONSTRAINT\b.*\bFOREIGN\s+KEY\b/is', $statement) => 'foreign-key-missing',
                    default => null,
                };

                self::assertSame(
                    $expectedKind,
                    $guard['kind'],
                    sprintf('%s: guard kind "%s" does not match clause verb', basename($file), $guard['kind'])
                );
            }
        }
    }

    public function testNoLiteralDefaultOnTextBlobJson(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);

            foreach (Migrator::statements($sql) as $statement) {
                self::assertDoesNotMatchRegularExpression(
                    '/\b(TINY|MEDIUM|LONG)?(TEXT|BLOB)\b[^,()]*\bDEFAULT\s+\'/i',
                    $statement,
                    sprintf('%s: TEXT/BLOB column with a literal DEFAULT (MySQL 8 error 1101):%s%s', basename($file), "\n", $statement)
                );
                self::assertDoesNotMatchRegularExpression(
                    '/\bJSON\b[^,()]*\bDEFAULT\s+\'/i',
                    $statement,
                    sprintf('%s: JSON column with a literal DEFAULT (MySQL 8 error 1101):%s%s', basename($file), "\n", $statement)
                );
            }
        }
    }

    public function testNoCreateIndexStatements(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);

            foreach (Migrator::statements($sql) as $statement) {
                self::assertDoesNotMatchRegularExpression(
                    '/^\s*CREATE\s+(UNIQUE\s+)?INDEX\b/i',
                    $statement,
                    sprintf('%s: bare CREATE INDEX is not guardable on MySQL — use ALTER TABLE ... ADD INDEX with a guard', basename($file))
                );
            }
        }
    }
}
