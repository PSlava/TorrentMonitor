<?php
class EventBus
{
    // Типы событий
    const EVENT_TORRENT_UPDATED  = 'torrent.updated';
    const EVENT_TORRENT_ADDED    = 'torrent.added';
    const EVENT_TORRENT_DELETED  = 'torrent.deleted';
    const EVENT_DOWNLOAD_START   = 'download.start';
    const EVENT_DOWNLOAD_DONE    = 'download.done';
    const EVENT_DOWNLOAD_ERROR   = 'download.error';
    const EVENT_WARNING          = 'warning';
    const EVENT_ENGINE_START     = 'engine.start';
    const EVENT_ENGINE_DONE      = 'engine.done';
    const EVENT_HEALTH_CHECK     = 'health.check';

    // Публикация события
    public static function emit($type, $data = [])
    {
        $date = date('Y-m-d H:i:s');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $stmt = Database::newStatement("INSERT INTO `events_log` (`type`, `data`, `created_at`) VALUES (:type, :data, :date)");
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':data', $json);
        $stmt->bindParam(':date', $date);
        $stmt->execute();

        // Обновляем файл-маркер для SSE (избегает лишних DB-запросов)
        $lastIdFile = dirname(__FILE__).'/../data/last_event_id';
        @file_put_contents($lastIdFile, self::getLastId(), LOCK_EX);

        // Отправка вебхуков (через очередь задач)
        Webhook::dispatch($type, $data);
    }

    // Получение событий с определённого ID (для SSE polling)
    public static function getSince($lastId, $limit = 50)
    {
        $stmt = Database::newStatement("SELECT `id`, `type`, `data`, `created_at` FROM `events_log` WHERE `id` > :lastId ORDER BY `id` ASC LIMIT :lim");
        $stmt->bindParam(':lastId', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        if ($stmt->execute())
        {
            $result = [];
            foreach ($stmt as $row)
            {
                $result[] = [
                    'id'   => (int)$row['id'],
                    'type' => $row['type'],
                    'data' => json_decode($row['data'], true),
                    'time' => $row['created_at']
                ];
            }
            return $result;
        }
        return [];
    }

    // Получение последнего ID события
    public static function getLastId()
    {
        $stmt = Database::newStatement("SELECT MAX(`id`) AS `max_id` FROM `events_log`");
        if ($stmt->execute())
        {
            foreach ($stmt as $row)
                return (int)$row['max_id'];
        }
        return 0;
    }

    // Очистка старых событий (вызывать из engine.php)
    public static function cleanup($days = 7)
    {
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = Database::newStatement("DELETE FROM `events_log` WHERE `created_at` < :date");
        $stmt->bindParam(':date', $date);
        $stmt->execute();
    }
}
?>
