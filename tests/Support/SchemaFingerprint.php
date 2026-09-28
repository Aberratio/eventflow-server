<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Support;

use PDO;

/**
 * A deterministic, engine-agnostic-in-shape text dump of the current database's
 * schema, used to compare "was this DB built by a fresh apply?" against "was it
 * built by migrating an existing/upgraded DB?" (PP.13 upgrade scenarios). Only
 * meaningful comparing two fingerprints taken on the SAME engine — MySQL and MariaDB
 * render some types/defaults differently even for identical DDL.
 */
final class SchemaFingerprint
{
    public static function of(PDO $pdo): string
    {
        $lines = [];

        $columns = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME NOT IN ('schema_migrations', 'seed_records')"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $row) {
            $lines[] = 'COLUMN ' . implode('|', [
                $row['TABLE_NAME'], $row['COLUMN_NAME'], $row['COLUMN_TYPE'],
                $row['IS_NULLABLE'], $row['COLUMN_DEFAULT'] ?? 'NULL', $row['EXTRA'],
            ]);
        }

        $statistics = $pdo->query(
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME NOT IN ('schema_migrations', 'seed_records')"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($statistics as $row) {
            $lines[] = 'INDEX ' . implode('|', [
                $row['TABLE_NAME'], $row['INDEX_NAME'], $row['NON_UNIQUE'], $row['SEQ_IN_INDEX'], $row['COLUMN_NAME'],
            ]);
        }

        $foreignKeys = $pdo->query(
            "SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.UPDATE_RULE, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME NOT IN ('schema_migrations', 'seed_records')
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreignKeys as $row) {
            $lines[] = 'FK ' . implode('|', [
                $row['CONSTRAINT_NAME'], $row['TABLE_NAME'], $row['COLUMN_NAME'],
                $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME'],
                $row['UPDATE_RULE'], $row['DELETE_RULE'],
            ]);
        }

        sort($lines);

        return implode("\n", $lines);
    }
}
