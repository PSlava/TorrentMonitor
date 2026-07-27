<?php
class Webhook
{
    private static $activeCache = null;

    // Отправка вебхуков по событию (асинхронно через очередь)
    public static function dispatch($type, $data)
    {
        if (self::$activeCache === null)
            self::$activeCache = self::getActive();

        if (empty(self::$activeCache))
            return;

        foreach (self::$activeCache as $hook)
        {
            // Проверяем подписку на тип события
            if ($hook['events'] !== '*')
            {
                $events = array_map('trim', explode(',', $hook['events']));
                if ( ! in_array($type, $events, true))
                    continue;
            }

            $payload = json_encode([
                'event' => $type,
                'data'  => $data,
                'time'  => date('c')
            ], JSON_UNESCAPED_UNICODE);

            // Ставим в очередь вместо синхронной отправки
            if (class_exists('TaskQueue'))
            {
                TaskQueue::add('webhook_send', [
                    'url'     => $hook['url'],
                    'payload' => $payload,
                    'secret'  => $hook['secret']
                ]);
            }
            else
                self::send($hook['url'], $payload, $hook['secret']);
        }
    }

    // Прямая отправка одного вебхука (вызывается из TaskQueue)
    public static function sendQueued($taskPayload)
    {
        self::send($taskPayload['url'], $taskPayload['payload'], $taskPayload['secret']);
    }

    // HTTP-отправка с HMAC-подписью
    private static function send($url, $payload, $secret)
    {
        // Защита от SSRF — разрешаем только http/https
        if ( ! preg_match('#^https?://#i', $url))
            return;

        // Блокируем обращения к локальным адресам (включая IPv6, DNS resolve)
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host))
            return;
        // Прямая проверка hostname
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|0\.|localhost|\[::)/i', $host))
            return;
        // DNS resolve — проверяем куда реально указывает домен
        $resolved = gethostbyname($host);
        if ($resolved === $host)
            return; // DNS не разрешился
        if ( ! filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_LOOPBACK))
            return;

        $headers = ['Content-Type: application/json'];
        if ( ! empty($secret))
        {
            $signature = hash_hmac('sha256', $payload, $secret);
            $headers[] = 'X-TM-Signature: sha256=' . $signature;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // CRUD

    public static function create($name, $url, $events = '*', $secret = '')
    {
        // Защита от SSRF
        if ( ! preg_match('#^https?://#i', $url))
            return false;

        if (empty($secret))
            $secret = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $stmt = Database::newStatement("INSERT INTO `webhooks` (`name`, `url`, `secret`, `events`, `created_at`) VALUES (:name, :url, :secret, :events, :date)");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':url', $url);
        $stmt->bindParam(':secret', $secret);
        $stmt->bindParam(':events', $events);
        $stmt->bindParam(':date', $now);
        if ($stmt->execute())
        {
            // Получаем ID вставленной записи
            $dbType = Database::getDbType();
            if ($dbType == 'mysql')
                $stmtId = Database::newStatement("SELECT LAST_INSERT_ID() AS `last_id`");
            elseif ($dbType == 'pgsql')
                $stmtId = Database::newStatement("SELECT currval(pg_get_serial_sequence('webhooks', 'id')) AS \"last_id\"");
            else
                $stmtId = Database::newStatement("SELECT last_insert_rowid() AS `last_id`");
            if ($stmtId->execute())
            {
                foreach ($stmtId as $row)
                    return (int)$row['last_id'];
            }
            return true;
        }
        return false;
    }

    public static function delete($id)
    {
        $stmt = Database::newStatement("DELETE FROM `webhooks` WHERE `id` = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // Полный список (для внутреннего использования)
    public static function getAll()
    {
        $stmt = Database::newStatement("SELECT `id`, `name`, `url`, `secret`, `events`, `active`, `created_at` FROM `webhooks` ORDER BY `id`");
        $result = [];
        if ($stmt->execute())
        {
            foreach ($stmt as $row)
                $result[] = $row;
        }
        return $result;
    }

    // Список без секретов (для отдачи клиенту)
    public static function getAllSafe()
    {
        $stmt = Database::newStatement("SELECT `id`, `name`, `url`, `events`, `active`, `created_at` FROM `webhooks` ORDER BY `id`");
        $result = [];
        if ($stmt->execute())
        {
            foreach ($stmt as $row)
                $result[] = $row;
        }
        return $result;
    }

    public static function getActive()
    {
        $stmt = Database::newStatement("SELECT `id`, `name`, `url`, `secret`, `events` FROM `webhooks` WHERE `active` = 1");
        $result = [];
        if ($stmt->execute())
        {
            foreach ($stmt as $row)
                $result[] = $row;
        }
        return $result;
    }

    public static function toggle($id, $active)
    {
        $stmt = Database::newStatement("UPDATE `webhooks` SET `active` = :active WHERE `id` = :id");
        $stmt->bindParam(':active', $active, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
?>
