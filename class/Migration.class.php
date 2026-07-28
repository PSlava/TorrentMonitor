<?php
class Migration
{
    private static $migrations = [
        '001' => 'createApiTokens',
        '002' => 'createEventsLog',
        '003' => 'createWebhooks',
        '004' => 'createTaskQueue',
        '005' => 'addProxyAuth',
        '006' => 'addTorrentCategory',
        '007' => 'addMissingSettings',
        '008' => 'widenSettingsVal',
        '009' => 'convertMysqlToInnodb',
    ];

    private static $done = false;

    public static function run()
    {
        if (self::$done) return;
        self::$done = true;

        self::ensureMigrationsTable();
        $applied = self::getApplied();

        // Все миграции уже применены — выходим быстро
        if (count($applied) >= count(self::$migrations)) return;

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

        // Индексы для PostgreSQL и SQLite
        if ($dbType == 'pgsql')
        {
            $idx1 = Database::newStatement("CREATE INDEX IF NOT EXISTS \"idx_events_type\" ON \"events_log\" (\"type\")");
            $idx1->execute();
            $idx2 = Database::newStatement("CREATE INDEX IF NOT EXISTS \"idx_events_created\" ON \"events_log\" (\"created_at\")");
            $idx2->execute();
        }
        elseif ($dbType == 'sqlite' || $dbType == 'sqlite2')
        {
            $idx1 = Database::newStatement("CREATE INDEX IF NOT EXISTS `idx_events_type` ON `events_log` (`type`)");
            $idx1->execute();
            $idx2 = Database::newStatement("CREATE INDEX IF NOT EXISTS `idx_events_created` ON `events_log` (`created_at`)");
            $idx2->execute();
        }
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

        // Индексы для PostgreSQL и SQLite
        if ($dbType == 'pgsql')
        {
            $idx1 = Database::newStatement("CREATE INDEX IF NOT EXISTS \"idx_task_status\" ON \"task_queue\" (\"status\")");
            $idx1->execute();
            $idx2 = Database::newStatement("CREATE INDEX IF NOT EXISTS \"idx_task_next_run\" ON \"task_queue\" (\"next_run\")");
            $idx2->execute();
        }
        elseif ($dbType == 'sqlite' || $dbType == 'sqlite2')
        {
            $idx1 = Database::newStatement("CREATE INDEX IF NOT EXISTS `idx_task_status` ON `task_queue` (`status`)");
            $idx1->execute();
            $idx2 = Database::newStatement("CREATE INDEX IF NOT EXISTS `idx_task_next_run` ON `task_queue` (`next_run`)");
            $idx2->execute();
        }
    }
    // Миграция 005: Настройка proxyAuth (авторизация прокси)
    private static function addProxyAuth()
    {
        // Проверяем, есть ли уже такая настройка
        $val = Database::getSetting('proxyAuth');
        if ($val === null || $val === false)
        {
            $stmt = Database::newStatement("INSERT INTO `settings` (`key`, `val`) VALUES ('proxyAuth', '')");
            $stmt->execute();
            Database::invalidateSettingsCache();
        }
    }

    // Миграция 006: Колонка torrent.category (добавлена в v3.0 апстрима, отсутствует в БД старых форков)
    private static function addTorrentCategory()
    {
        $dbType = Database::getDbType();

        if ($dbType == 'mysql')
        {
            $check = Database::newStatement(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'torrent' AND COLUMN_NAME = 'category'"
            );
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if ((int)$row['c'] === 0)
            {
                $alter = Database::newStatement(
                    "ALTER TABLE `torrent` ADD COLUMN `category` varchar(100) NOT NULL DEFAULT ''"
                );
                $alter->execute();
            }
        }
        elseif ($dbType == 'pgsql')
        {
            $alter = Database::newStatement(
                "ALTER TABLE \"torrent\" ADD COLUMN IF NOT EXISTS \"category\" VARCHAR(100) NOT NULL DEFAULT ''"
            );
            $alter->execute();
        }
        else
        {
            $check = Database::newStatement("PRAGMA table_info(`torrent`)");
            $check->execute();
            $exists = false;
            foreach ($check as $col)
            {
                if (isset($col['name']) && $col['name'] === 'category')
                {
                    $exists = true;
                    break;
                }
            }
            if ( ! $exists)
            {
                $alter = Database::newStatement(
                    "ALTER TABLE `torrent` ADD COLUMN `category` VARCHAR(100) NOT NULL DEFAULT ''"
                );
                $alter->execute();
            }
        }
    }

    // Миграция 007: Строки settings, добавленные в v3.0 (flaresolverrUrl, ApiKey, qbitCategory).
    // updateSettings делает UPDATE — без строки сохранения молча теряются.
    private static function addMissingSettings()
    {
        $rows = array('flaresolverrUrl', 'ApiKey', 'qbitCategory');
        foreach ($rows as $key)
        {
            $val = Database::getSetting($key);
            if ($val === null || $val === false)
            {
                $stmt = Database::newStatement("INSERT INTO `settings` (`key`, `val`) VALUES (:key, '')");
                $stmt->bindParam(':key', $key);
                $stmt->execute();
            }
        }
        Database::invalidateSettingsCache();
    }

    // Миграция 008: settings.val VARCHAR(100) → VARCHAR(255). SQLite не проверяет длину.
    private static function widenSettingsVal()
    {
        $dbType = Database::getDbType();

        if ($dbType == 'mysql')
        {
            $alter = Database::newStatement(
                "ALTER TABLE `settings` MODIFY COLUMN `val` VARCHAR(255) NOT NULL"
            );
            $alter->execute();
        }
        elseif ($dbType == 'pgsql')
        {
            $alter = Database::newStatement(
                "ALTER TABLE \"settings\" ALTER COLUMN \"val\" TYPE VARCHAR(255)"
            );
            $alter->execute();
        }
        // SQLite: тип VARCHAR(N) не enforcement — данные пишутся без обрезки, миграция не нужна.
    }

    // Миграция 009 (MySQL): конвертация старых таблиц MyISAM+utf8 → InnoDB+utf8mb4.
    // Даёт транзакции, row-level lock и полноценный 4-байтный UTF-8 (эмоджи).
    private static function convertMysqlToInnodb()
    {
        if (Database::getDbType() != 'mysql') return;

        $tables = array('buffer', 'credentials', 'news', 'notifications', 'settings', 'temp', 'torrent', 'warning', 'watch');
        foreach ($tables as $table)
        {
            $check = Database::newStatement(
                "SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
            );
            $check->bindParam(':table', $table);
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if ( ! $row) continue;

            $needsEngine = strtolower($row['ENGINE']) !== 'innodb';
            $needsCharset = strpos((string)$row['TABLE_COLLATION'], 'utf8mb4') !== 0;

            if ($needsEngine)
            {
                $alter = Database::newStatement("ALTER TABLE `$table` ENGINE=InnoDB");
                $alter->execute();
            }
            if ($needsCharset)
            {
                $alter = Database::newStatement(
                    "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
                $alter->execute();
            }
        }
    }
}
?>
