<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Eshcol\Ats\App;

/**
 * Applies database updates from /migrations in filename order, once each.
 * Used by first-run setup, by Settings ("Apply updates"), and by bin/migrate.php,
 * so the live site never needs command-line access to be updated.
 */
final class Migrator
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return string[] versions not yet applied */
    public function pending(): array
    {
        $this->ensureTable();
        $done = $this->app->db->pdo()->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_diff($this->available(), $done));
    }

    /** @return string[] versions applied now */
    public function run(): array
    {
        $applied = [];
        $pdo = $this->app->db->pdo();
        foreach ($this->pending() as $version) {
            $sql = (string) file_get_contents($this->app->root . "/migrations/$version.sql");
            foreach (preg_split('/^\s*;;\s*$/m', $sql) as $statement) {
                $statement = trim(preg_replace('/;\s*$/', '', trim($statement)));
                $withoutComments = trim(preg_replace('/^--[^\n]*$/m', '', $statement));
                if ($withoutComments === '') {
                    continue;
                }
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    public function isInstalled(): bool
    {
        try {
            return (bool) $this->app->db->pdo()->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function available(): array
    {
        $files = glob($this->app->root . '/migrations/*.sql') ?: [];
        sort($files);
        return array_map(fn ($f) => basename($f, '.sql'), $files);
    }

    private function ensureTable(): void
    {
        $this->app->db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
    }
}
