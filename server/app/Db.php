<?php

declare(strict_types=1);

/**
 * PDO 单例
 */
class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $c = config('db', []);
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'] ?? '127.0.0.1',
                (int)($c['port'] ?? 3306),
                $c['name'] ?? '',
                $c['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $c['user'] ?? '', $c['password'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int)self::conn()->lastInsertId();
    }

    public static function tx(callable $fn)
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
