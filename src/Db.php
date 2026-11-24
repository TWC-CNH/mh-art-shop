<?php
declare(strict_types=1);

namespace App;

use PDO;

/** Datenbankzugriff (PDO, MariaDB/MySQL). Zeiten werden in UTC gespeichert. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $c = Config::get()['db'];
        if ($c['name'] === '') {
            throw new \RuntimeException('Datenbank nicht eingerichtet: DB_NAME, DB_USER und DB_PASS in der .env setzen.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Führt aus und liefert die Zahl der betroffenen Zeilen. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Aktuelle Zeit als DATETIME-String in UTC. */
    public static function now(int $offsetSec = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSec);
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
