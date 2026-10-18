<?php
// REST API — точка входа
// Работает параллельно с action.php для обратной совместимости
$dir = dirname(__FILE__).'/';
include_once $dir.'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
    exit;

// Единый конверт ответа: {ok, message, data} — его описывают вики и справка в «Настройки → API»
function api_respond($ok, $message, $data = NULL, $code = 200)
{
    http_response_code($code);
    $body = ['ok' => (bool) $ok, 'message' => $message];
    if ($ok && $data !== NULL)
        $body['data'] = $data;
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Авторизация по API-ключу: заголовок Authorization: Bearer <key> или ?token= в URL
function apiAuth()
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION']))
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers'))
    {
        $headers = apache_request_headers();
        if (isset($headers['Authorization']))
            $header = $headers['Authorization'];
    }

    $token = '';
    if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', $header, $m))
        $token = $m[1];
    //webhook'и (Sonarr) не умеют слать заголовок — ключ приходит параметром URL
    elseif ( ! empty($_GET['token']) && is_string($_GET['token']))
        $token = $_GET['token'];

    if (empty($token))
        api_respond(FALSE, 'Требуется API-ключ: заголовок Authorization: Bearer <ключ> или параметр ?token=', NULL, 401);

    // Миграции — только после подтверждения наличия токена
    Migration::run();

    //ключ из «Настройки → API» — единственный, который пользователь может выпустить из интерфейса
    $apiKey = Database::getSetting('ApiKey');
    if ( ! empty($apiKey) && hash_equals((string) $apiKey, $token))
        return ['id' => 0, 'name' => 'ApiKey'];

    // Constant-time сравнение токена (защита от timing attack)
    $stmt = Database::newStatement("SELECT `id`, `name`, `token` FROM `api_tokens` WHERE `active` = 1");
    if ($stmt->execute())
    {
        foreach ($stmt as $row)
        {
            if (hash_equals($row['token'], $token))
            {
                // Обновляем last_used
                $now = date('Y-m-d H:i:s');
                $stmtUp = Database::newStatement("UPDATE `api_tokens` SET `last_used` = :now WHERE `id` = :id");
                $stmtUp->bindParam(':now', $now);
                $stmtUp->bindParam(':id', $row['id'], PDO::PARAM_INT);
                $stmtUp->execute();
                return ['id' => $row['id'], 'name' => $row['name']];
            }
        }
    }

    api_respond(FALSE, 'Недействительный или неактивный ключ', NULL, 401);
}

// === МАРШРУТЫ ===

// Торренты
Router::get('/torrents', function() {
    apiAuth();
    $tracker = ( ! empty($_GET['tracker']) && is_string($_GET['tracker'])) ? $_GET['tracker'] : '';
    $list = Database::getTorrentsList('name');
    $items = [];
    if (is_array($list))
    {
        foreach ($list as $row)
        {
            if ($tracker !== '' && $row['tracker'] !== $tracker)
                continue;
            //краткий набор полей; всё остальное отдаёт GET /torrents/{id}
            $items[] = [
                'id'         => (int) $row['id'],
                'tracker'    => $row['tracker'],
                'name'       => $row['name'],
                'torrent_id' => $row['torrent_id'],
                'hd'         => (int) $row['hd'],
                'ep'         => $row['ep'],
                'timestamp'  => $row['timestamp'],
                'error'      => (int) $row['error'],
                'closed'     => (int) $row['closed'],
            ];
        }
    }
    api_respond(TRUE, count($items).' тем.', $items);
});

Router::get('/torrents/{id}', function($p) {
    apiAuth();
    $item = Database::getTorrent((int) $p['id']);
    if ( ! is_array($item))
        api_respond(FALSE, 'Тема не найдена.', NULL, 404);

    $torrent = $item[0];
    if ( ! empty($torrent['torrent_id']))
        $torrent['url'] = Url::href($torrent['tracker'], $torrent['torrent_id']);
    api_respond(TRUE, 'OK', $torrent);
});

