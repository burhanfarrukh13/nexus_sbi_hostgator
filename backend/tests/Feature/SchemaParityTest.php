<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * database.sql (phpMyAdmin import) and the migration are two sources of the same schema.
 * These checks fail when one of them drifts from the other.
 */
class SchemaParityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_11_04_000001_create_test_notebook_schema.php',
        'database/migrations/2026_11_05_000001_create_ai_notebook_chat_tickets.php',
        'database/migrations/2026_11_10_000001_create_team_member_subject_access.php',
        'database/migrations/2026_11_11_000001_add_archived_at_to_tests.php',
    ];

    /** @return array<string, array{columns: list<string>, indexes: list<string>, foreignKeys: list<string>, checks: list<string>}> */
    private function parseSqlDump(): array
    {
        $sql = (string) file_get_contents(dirname(base_path()).DIRECTORY_SEPARATOR.'database.sql');
        self::assertNotSame('', $sql, 'database.sql could not be read.');
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);
        self::assertNotEmpty($tables);

        $schema = [];
        foreach ($tables as [, $name, $body]) {
            preg_match_all('/^\s+`(\w+)`\s+[A-Za-z]/m', $body, $columns);
            preg_match_all('/^\s+(?:UNIQUE )?KEY `(\w+)`/m', $body, $indexes);
            preg_match_all('/CONSTRAINT `(\w+)` FOREIGN KEY/', $body, $foreignKeys);
            preg_match_all('/CONSTRAINT `(\w+)` CHECK/', $body, $checks);
            $schema[$name] = [
                'columns' => $columns[1],
                'indexes' => $indexes[1],
                'foreignKeys' => $foreignKeys[1],
                'checks' => $checks[1],
            ];
        }

        return $schema;
    }

    public function test_both_sources_create_the_same_tables_and_columns(): void
    {
        $dump = $this->parseSqlDump();
        $migrated = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations'"))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $dumpTables = array_keys($dump);
        sort($dumpTables);
        self::assertSame($migrated, $dumpTables, 'database.sql and the migration create different tables.');

        foreach ($migrated as $table) {
            $migratedColumns = collect(DB::select("PRAGMA table_info('{$table}')"))->pluck('name')->sort()->values()->all();
            $dumpColumns = $dump[$table]['columns'];
            sort($dumpColumns);
            self::assertSame($migratedColumns, $dumpColumns, "Columns of {$table} differ between database.sql and the migration.");
        }
    }

    public function test_both_sources_use_the_same_index_names(): void
    {
        $dump = $this->parseSqlDump();
        $migrated = [];
        foreach (DB::select("SELECT name, tbl_name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_autoindex%'") as $index) {
            $migrated[$index->tbl_name][] = $index->name;
        }

        foreach ($dump as $table => $definition) {
            $expected = $definition['indexes'];
            $actual = $migrated[$table] ?? [];
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, "Index names of {$table} differ between database.sql and the migration.");
        }
    }

    public function test_both_sources_use_the_same_foreign_key_and_check_constraint_names(): void
    {
        $migration = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents(base_path($path)),
            self::MIGRATIONS
        ));
        preg_match_all("/->foreign\('[^']+',\s*'(\w+)'\)/", $migration, $foreignKeys);
        preg_match_all('/ADD CONSTRAINT (\w+) CHECK/', $migration, $checks);
        // Every foreign key must be named explicitly, otherwise MySQL would auto-name it.
        self::assertSame(substr_count($migration, '->foreign('), count($foreignKeys[1]), 'Name every foreign key in the migration.');

        $dumpForeignKeys = $dumpChecks = [];
        foreach ($this->parseSqlDump() as $definition) {
            array_push($dumpForeignKeys, ...$definition['foreignKeys']);
            array_push($dumpChecks, ...$definition['checks']);
        }
        $migrationForeignKeys = $foreignKeys[1];
        $migrationChecks = $checks[1];
        sort($dumpForeignKeys);
        sort($migrationForeignKeys);
        sort($dumpChecks);
        sort($migrationChecks);

        self::assertSame($dumpForeignKeys, $migrationForeignKeys);
        self::assertSame($dumpChecks, $migrationChecks);
    }

    public function test_the_grading_queue_index_exists(): void
    {
        $indexes = DB::select("PRAGMA index_info('idx_test_attempts_grading_queue')");
        self::assertSame(['status', 'grading_attempts', 'submitted_at'], collect($indexes)->sortBy('seqno')->pluck('name')->all());
    }

    public function test_the_daily_usage_counter_keeps_one_row_per_user(): void
    {
        $primary = collect(DB::select("PRAGMA table_info('ai_notebook_daily_usage')"))
            ->filter(static fn (object $column): bool => (int) $column->pk > 0)
            ->pluck('name')
            ->all();

        // AiNotebookController::recordUsage() updates by userId alone and resets the count
        // when the date rolls over, so a (userId, usageDate) key would break it.
        self::assertSame(['userId'], $primary);
    }
}
