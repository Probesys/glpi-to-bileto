<?php

namespace App;

/**
 * @phpstan-type DatabaseConfiguration array{
 *     'host': string,
 *     'port': int,
 *     'dbname': string,
 *     'username': string,
 *     'password': string,
 * }
 *
 * @author Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Database
{
    private static ?Database $instance = null;

    private ?\PDO $pdo_connection = null;

    /**
     * Return an instance of Database.
     *
     * @param DatabaseConfiguration $configuration
     */
    public static function get(array $configuration): Database
    {
        if (!self::$instance) {
            self::$instance = new self($configuration);
        }

        return self::$instance;
    }

    /**
     * Initialize a database. Note it is private, you must use `\App\Database::get`
     * to get an instance.
     *
     * @param DatabaseConfiguration $configuration
     */
    private function __construct(array $configuration)
    {
        $dsn = "mysql:host={$configuration['host']}";
        $dsn .= ";port={$configuration['port']}";
        $dsn .= ";dbname={$configuration['dbname']}";
        $dsn .= ";charset=utf8mb4";

        $options = [];
        $options[\PDO::ATTR_DEFAULT_FETCH_MODE] = \PDO::FETCH_ASSOC;
        $options[\PDO::ATTR_EMULATE_PREPARES] = false;
        $options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;

        $this->pdo_connection = new \PDO(
            $dsn,
            $configuration['username'],
            $configuration['password'],
            $options,
        );
    }

    /**
     * @see \PDO::beginTransaction https://www.php.net/manual/pdo.begintransaction.php
     */
    public function beginTransaction(): bool
    {
        /** @var bool $result */
        $result = $this->pdoCall('beginTransaction');
        return $result;
    }

    /**
     * @see \PDO::commit https://www.php.net/manual/pdo.commit.php
     */
    public function commit(): bool
    {
        /** @var bool $result */
        $result = $this->pdoCall('commit');
        return $result;
    }

    /**
     * @see \PDO::errorCode https://www.php.net/manual/pdo.errorcode.php
     */
    public function errorCode(): ?string
    {
        /** @var ?string $result */
        $result = $this->pdoCall('errorCode');
        return $result;
    }

    /**
     * @see \PDO::errorInfo https://www.php.net/manual/pdo.errorinfo.php
     *
     * @return array{
     *     0: string,
     *     1: ?string,
     *     2: string,
     * }
     */
    public function errorInfo(): array
    {
        /** @var array{
         *     0: string,
         *     1: ?string,
         *     2: string,
         * } $result
        */
        $result = $this->pdoCall('errorInfo');
        return $result;
    }

    /**
     * @see \PDO::exec() https://www.php.net/manual/pdo.exec.php
     */
    public function exec(string $sql_statement): int
    {
        /** @var int $result */
        $result = $this->pdoCall('exec', $sql_statement);
        return $result;
    }

    /**
     * @see \PDO::getAttribute() https://www.php.net/manual/pdo.getattribute.php
     *
     * @param \PDO::ATTR_* $attribute
     */
    public function getAttribute(int $attribute): mixed
    {
        return $this->pdoCall('getAttribute', $attribute);
    }

    /**
     * @see \PDO::inTransaction() https://www.php.net/manual/pdo.intransaction.php
     */
    public function inTransaction(): bool
    {
        /** @var bool $result */
        $result = $this->pdoCall('inTransaction');
        return $result;
    }

    /**
     * @see \PDO::lastInsertId() https://www.php.net/manual/pdo.lastinsertid.php
     */
    public function lastInsertId(?string $name = null): string
    {
        /** @var string $result */
        $result = $this->pdoCall('lastInsertId', $name);
        return $result;
    }

    /**
     * @see \PDO::prepare() https://www.php.net/manual/pdo.prepare.php
     *
     * @param mixed[] $options
     */
    public function prepare(string $sql_statement, array $options = []): \PDOStatement
    {
        /** @var \PDOStatement $result */
        $result = $this->pdoCall('prepare', $sql_statement, $options);
        return $result;
    }

    /**
     * @see \PDO::query() https://www.php.net/manual/pdo.query.php
     */
    public function query(string $sql_statement, ?int $fetch_mode = null): \PDOStatement
    {
        /** @var \PDOStatement $result */
        $result = $this->pdoCall('query', $sql_statement, $fetch_mode);
        return $result;
    }

    /**
     * @see \PDO::quote https://www.php.net/manual/pdo.quote.php
     */
    public function quote(string $string, int $parameter_type = \PDO::PARAM_STR): string
    {
        /** @var string $result */
        $result = $this->pdoCall('quote', $string, $parameter_type);
        return $result;
    }

    /**
     * @see \PDO::rollBack https://www.php.net/manual/pdo.rollback.php
     */
    public function rollBack(): bool
    {
        /** @var bool $result */
        $result = $this->pdoCall('rollBack');
        return $result;
    }

    /**
     * @see \PDO::setAttribute() https://www.php.net/manual/pdo.setattribute.php
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        /** @var bool $result */
        $result = $this->pdoCall('setAttribute', $attribute, $value);
        return $result;
    }

    /**
     * Transfer method calls to the PDO connection. It starts the connection if
     * it has been stopped.
     */
    public function pdoCall(string $name, mixed ...$arguments): mixed
    {
        if (!is_callable([$this->pdo_connection, $name])) {
            throw new \BadMethodCallException('Call to undefined method ' . get_called_class() . '::' . $name);
        }

        return $this->pdo_connection->$name(...$arguments);
    }
}