Router::post('/torrents', function() {
    apiAuth();
    $b = Router::getInput();
    //поле из JSON может приехать массивом или объектом - trim() на таком роняет запрос в 500
    $str = function($key) use ($b) {
        return isset($b[$key]) && is_scalar($b[$key]) ? trim((string) $b[$key]) : '';
    };
    $rawUrl   = $str('url');
    $name     = $str('name');
    $tracker  = $str('tracker');
    $savePath = $str('path');
    $category = $str('category');

    //форумный трекер: тему опознаём по ссылке
    if ( ! empty($rawUrl))
    {
        $parsed = api_parse_tracker_url($rawUrl);
        if ($parsed === NULL)
            api_respond(FALSE, 'Не удалось разобрать URL или трекер не поддерживается.', NULL, 422);

        $tracker = $parsed['tracker'];
        $threme  = $parsed['threme'];

        if ( ! is_array(Database::getCredentials($tracker)))
            api_respond(FALSE, "Нет учётных данных для трекера: {$tracker}.", NULL, 422);

        if ( ! Database::checkThremExist($tracker, $threme))
            api_respond(FALSE, 'Тема уже отслеживается.', NULL, 409);

        Database::setThreme($tracker, $name, $savePath, $threme, 1, $category);
        api_respond(TRUE, 'Тема добавлена.', ['tracker' => $tracker, 'threme' => $threme], 201);
    }
    //RSS-трекер: сериал по названию
    elseif ( ! empty($tracker) && ! empty($name))
    {
        if ( ! in_array($tracker, ['lostfilm.tv', 'lostfilm-mirror', 'newstudio.tv', 'baibako.tv']))
            api_respond(FALSE, "Трекер {$tracker} не является RSS-трекером. Используйте параметр url.", NULL, 422);

        if ( ! is_array(Database::getCredentials($tracker)))
            api_respond(FALSE, "Нет учётных данных для трекера: {$tracker}.", NULL, 422);

        $hd = (int) $str('hd');
        if ( ! in_array($hd, [0, 1, 2]))
            api_respond(FALSE, 'Параметр hd должен быть 0 (SD), 1 (720p) или 2 (1080p).', NULL, 422);

        if ( ! Database::checkSerialExist($tracker, $name, $hd))
            api_respond(FALSE, 'Сериал уже отслеживается.', NULL, 409);

        Database::setSerial($tracker, $name, $savePath, $hd, $category);
        api_respond(TRUE, 'Сериал добавлен.', ['tracker' => $tracker, 'name' => $name, 'hd' => $hd], 201);
    }
    else
        api_respond(FALSE, 'Укажите url (форумный трекер) или tracker + name (RSS-трекер).', NULL, 422);
});

Router::delete('/torrents/{id}', function($p) {
    apiAuth();
    $id = (int) $p['id'];
    if ( ! is_array(Database::getTorrent($id)))
        api_respond(FALSE, 'Тема не найдена.', NULL, 404);

    if ( ! Database::deletItem($id))
        api_respond(FALSE, 'Ошибка удаления.', NULL, 500);

    EventBus::emit(EventBus::EVENT_TORRENT_DELETED, ['id' => $id]);
    api_respond(TRUE, 'Тема удалена.');
});

// Запуск движка в фоне
Router::post('/run', function() {
    apiAuth();
    //тот же запуск, что у кнопки «Запуск»: PHP_BINARY под mod_php - это httpd, а не PHP CLI
    $result = EngineRunner::start();
    if ( ! $result['ok'])
        api_respond(FALSE, $result['msg'], NULL, EngineRunner::isRunning() ? 409 : 500);
    api_respond(TRUE, $result['msg'], NULL, 202);
});

// Ошибки: тот же журнал, что в разделе «Ошибки» интерфейса
Router::get('/errors', function() {
    apiAuth();
    $tracker = ( ! empty($_GET['tracker']) && is_string($_GET['tracker'])) ? $_GET['tracker'] : '';
    //с фильтром набор полей другой - трекер приходит в `where`, дата разложена по частям
    $rows = ($tracker !== '')
        ? Database::getWarningsList($tracker)
        : Database::getAllWarningsList();
    $rows = $rows ?: [];
    api_respond(TRUE, count($rows).' ошибок.', $rows);
});

Router::get('/errors/{id}', function($p) {
    apiAuth();
    $id = (int) $p['id'];
    if ( ! is_array(Database::getTorrent($id)))
        api_respond(FALSE, 'Тема не найдена.', NULL, 404);

    $rows = Database::getWarningsByTorrentId($id) ?: [];
    api_respond(TRUE, count($rows).' ошибок.', $rows);
});

// Пользователи (мониторинг)
Router::get('/users', function() {
    apiAuth();
    $list = Database::getUserToWatch() ?: [];
    api_respond(TRUE, count($list).' пользователей.', $list);
});

// Учётные данные трекеров (без паролей)
Router::get('/credentials', function() {
    apiAuth();
    $list = Database::getAllCredentials();
    // Скрываем пароли
    if ($list)
    {
        foreach ($list as &$item)
        {
            $item['password'] = ! empty($item['password']) ? '***' : '';
        }
        unset($item);
    }
    $list = $list ?: [];
    api_respond(TRUE, count($list).' трекеров.', $list);
});

