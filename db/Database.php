<?php
/**
 * Database Class - PDO Wrapper for MySQL
 *
 * Singleton pattern for efficient connection management
 * Compatible with shared hosting environments
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $config;
    private $queryCount = 0;

    /**
     * Private constructor - use getInstance()
     */
    private function __construct() {
        $this->config = require __DIR__ . '/config.php';
        $this->connect();
    }

    /**
     * Establish database connection
     */
    private function connect(): void {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );
        } catch (PDOException $e) {
            // Log error but don't expose credentials
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please check your configuration.");
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the PDO instance directly
     */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Execute a prepared statement
     */
    public function query(string $sql, array $params = []): PDOStatement {
        $this->queryCount++;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch a single column value
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn($column);
    }

    /**
     * Insert a row and return the last insert ID
     */
    public function insert(string $table, array $data): int {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Update rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "$column = ?";
        }

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            $where
        );

        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows
     */
    public function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Insert or update on duplicate key
     */
    public function upsert(string $table, array $data, array $updateColumns = []): int {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        if (empty($updateColumns)) {
            $updateColumns = $columns;
        }

        $updateParts = [];
        foreach ($updateColumns as $column) {
            $updateParts[] = "$column = VALUES($column)";
        }

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool {
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * Execute a callback within a transaction
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Check if a table exists
     */
    public function tableExists(string $table): bool {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
            [$this->config['database'], $table]
        );
        return $result && $result['count'] > 0;
    }

    /**
     * Get query count (for debugging)
     */
    public function getQueryCount(): int {
        return $this->queryCount;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
