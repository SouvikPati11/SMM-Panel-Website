<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query uses prepared statements with bound values.
 * Identifiers passed to insert()/update() come from application code only and
 * are additionally validated against a strict pattern.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private int $txDepth = 0;

    public function __construct(array $cfg)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'] ?? 3306, $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Store and compare all timestamps in UTC; strict mode rejects silent truncation.
        $this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(Config::get('db'));
        }
        return self::$instance;
    }

    public static function setInstance(?self $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return array<int|string, mixed> key => value pairs from first two columns */
    public function fetchPairs(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /** Update rows; $where is an associative array of column => value (ANDed). */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = self::ident($col) . ' = :s_' . $col;
            $params['s_' . $col] = $val;
        }
        $cond = [];
        foreach ($where as $col => $val) {
            $cond[] = self::ident($col) . ' = :w_' . $col;
            $params['w_' . $col] = $val;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), implode(', ', $set), implode(' AND ', $cond));
        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $cond = [];
        $params = [];
        foreach ($where as $col => $val) {
            $cond[] = self::ident($col) . ' = :w_' . $col;
            $params['w_' . $col] = $val;
        }
        return $this->query(sprintf('DELETE FROM %s WHERE %s', self::ident($table), implode(' AND ', $cond)), $params)->rowCount();
    }

    /**
     * Run $fn inside a transaction. Nested calls join the outer transaction.
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth > 0) {
            $this->txDepth++;
            try {
                return $fn($this);
            } finally {
                $this->txDepth--;
            }
        }
        $this->pdo->beginTransaction();
        $this->txDepth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->txDepth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    /** MySQL named lock (works on shared hosting). Returns true if acquired. */
    public function acquireLock(string $name, int $timeout = 0): bool
    {
        return (int) $this->fetchColumn('SELECT GET_LOCK(?, ?)', [substr($name, 0, 64), $timeout]) === 1;
    }

    public function releaseLock(string $name): void
    {
        $this->fetchColumn('SELECT RELEASE_LOCK(?)', [substr($name, 0, 64)]);
    }

    public static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException('Invalid SQL identifier');
        }
        return '`' . $name . '`';
    }

    /** Escape LIKE wildcards in user search input. */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