// Настройки
Router::get('/settings', function() {
    apiAuth();
    $settings = Database::getAllSetting();
    $result = [];
    if ($settings)
    {
        foreach ($settings as $row)
            $result[key($row)] = $row[key($row)];
    }
    // Скрываем пароли
    unset($result['password']);
    unset($result['torrentPassword']);
    unset($result['proxyAuth']);
    unset($result['ApiKey']);
    api_respond(TRUE, 'OK', $result);
});

Router::put('/settings', function() {
    apiAuth();
    $input = Router::getInput();
    $allowed = ['serverAddress', 'userAgent', 'send', 'auth', 'rss', 'autoUpdate', 'debug',
                'proxy', 'proxyType', 'proxyAddress', 'proxyAuth',
                'useTorrent', 'torrentClient', 'torrentAddress', 'torrentLogin', 'torrentPassword',
                'pathToDownload', 'deleteDistribution', 'deleteOldFiles',
                'httpTimeout', 'flaresolverrUrl'];
    $saved = 0;
    foreach ($input as $key => $val)
    {
        if (in_array($key, $allowed, TRUE) && is_scalar($val))
        {
            Database::updateSettings($key, $val);
            $saved++;
        }
    }
    api_respond(TRUE, $saved.' настроек сохранено.');
});

// Счётчик ошибок по трекерам
Router::get('/warnings', function() {
    apiAuth();
    $warnings = Database::getWarningsCount() ?: [];
    api_respond(TRUE, count($warnings).' трекеров с ошибками.', $warnings);
});

Router::delete('/warnings/{tracker}', function($p) {
    apiAuth();
    if ( ! Database::clearWarnings($p['tracker']))
        api_respond(FALSE, 'Ошибка очистки.', NULL, 500);

    api_respond(TRUE, 'Ошибки трекера очищены.');
});

// Новости
Router::get('/news', function() {
    apiAuth();
    $news = Database::getNews() ?: [];
    api_respond(TRUE, count($news).' новостей.', $news);
});

// События (EventBus)
Router::get('/events', function() {
    apiAuth();
    $since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
    $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 100) : 50;
    $events = EventBus::getSince($since, $limit) ?: [];
    api_respond(TRUE, count($events).' событий.', ['events' => $events, 'lastId' => EventBus::getLastId()]);
});

// Вебхуки (без раскрытия секретов)
Router::get('/webhooks', function() {
    apiAuth();
    $list = Webhook::getAllSafe() ?: [];
    api_respond(TRUE, count($list).' вебхуков.', $list);
});

Router::post('/webhooks', function() {
    apiAuth();
    $input = Router::getInput();
    if (empty($input['name']) || empty($input['url']) || ! is_string($input['url']))
        api_respond(FALSE, 'Требуются поля name и url.', NULL, 422);

    // Защита от SSRF — разрешаем только http/https
    if ( ! preg_match('#^https?://#i', $input['url']))
        api_respond(FALSE, 'URL должен начинаться с http:// или https://', NULL, 422);

    $id = Webhook::create($input['name'], $input['url'], $input['events'] ?? '*', $input['secret'] ?? '');
    api_respond(TRUE, 'Вебхук создан.', ['id' => $id], 201);
});

Router::delete('/webhooks/{id}', function($p) {
    apiAuth();
    if ( ! Webhook::delete((int) $p['id']))
        api_respond(FALSE, 'Вебхук не найден.', NULL, 404);

    api_respond(TRUE, 'Вебхук удалён.');
});

// API-токены
Router::get('/tokens', function() {
    apiAuth();
    $stmt = Database::newStatement("SELECT `id`, `name`, `created_at`, `last_used`, `active` FROM `api_tokens` ORDER BY `id`");
    $tokens = [];
    if ($stmt->execute())
    {
        foreach ($stmt as $row)
            $tokens[] = $row;
    }
    api_respond(TRUE, count($tokens).' токенов.', $tokens);
});

Router::post('/tokens', function() {
    apiAuth();
    $input = Router::getInput();
    $name = ( ! empty($input['name']) && is_string($input['name'])) ? $input['name'] : 'API Token';
    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $stmt = Database::newStatement("INSERT INTO `api_tokens` (`name`, `token`, `created_at`) VALUES (:name, :token, :date)");
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':date', $now);
    if ( ! $stmt->execute())
        api_respond(FALSE, 'Не удалось создать токен.', NULL, 500);

    api_respond(TRUE, 'Токен создан.', ['token' => $token, 'name' => $name], 201);
});

Router::delete('/tokens/{id}', function($p) {
    apiAuth();
    $stmt = Database::newStatement("DELETE FROM `api_tokens` WHERE `id` = :id");
    $stmt->bindValue(':id', (int) $p['id'], PDO::PARAM_INT);
    if ( ! $stmt->execute())
        api_respond(FALSE, 'Ошибка удаления.', NULL, 500);

    api_respond(TRUE, 'Токен удалён.');
});

