<?php
declare(strict_types=1);

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $this->pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enable WAL mode for better concurrent read performance
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // Enforce foreign keys
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Execute a write statement (INSERT, UPDATE, DELETE)
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch a single row
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // Fetch all rows
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Last inserted row ID
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // Return raw PDO for transactions if needed
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
