<?php
class Migration
{
    private static $migrations = [
        '001' => 'createApiTokens',
        '002' => 'createEventsLog',
        '003' => 'createWebhooks',
        '004' => 'createTaskQueue',
    ];

    public static function run()
    {
        self::ensureMigrationsTable();
        $applied = self::getApplied();

        foreach (self::$migrations as $version => $method)
        {
            if ( ! in_array($version, $applied))
            {
                call_user_func([__CLASS__, $method]);
                self::markApplied($version);
            }
        }
    }

    private static function ensureMigrationsTable()
    {
        $dbType = Database::getDbType();
        if ($dbType == 'mysql')
            $sql = "CREATE TABLE IF NOT EXISTS `migrations` (`version` varchar(10) NOT NULL, `applied_at` datetime NOT NULL, PRIMARY KEY (`version`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        elseif ($dbType == 'pgsql')
            $sql = "CREATE TABLE IF NOT EXISTS \"migrations\" (\"version\" varchar(10) NOT NULL PRIMARY KEY, \"applied_at\" timestamp NOT NULL)";
        else
            $sql = "CREATE TABLE IF NOT EXISTS `migrations` (`version` varchar(10) NOT NULL PRIMARY KEY, `applied_at` datetime NOT NULL)";

        $stmt = Database::newStatement($sql);
        $stmt->execute();
    }

    private static function getApplied()
    {
        $stmt = Database::newStatement("SELECT `version` FROM `migrations` ORDER BY `version`");
        $stmt->execute();
        $result = [];
        foreach ($stmt as $row)
            $result[] = $row['version'];
        return $result;
    }

    private static function markApplied($version)
    {
        $stmt = Database::newStatement("INSERT INTO `migrations` (`version`, `applied_at`) VALUES (:version, :date)");
        $date = date('Y-m-d H:i:s');
        $stmt->bindParam(':version', $version);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
    }

    // Миграция 001: Таблица API-токенов
    private static function createApiTokens()
    {
        $dbType = Database::getDbType();
        if ($dbType == 'mysql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS `api_tokens` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `token` varchar(64) NOT NULL,
                `created_at` datetime NOT NULL,
                `last_used` datetime DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT '1',
                PRIMARY KEY (`id`),
                UNIQUE KEY `token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        elseif ($dbType == 'pgsql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS \"api_tokens\" (
                \"id\" SERIAL PRIMARY KEY,
                \"name\" varchar(100) NOT NULL,
                \"token\" varchar(64) NOT NULL UNIQUE,
                \"created_at\" timestamp NOT NULL,
                \"last_used\" timestamp DEFAULT NULL,
                \"active\" INTEGER NOT NULL DEFAULT 1
            )";
        }
        else
        {
            $sql = "CREATE TABLE IF NOT EXISTS `api_tokens` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `name` varchar(100) NOT NULL,
                `token` varchar(64) NOT NULL UNIQUE,
                `created_at` datetime NOT NULL,
                `last_used` datetime DEFAULT NULL,
                `active` INTEGER NOT NULL DEFAULT 1
            )";
        }
        $stmt = Database::newStatement($sql);
        $stmt->execute();
    }

    // Миграция 002: Таблица событий (EventBus)
    private static function createEventsLog()
    {
        $dbType = Database::getDbType();
        if ($dbType == 'mysql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS `events_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `type` varchar(50) NOT NULL,
                `data` text,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_type` (`type`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        elseif ($dbType == 'pgsql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS \"events_log\" (
                \"id\" SERIAL PRIMARY KEY,
                \"type\" varchar(50) NOT NULL,
                \"data\" text,
                \"created_at\" timestamp NOT NULL
            )";
        }
        else
        {
            $sql = "CREATE TABLE IF NOT EXISTS `events_log` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `type` varchar(50) NOT NULL,
                `data` text,
                `created_at` datetime NOT NULL
            )";
        }
        $stmt = Database::newStatement($sql);
        $stmt->execute();
    }

    // Миграция 003: Таблица вебхуков
    private static function createWebhooks()
    {
        $dbType = Database::getDbType();
        if ($dbType == 'mysql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS `webhooks` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `url` varchar(500) NOT NULL,
                `secret` varchar(64) NOT NULL DEFAULT '',
                `events` varchar(500) NOT NULL DEFAULT '*',
                `active` tinyint(1) NOT NULL DEFAULT '1',
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        elseif ($dbType == 'pgsql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS \"webhooks\" (
                \"id\" SERIAL PRIMARY KEY,
                \"name\" varchar(100) NOT NULL,
                \"url\" varchar(500) NOT NULL,
                \"secret\" varchar(64) NOT NULL DEFAULT '',
                \"events\" varchar(500) NOT NULL DEFAULT '*',
                \"active\" INTEGER NOT NULL DEFAULT 1,
                \"created_at\" timestamp NOT NULL
            )";
        }
        else
        {
            $sql = "CREATE TABLE IF NOT EXISTS `webhooks` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `name` varchar(100) NOT NULL,
                `url` varchar(500) NOT NULL,
                `secret` varchar(64) NOT NULL DEFAULT '',
                `events` varchar(500) NOT NULL DEFAULT '*',
                `active` INTEGER NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL
            )";
        }
        $stmt = Database::newStatement($sql);
        $stmt->execute();
    }

    // Миграция 004: Очередь задач
    private static function createTaskQueue()
    {
        $dbType = Database::getDbType();
        if ($dbType == 'mysql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS `task_queue` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `type` varchar(50) NOT NULL,
                `payload` text NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `attempts` int(3) NOT NULL DEFAULT '0',
                `max_attempts` int(3) NOT NULL DEFAULT '3',
                `next_run` datetime NOT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `error` text DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`),
                KEY `idx_next_run` (`next_run`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        elseif ($dbType == 'pgsql')
        {
            $sql = "CREATE TABLE IF NOT EXISTS \"task_queue\" (
                \"id\" SERIAL PRIMARY KEY,
                \"type\" varchar(50) NOT NULL,
                \"payload\" text NOT NULL,
                \"status\" varchar(20) NOT NULL DEFAULT 'pending',
                \"attempts\" INTEGER NOT NULL DEFAULT 0,
                \"max_attempts\" INTEGER NOT NULL DEFAULT 3,
                \"next_run\" timestamp NOT NULL,
                \"created_at\" timestamp NOT NULL,
                \"updated_at\" timestamp NOT NULL,
                \"error\" text DEFAULT NULL
            )";
        }
        else
        {
            $sql = "CREATE TABLE IF NOT EXISTS `task_queue` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `type` varchar(50) NOT NULL,
                `payload` text NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `max_attempts` INTEGER NOT NULL DEFAULT 3,
                `next_run` datetime NOT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `error` text DEFAULT NULL
            )";
        }
        $stmt = Database::newStatement($sql);
        $stmt->execute();
    }
}
?>
