<?php
class TaskQueue
{
    const STATUS_PENDING    = 'pending';
    const STATUS_RUNNING    = 'running';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    // Добавление задачи в очередь
    public static function add($type, $payload = [], $maxAttempts = 3)
    {
        $now = date('Y-m-d H:i:s');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = Database::newStatement("INSERT INTO `task_queue` (`type`, `payload`, `status`, `max_attempts`, `next_run`, `created_at`, `updated_at`) VALUES (:type, :payload, :status, :max, :next, :created, :updated)");
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':payload', $json);
        $status = self::STATUS_PENDING;
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':max', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindParam(':next', $now);
        $stmt->bindParam(':created', $now);
        $stmt->bindParam(':updated', $now);
        return $stmt->execute();
    }

    // Атомарный захват следующей задачи (getNext + markRunning)
    public static function getNext()
    {
        $now = date('Y-m-d H:i:s');
        $statusPending = self::STATUS_PENDING;
        $statusRunning = self::STATUS_RUNNING;
        // Уникальный маркер для идентификации захваченной задачи
        $lockToken = bin2hex(random_bytes(8));

        $dbType = Database::getDbType();

        if ($dbType == 'mysql')
        {
            $stmtUp = Database::newStatement("UPDATE `task_queue` SET `status` = :running, `updated_at` = :now, `error` = :lock WHERE `id` = (SELECT `id` FROM (SELECT `id` FROM `task_queue` WHERE `status` = :pending AND `next_run` <= :now2 ORDER BY `id` ASC LIMIT 1) AS t)");
        }
        else
        {
            $stmtUp = Database::newStatement("UPDATE `task_queue` SET `status` = :running, `updated_at` = :now, `error` = :lock WHERE `id` = (SELECT `id` FROM `task_queue` WHERE `status` = :pending AND `next_run` <= :now2 ORDER BY `id` ASC LIMIT 1)");
        }
        $stmtUp->bindParam(':running', $statusRunning);
        $stmtUp->bindParam(':now', $now);
        $stmtUp->bindParam(':lock', $lockToken);
        $stmtUp->bindParam(':pending', $statusPending);
        $stmtUp->bindParam(':now2', $now);
        $stmtUp->execute();
        if ($stmtUp->rowCount() == 0)
            return null;

        // Читаем задачу по уникальному lock_token
        $stmt = Database::newStatement("SELECT `id`, `type`, `payload`, `attempts`, `max_attempts` FROM `task_queue` WHERE `error` = :lock AND `status` = :status LIMIT 1");
        $stmt->bindParam(':lock', $lockToken);
        $stmt->bindParam(':status', $statusRunning);

        if ($stmt->execute())
        {
            foreach ($stmt as $row)
            {
                // Очищаем lock_token из поля error
                $stmtClear = Database::newStatement("UPDATE `task_queue` SET `error` = NULL WHERE `id` = :id");
                $stmtClear->bindParam(':id', $row['id']);
                $stmtClear->execute();

                $row['payload'] = json_decode($row['payload'], true);
                return $row;
            }
        }
        return null;
    }

    // Отметить задачу как выполняемую (для ручного использования)
    public static function markRunning($id)
    {
        $now = date('Y-m-d H:i:s');
        $stmt = Database::newStatement("UPDATE `task_queue` SET `status` = :status, `updated_at` = :now WHERE `id` = :id");
        $status = self::STATUS_RUNNING;
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':now', $now);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Отметить задачу как завершённую
    public static function markCompleted($id)
    {
        $now = date('Y-m-d H:i:s');
        $stmt = Database::newStatement("UPDATE `task_queue` SET `status` = :status, `updated_at` = :now WHERE `id` = :id");
        $status = self::STATUS_COMPLETED;
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':now', $now);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Отметить задачу как неудачную с exponential backoff
    public static function markFailed($id, $error = '')
    {
        $now = date('Y-m-d H:i:s');

        // Получаем текущие попытки
        $stmt = Database::newStatement("SELECT `attempts`, `max_attempts` FROM `task_queue` WHERE `id` = :id");
        $stmt->bindParam(':id', $id);
        $task = null;
        if ($stmt->execute())
        {
            foreach ($stmt as $row)
                $task = $row;
        }

        if ( ! $task)
            return false;

        $attempts = (int)$task['attempts'] + 1;
        $maxAttempts = (int)$task['max_attempts'];

        if ($attempts >= $maxAttempts)
        {
            // Финальная неудача
            $status = self::STATUS_FAILED;
            $stmt = Database::newStatement("UPDATE `task_queue` SET `status` = :status, `attempts` = :attempts, `error` = :error, `updated_at` = :now WHERE `id` = :id");
        }
        else
        {
            // Exponential backoff: 30с, 2мин, 8мин, 32мин...
            $delay = min(pow(4, $attempts) * 30, 3600); // максимум 1 час
            $nextRun = date('Y-m-d H:i:s', time() + $delay);
            $status = self::STATUS_PENDING;
            $stmt = Database::newStatement("UPDATE `task_queue` SET `status` = :status, `attempts` = :attempts, `next_run` = :next, `error` = :error, `updated_at` = :now WHERE `id` = :id");
            $stmt->bindParam(':next', $nextRun);
        }

        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':attempts', $attempts, PDO::PARAM_INT);
        $stmt->bindParam(':error', $error);
        $stmt->bindParam(':now', $now);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Обработка очереди (вызывать из engine.php)
    public static function process()
    {
        $processed = 0;
        while ($task = self::getNext())
        {

            try {
                self::execute($task);
                self::markCompleted($task['id']);
                $processed++;
            } catch (Exception $e) {
                self::markFailed($task['id'], $e->getMessage());
            }

            // Обрабатываем не более 50 задач за раз
            if ($processed >= 50)
                break;
        }
        return $processed;
    }

    // Выполнение задачи по типу
    private static function execute($task)
    {
        switch ($task['type'])
        {
            case 'add_torrent':
                Sys::AddFromTemp([$task['payload']]);
                break;

            case 'webhook':
                $payload = $task['payload'];
                Webhook::dispatch($payload['event'], $payload['data']);
                break;

            case 'webhook_send':
                Webhook::sendQueued($task['payload']);
                break;

            default:
                throw new Exception('Неизвестный тип задачи: ' . $task['type']);
        }
    }

    // Получение всех задач (для API/UI)
    public static function getAll($limit = 50)
    {
        $stmt = Database::newStatement("SELECT `id`, `type`, `payload`, `status`, `attempts`, `max_attempts`, `next_run`, `created_at`, `updated_at`, `error` FROM `task_queue` ORDER BY `id` DESC LIMIT :lim");
        $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $result = [];
        if ($stmt->execute())
        {
            foreach ($stmt as $row)
            {
                $row['payload'] = json_decode($row['payload'], true);
                $result[] = $row;
            }
        }
        return $result;
    }

    // Очистка завершённых задач
    public static function cleanup($days = 7)
    {
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = Database::newStatement("DELETE FROM `task_queue` WHERE `status` IN ('completed', 'failed') AND `updated_at` < :date");
        $stmt->bindParam(':date', $date);
        $stmt->execute();
    }
}
?>
