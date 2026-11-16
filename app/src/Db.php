<?php
declare(strict_types=1);

namespace Eshcol\Ats;

use PDO;

/** Thin PDO wrapper. Every query is a prepared statement; nothing is string-built from input. */
final class Db
{
    private ?PDO $pdo = null;

    public function __construct(private readonly array $cfg)
    {
    }

    public function pdo(): PDO
    {
        if (!$this->pdo) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->cfg['host'], $this->cfg['port'] ?? 3306, $this->cfg['name']);
            $this->pdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+05:30'");
        }
        return $this->pdo;
    }

    public function run(string $sql, array $args = []): \PDOStatement
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public function one(string $sql, array $args = []): ?array
    {
        $row = $this->run($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $args = []): array
    {
        return $this->run($sql, $args)->fetchAll();
    }

    public function col(string $sql, array $args = []): mixed
    {
        return $this->run($sql, $args)->fetchColumn();
    }

    /** Column names are fixed by the calling code, never taken from user input. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols), implode(',', array_fill(0, count($cols), '?')));
        $this->run($sql, array_values($data));
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, int $id): void
    {
        $set = implode(',', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        $this->run("UPDATE `$table` SET $set WHERE id = ?", [...array_values($data), $id]);
    }

    public function tx(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
