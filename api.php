<?php
// REST API — точка входа
// Работает параллельно с action.php для обратной совместимости
$dir = dirname(__FILE__).'/';
include_once $dir.'config.php';
include_once $dir.'class/Database.class.php';
include_once $dir.'class/System.class.php';
include_once $dir.'class/Errors.class.php';
include_once $dir.'class/Notification.class.php';
include_once $dir.'class/Url.class.php';
include_once $dir.'class/Router.class.php';
include_once $dir.'class/EventBus.class.php';
include_once $dir.'class/Webhook.class.php';
include_once $dir.'class/HealthCheck.class.php';
include_once $dir.'class/TaskQueue.class.php';
include_once $dir.'class/Migration.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
    exit;

// Авторизация по API-токену (только через заголовок Authorization)
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
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m))
        $token = $m[1];

    if (empty($token))
    {
        Router::json(['error' => 'Требуется API-токен. Передайте заголовок Authorization: Bearer <token>'], 401);
        exit;
    }

    // Миграции — только после подтверждения наличия токена
    Migration::run();

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

    Router::json(['error' => 'Недействительный или неактивный токен'], 401);
    exit;
}

// === МАРШРУТЫ ===

// Торренты
Router::get('/torrents', function() {
    apiAuth();
    $list = Database::getTorrentsList('name');
    Router::json(['data' => $list ?: []]);
});

Router::get('/torrents/{id}', function($p) {
    apiAuth();
    $item = Database::getTorrent((int)$p['id']);
    if ($item)
    {
        $torrent = $item[0];
        if ( ! empty($torrent['torrent_id']))
            $torrent['url'] = Url::href($torrent['tracker'], $torrent['torrent_id']);
        Router::json(['data' => $torrent]);
    }
    else
        Router::json(['error' => 'Торрент не найден'], 404);
});

Router::delete('/torrents/{id}', function($p) {
    apiAuth();
    $id = (int)$p['id'];
    if (Database::deletItem($id))
    {
        EventBus::emit(EventBus::EVENT_TORRENT_DELETED, ['id' => $id]);
        Router::json(['success' => true]);
    }
    else
        Router::json(['error' => 'Ошибка удаления'], 500);
});

// Пользователи (мониторинг)
Router::get('/users', function() {
    apiAuth();
    $list = Database::getUserToWatch();
    Router::json(['data' => $list ?: []]);
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
    Router::json(['data' => $list ?: []]);
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
    Router::json(['data' => $result]);
});

Router::put('/settings', function() {
    apiAuth();
    $input = Router::getInput();
    $allowed = ['serverAddress', 'userAgent', 'send', 'auth', 'rss', 'autoUpdate', 'debug',
                'proxy', 'proxyType', 'proxyAddress',
                'useTorrent', 'torrentClient', 'torrentAddress', 'torrentLogin', 'torrentPassword',
                'pathToDownload', 'deleteDistribution', 'deleteOldFiles'];
    foreach ($input as $key => $val)
    {
        if (in_array($key, $allowed, true))
            Database::updateSettings($key, $val);
    }
    Router::json(['success' => true]);
});

// Ошибки
Router::get('/warnings', function() {
    apiAuth();
    $warnings = Database::getWarningsCount();
    Router::json(['data' => $warnings ?: []]);
});

Router::delete('/warnings/{tracker}', function($p) {
    apiAuth();
    if (Database::clearWarnings($p['tracker']))
        Router::json(['success' => true]);
    else
        Router::json(['error' => 'Ошибка очистки'], 500);
});

// Новости
Router::get('/news', function() {
    apiAuth();
    $news = Database::getNews();
    Router::json(['data' => $news ?: []]);
});

// Health Check
Router::get('/health', function() {
    apiAuth();
    $checks = HealthCheck::runAll();
    Router::json(['data' => $checks]);
});

// События (EventBus)
Router::get('/events', function() {
    apiAuth();
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $events = EventBus::getSince($since, $limit);
    Router::json(['data' => $events, 'lastId' => EventBus::getLastId()]);
});

// Вебхуки (без раскрытия секретов)
Router::get('/webhooks', function() {
    apiAuth();
    $list = Webhook::getAllSafe();
    Router::json(['data' => $list]);
});

Router::post('/webhooks', function() {
    apiAuth();
    $input = Router::getInput();
    if (empty($input['name']) || empty($input['url']))
    {
        Router::json(['error' => 'Требуются поля name и url'], 400);
        return;
    }
    $url = $input['url'];
    // Защита от SSRF — разрешаем только http/https
    if ( ! preg_match('#^https?://#i', $url))
    {
        Router::json(['error' => 'URL должен начинаться с http:// или https://'], 400);
        return;
    }
    $id = Webhook::create($input['name'], $url, $input['events'] ?? '*', $input['secret'] ?? '');
    Router::json(['success' => true, 'id' => $id], 201);
});

Router::delete('/webhooks/{id}', function($p) {
    apiAuth();
    if (Webhook::delete((int)$p['id']))
        Router::json(['success' => true]);
    else
        Router::json(['error' => 'Вебхук не найден'], 404);
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
    Router::json(['data' => $tokens]);
});

Router::post('/tokens', function() {
    apiAuth();
    $input = Router::getInput();
    $name = ! empty($input['name']) ? $input['name'] : 'API Token';
    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $stmt = Database::newStatement("INSERT INTO `api_tokens` (`name`, `token`, `created_at`) VALUES (:name, :token, :date)");
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':date', $now);
    $stmt->execute();
    Router::json(['success' => true, 'token' => $token, 'name' => $name], 201);
});

Router::delete('/tokens/{id}', function($p) {
    apiAuth();
    $stmt = Database::newStatement("DELETE FROM `api_tokens` WHERE `id` = :id");
    $stmt->bindParam(':id', $p['id'], PDO::PARAM_INT);
    if ($stmt->execute())
        Router::json(['success' => true]);
    else
        Router::json(['error' => 'Ошибка удаления'], 500);
});

// Очередь задач
Router::get('/tasks', function() {
    apiAuth();
    $tasks = TaskQueue::getAll();
    Router::json(['data' => $tasks]);
});

// Прогресс загрузок
Router::get('/progress', function() {
    apiAuth();
    include_once dirname(__FILE__).'/class/Transmission.class.php';
    include_once dirname(__FILE__).'/class/TransmissionRPC.class.php';
    include_once dirname(__FILE__).'/class/qBittorrent.class.php';
    include_once dirname(__FILE__).'/class/Deluge.class.php';
    include_once dirname(__FILE__).'/class/TorrServer.class.php';
    include_once dirname(__FILE__).'/class/SynologyDS.class.php';

    $settings = Database::getAllSetting();
    $csettings = [];
    if ($settings)
    {
        foreach ($settings as $row)
            $csettings[key($row)] = $row[key($row)];
    }

    if (empty($csettings['useTorrent']) || empty($csettings['torrentClient']))
    {
        Router::json(['data' => [], 'error' => 'Торрент-клиент не настроен']);
        return;
    }

    $progress = HealthCheck::getTorrentProgress($csettings);
    Router::json(['data' => $progress]);
});

// Версия системы
Router::get('/version', function() {
    apiAuth();
    $version = Sys::version();
    Router::json(['data' => $version]);
});

// Диспатч
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
Router::dispatch($method, $uri);
?>
