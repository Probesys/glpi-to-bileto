<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

/**
 * @phpstan-type DatabaseConfiguration array{
 *     'host': string,
 *     'port': int,
 *     'dbname': string,
 *     'username': string,
 *     'password': string,
 * }
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
     * @see \PDO::prepare() https://www.php.net/manual/pdo.prepare.php
     *
     * @param mixed[] $options
     */
    public function prepare(string $sql_statement, array $options = []): \PDOStatement
    {
        assert($this->pdo_connection !== null);

        return $this->pdo_connection->prepare($sql_statement, $options);
    }

    /**
     * Fetch all the data corresponding to the given SQL request.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /**
     * Fetch the data corresponding to the given SQL request. The results are
     * indexed by the first selected column.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<mixed, array<string, mixed>>
     */
    public function fetchIndexed(string $sql, array $parameters = []): array
    {
        $statement = $this->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(\PDO::FETCH_UNIQUE);
    }

    /**
     * Fetch the data corresponding to the given SQL request. You must select
     * two columns: the first is used as key of the returned array.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<mixed, mixed>
     */
    public function fetchKeyValue(string $sql, array $parameters = []): array
    {
        $statement = $this->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /**
     * Fetch a single value corresponding to the SQL request. You must select
     * one column for a single result (e.g. selecting the "name" column of a
     * specific "id").
     *
     * @param array<string, mixed> $parameters
     */
    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $statement = $this->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }
}
