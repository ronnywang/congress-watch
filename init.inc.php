<?php

if (file_exists(__DIR__ . '/config.php')) {
    require_once(__DIR__ . '/config.php');
}

class CongressWatch
{
    protected static $_pdo = null;
    public static function getDb()
    {
        if (is_null(self::$_pdo)) {
            $pdo = new PDO(getenv('PDO_DSN'), getenv('PDO_USERNAME'), getenv('PDO_PASSWORD'));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            self::$_pdo = $pdo;
        }
        return self::$_pdo;
    }
}
