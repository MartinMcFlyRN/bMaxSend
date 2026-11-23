<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/** Thin PDO wrapper. All queries use prepared statements. */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = config('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'], (int) ($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4');
            if (!empty($c['socket'])) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['socket'], $c['name'], $c['charset'] ?? 'utf8mb4');
            }
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function reconnect(): void
    {
        self::$pdo = null;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?')));
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $st = self::query("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $params));
        return $st->rowCount();
    }

    /** Build "?,?,?" placeholders for IN() clauses. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
