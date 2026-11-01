<?php
declare(strict_types=1);

namespace Alphano\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Class PDOMySQLDriver
 *
 * Driver PDO per MySQL, compatibile PHP 8+.
 * - Risolve l’errore HY093 sui placeholder ripetuti.
 * - Offre query(), execute(), transazioni, replace(), limit helper.
 */
class PDOMySQLDriver
{
    private PDO $pdo;
    private ?PDOStatement $stmt = null;

    /**
     * Costruisce il driver e apre la connessione.
     *
     * @param string $host    Host MySQL (es. "localhost")
     * @param string $db      Nome del database
     * @param string $user    Username
     * @param string $pass    Password
     * @param string $charset Charset (default "utf8mb4")
     *
     * @throws RuntimeException In caso di errore di connessione
     */
    public function __construct(
        string $host,
        string $db,
        string $user,
        string $pass,
        string $charset = 'utf8mb4',
        int $port = 3306, string $socket = ''
    ) {
        $dsn = $socket !== '' ? "mysql:unix_socket={$socket};dbname={$db};charset={$charset}" : "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emula i prepared per consentire placeholder ripetuti (fix HY093)
            PDO::ATTR_EMULATE_PREPARES   => true,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new RuntimeException('Connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Ritorna le informazioni sull'ultimo errore PDO.
     *
     * @return array
     */
    public function errorInfo(): array
    {
        return $this->pdo->errorInfo();
    }

    /** Accesso preparato per i repository che usano direttamente fetch()/fetchAll(). */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function getConnection(): PDO { return $this->pdo; }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    /**
     * Esegue una SELECT e restituisce tutte le righe.
     *
     * @param string $sql    Query SQL
     * @param array  $params Parametri ['nome'=>valore, ...]
     *
     * @return array
     */
    public function query(string $sql, array $params = []): array
    {
        $this->stmt = $this->pdo->prepare($sql);
        // bind dei soli parametri nominati
        $this->bindAll($params);
        // poi eseguo: PDO provvederà anche ai posizionali
        $this->stmt->execute();
        return $this->stmt->fetchAll();
    }
    /**
     * Esegue INSERT/UPDATE/DELETE e restituisce il numero di righe modificate.
     *
     * @param string $sql    Query SQL
     * @param array  $params Parametri ['nome'=>valore, ...]
     *
     * @return int
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->stmt = $this->pdo->prepare($sql);
        $this->bindAll($params);
        $this->stmt->execute();
        return $this->stmt->rowCount();
    }

    /**
     * Inizio transazione.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transazione.
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback transazione.
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Restituisce l'ultimo ID generato.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Pulisce il risultato corrente.
     */
    public function free(): void
    {
        $this->stmt = null;
    }

    /**
     * Imposta il livello di isolamento della transazione.
     *
     * @param string $level Uno tra READ UNCOMMITTED, READ COMMITTED,
     *                      REPEATABLE READ, SERIALIZABLE
     *
     * @throws RuntimeException Se il livello non è valido
     */
    public function setTransactionIsolation(string $level): void
    {
        $valid = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];
        $upper = strtoupper($level);
        if (!in_array($upper, $valid, true)) {
            throw new RuntimeException("Isolation level {$level} non supportato");
        }
        $this->execute("SET SESSION TRANSACTION ISOLATION LEVEL {$upper}");
    }

    /**
     * Emula un “REPLACE” in MySQL (INSERT … ON DUPLICATE KEY UPDATE).
     *
     * @param string $table Nome tabella
     * @param array  $data  Assoc array colonna=>valore
     *
     * @return int Numero di righe inserite/aggiornate
     */
    public function replace(string $table, array $data): int
    {
        if (empty($data)) {
            throw new RuntimeException('replace(): data array vuoto');
        }

        $cols    = array_keys($data);
        $place   = array_map(fn($c) => ":{$c}", $cols);
        $updates = implode(
            ',',
            array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $cols)
        );

        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
            $table,
            implode(',', array_map(fn($c) => "`{$c}`", $cols)),
            implode(',', $place),
            $updates
        );

        return $this->execute($sql, $data);
    }

    /**
     * Appende LIMIT/OFFSET a una query.
     *
     * @param string $sql    Query di base
     * @param int    $limit  Numero di righe
     * @param int    $offset Offset iniziale
     *
     * @return string Query con LIMIT
     */
    public function modifyQueryWithLimit(string $sql, int $limit, int $offset = 0): string
    {
        $sql = rtrim($sql, ";\n\r\t ");
        return "{$sql} LIMIT {$offset},{$limit}";
    }

    /**
     * Binds tutti i parametri nominati (le chiavi stringa).
     * I parametri con chiavi numeriche vengono ignorati e saranno gestiti
     * come posizionali da PDO->execute().
     *
     * @param array $params ['nome'=>valore, 0=>valorePosizionale, ...]
     */
    private function bindAll(array $params): void
    {
        foreach ($params as $key => $val) {
            $placeholder = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ":{$key}");
            $type = match (true) {
                is_int($val) => PDO::PARAM_INT,
                is_bool($val) => PDO::PARAM_BOOL,
                $val === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $this->stmt->bindValue($placeholder, $val, $type);
        }
    }
}