// Очередь задач
Router::get('/tasks', function() {
    apiAuth();
    $tasks = TaskQueue::getAll() ?: [];
    api_respond(TRUE, count($tasks).' задач.', $tasks);
});

// Версия системы
Router::get('/version', function() {
    apiAuth();
    api_respond(TRUE, 'OK', Sys::version());
});

// Sonarr webhook: добавляет тему на мониторинг из downloadId (хеша) в qBittorrent
Router::post('/sonarr', function() {
    apiAuth();

    $body = Router::getInput();
    $eventType = isset($body['eventType']) && is_string($body['eventType']) ? $body['eventType'] : '';

    if ($eventType === 'Test')
        api_respond(TRUE, 'OK');

    if ($eventType !== 'Grab')
    {
        Errors::setWarnings('api', 'api_bad_event_type');
        api_respond(FALSE, "Неверный eventType: {$eventType}.", NULL, 400);
    }

    $hash = isset($body['downloadId']) && is_string($body['downloadId']) ? $body['downloadId'] : '';
    if (empty($hash))
    {
        Errors::setWarnings('api', 'api_no_download_id');
        api_respond(FALSE, 'Нет downloadId в запросе.', NULL, 400);
    }

    $comment = qBittorrent::getTorrentComment($hash);
    if (empty($comment))
    {
        Errors::setWarnings('api', 'api_qbit_fail');
        api_respond(FALSE, 'Не удалось получить данные из qBittorrent.', NULL, 502);
    }

    $parsed = api_parse_tracker_url($comment);
    if ($parsed === NULL)
    {
        Errors::setWarnings('api', 'api_bad_url');
        api_respond(FALSE, 'comment торрента не содержит корректного URL форумной темы.', NULL, 400);
    }

    $tracker = $parsed['tracker'];
    $threme  = $parsed['threme'];

    if ( ! is_array(Database::getCredentials($tracker)))
    {
        Errors::setWarnings('api', 'api_no_credentials');
        api_respond(FALSE, "Нет учётных данных для трекера: {$tracker}.", NULL, 400);
    }

    if ( ! Database::checkThremExist($tracker, $threme))
    {
        Errors::setWarnings('api', 'api_already_tracked');
        api_respond(FALSE, 'Тема уже отслеживается.', NULL, 409);
    }

    $category = ( ! empty($_GET['category']) && is_string($_GET['category'])) ? $_GET['category'] : '';
    Database::setThreme($tracker, '', '', $threme, 1, $category);

    $seriesTitle = isset($body['series']['title']) ? $body['series']['title'] : $comment;
    Notification::sendNotification('notification', date('r'), 'api',
        "Тема «{$seriesTitle}» ({$comment}) добавлена на мониторинг через Sonarr.", $seriesTitle);
    api_respond(TRUE, "Тема добавлена: {$seriesTitle}.");
});

// Разбор URL темы трекера: используется POST /torrents и вебхуком sonarr
function api_parse_tracker_url($rawUrl)
{
    $url = parse_url($rawUrl);
    if (empty($url['host']))
        return null;

    $tracker = preg_replace('/^www\./', '', $url['host']);

    if (in_array($tracker, ['lostfilm.tv', 'lostfilm-mirror', 'newstudio.tv']))
        return null;

    if ($tracker === 'tr.anidub.com')
        $tracker = 'anidub.com';
    elseif ($tracker === 'baibako.tv')
        $tracker = 'baibako.tv_forum';

    if ($tracker === 'anidub.com' || $tracker === 'riperam.org')
    {
        $threme = $url['path'];
    }
    elseif ($tracker === 'animelayer.ru')
    {
        $path = str_replace('/torrent', '', $url['path']);
        preg_match('/\/(\w*)\/?/', $path, $array);
        $threme = $array[1] ?? null;
    }
    elseif ($tracker === 'casstudio.tk')
    {
        $query = explode('t=', $url['query'] ?? '');
        $threme = $query[1] ?? null;
    }
    elseif ($tracker !== 'rutor.is')
    {
        $query = explode('=', $url['query'] ?? '');
        $threme = $query[1] ?? null;
    }
    else
    {
        preg_match('/\d{4,8}/', $url['path'], $array);
        $threme = $array[0] ?? null;
    }

    if (empty($threme))
        return null;

    return ['tracker' => $tracker, 'threme' => $threme];
}

Router::notFound(function() {
    api_respond(FALSE, 'Маршрут не найден.', NULL, 404);
});

// Диспатч
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
Router::dispatch($method, $uri);
?>
