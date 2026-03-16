<?php
class HealthCheck
{
    // Запуск всех проверок
    public static function runAll()
    {
        $checks = [];

        // 1. Интернет
        $checks[] = self::checkSingle('internet', 'Подключение к интернету', function() {
            return Sys::checkInternet();
        });

        // 2. Конфиг
        $checks[] = self::checkSingle('config', 'Конфигурационный файл', function() {
            return Sys::checkConfigExist();
        });

        // 3. cURL
        $checks[] = self::checkSingle('curl', 'Расширение cURL', function() {
            return Sys::checkCurl();
        });

        // 4. Запись в директорию торрентов
        $torrentPath = dirname(__FILE__) . '/../torrents/';
        $checks[] = self::checkSingle('write_torrents', 'Запись в ' . basename($torrentPath), function() use ($torrentPath) {
            return Sys::checkWriteToPath($torrentPath);
        });

        // 5. Запись в системную директорию
        $sysPath = dirname(__FILE__) . '/../';
        $checks[] = self::checkSingle('write_system', 'Запись в системную директорию', function() use ($sysPath) {
            return Sys::checkWriteToPath($sysPath);
        });

        // 6. Последний запуск engine.php
        $checks[] = self::checkLastRun();

        // 7. Торрент-клиент
        $checks[] = self::checkTorrentClient();

        // 8. Куки трекеров
        $trackerChecks = self::checkTrackerCookies();
        $checks = array_merge($checks, $trackerChecks);

        return $checks;
    }

    public static function checkSingle($id, $title, $fn)
    {
        try {
            $ok = $fn();
            return [
                'id'     => $id,
                'title'  => $title,
                'status' => $ok ? 'ok' : 'error',
            ];
        } catch (Exception $e) {
            return [
                'id'     => $id,
                'title'  => $title,
                'status' => 'error',
                'detail' => $e->getMessage()
            ];
        }
    }


    // Проверка последнего запуска
    private static function checkLastRun()
    {
        $file = dirname(__FILE__) . '/../laststart.txt';
        $content = @file_get_contents($file);
        if (empty($content))
        {
            return [
                'id'     => 'last_run',
                'title'  => 'Последний запуск',
                'status' => 'warning',
                'detail' => 'Запуск ещё не производился'
            ];
        }

        // laststart.txt формат: "dd-mm-yyyy HH:MM:SS" (Sys::lastStart записывает date('d-m-Y H:i:s'))
        // Парсим через DateTime для надёжности
        $content = trim($content);
        $dt = DateTime::createFromFormat('d-m-Y H:i:s', $content);
        if ( ! $dt)
            $dt = DateTime::createFromFormat('d-m-Y H:i', $content);

        if ($dt)
        {
            $diff = time() - $dt->getTimestamp();

            $status = 'ok';
            if ($diff > 7200)
                $status = 'warning';
            if ($diff > 86400)
                $status = 'error';

            return [
                'id'       => 'last_run',
                'title'    => 'Последний запуск',
                'status'   => $status,
                'detail'   => $content,
                'last_run' => $dt->format('Y-m-d H:i:s')
            ];
        }

        return [
            'id'     => 'last_run',
            'title'  => 'Последний запуск',
            'status' => 'ok',
            'detail' => $content
        ];
    }

    // Проверка подключения к торрент-клиенту (реальный API-запрос)
    private static function checkTorrentClient()
    {
        $useTorrent = Database::getSetting('useTorrent');
        if ( ! $useTorrent)
        {
            return [
                'id'     => 'torrent_client',
                'title'  => 'Торрент-клиент',
                'status' => 'disabled',
                'detail' => 'Не настроен'
            ];
        }

        $client  = Database::getSetting('torrentClient');
        $address = Database::getSetting('torrentAddress');
        $login   = Database::getSetting('torrentLogin');
        $pass    = Database::getSetting('torrentPassword');
        $title   = 'Торрент-клиент (' . htmlspecialchars($client ?? '') . ')';

        $ok = self::pingTorrentClient($client, $address, $login, $pass);

        if ($ok)
        {
            return [
                'id'     => 'torrent_client',
                'title'  => $title,
                'status' => 'ok',
                'detail' => htmlspecialchars($address ?? '')
            ];
        }

        return [
            'id'     => 'torrent_client',
            'title'  => $title,
            'status' => 'error',
            'detail' => 'Недоступен: ' . htmlspecialchars($address ?? '')
        ];
    }

    // Реальная проверка доступности торрент-клиента через API
    private static function pingTorrentClient($client, $address, $login, $pass)
    {
        if (empty($address))
            return false;

        // 1. Проверка порта (быстрый фейл для всех клиентов)
        $parsed = self::parseAddress($address);
        $conn = @fsockopen($parsed['host'], $parsed['port'], $errno, $errstr, 5);
        if ( ! $conn)
            return false;
        fclose($conn);

        // 2. Проверка API (порт открыт, но работает ли клиент?)
        // Нормализуем адрес
        if (strpos($address, 'http') !== 0)
            $address = 'http://' . $address;
        $address = rtrim($address, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $ok = false;

        try {
            switch ($client)
            {
                case 'Transmission':
                    $ok = self::pingTransmission($ch, $address, $login, $pass);
                    break;
                case 'qBittorrent':
                    $ok = self::pingQBittorrent($ch, $address);
                    break;
                case 'Deluge':
                    $ok = self::pingDeluge($ch, $address);
                    break;
                case 'TorrServer':
                    $ok = self::pingTorrServer($ch, $address);
                    break;
                case 'SynologyDS':
                    $ok = self::pingSynology($ch, $address);
                    break;
                default:
                    // Неизвестный клиент — порт уже проверен выше
                    $ok = true;
                    break;
            }
        } catch (Exception $e) {
            $ok = false;
        }

        curl_close($ch);
        return $ok;
    }

    private static function pingTransmission($ch, $address, $login, $pass)
    {
        // Transmission RPC — сначала GET для получения X-Transmission-Session-Id (409)
        $rpcUrl = $address;
        if (strpos($rpcUrl, '/transmission') === false)
            $rpcUrl .= '/transmission/rpc';

        curl_setopt($ch, CURLOPT_URL, $rpcUrl);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ( ! empty($login))
            curl_setopt($ch, CURLOPT_USERPWD, $login . ':' . $pass);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // 409 = нужен session-id (нормальный ответ Transmission RPC)
        // 200 = успешный ответ
        // 401 = авторизация нужна (клиент работает, но пароль неверный — всё равно доступен)
        return in_array($httpCode, [200, 401, 409]);
    }

    private static function pingQBittorrent($ch, $address)
    {
        // qBittorrent — проверяем версию API
        curl_setopt($ch, CURLOPT_URL, $address . '/api/v2/app/version');
        curl_setopt($ch, CURLOPT_HEADER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // 200 = API отвечает, 403 = нужна авторизация (клиент работает)
        return in_array($httpCode, [200, 403]);
    }

    private static function pingDeluge($ch, $address)
    {
        // Deluge Web UI — JSON-RPC
        curl_setopt($ch, CURLOPT_URL, $address . '/json');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'method' => 'web.get_host_status',
            'params' => [],
            'id'     => 1
        ]));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 200 && $response !== false;
    }

    private static function pingTorrServer($ch, $address)
    {
        // TorrServer — echo endpoint
        curl_setopt($ch, CURLOPT_URL, $address . '/echo');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 200;
    }

    private static function pingSynology($ch, $address)
    {
        // Synology DownloadStation — SYNO.API.Info
        curl_setopt($ch, CURLOPT_URL, $address . '/webapi/query.cgi?api=SYNO.API.Info&version=1&method=query');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || $response === false)
            return false;

        $data = json_decode($response, true);
        return is_array($data) && isset($data['success']);
    }

    // Парсинг адреса host:port (с учётом возможного протокола)
    private static function parseAddress($address)
    {
        $host = '127.0.0.1';
        $port = 9091;

        if (empty($address))
            return ['host' => $host, 'port' => $port];

        // Убираем протокол если есть
        $clean = preg_replace('#^https?://#i', '', $address);
        // Убираем путь
        $clean = strtok($clean, '/');

        $parts = explode(':', $clean);
        if ( ! empty($parts[0]))
            $host = $parts[0];
        if ( ! empty($parts[1]))
            $port = (int)$parts[1];

        return ['host' => $host, 'port' => $port];
    }

    // Проверка куки трекеров
    private static function checkTrackerCookies()
    {
        $results = [];
        $trackers = Database::getTrackersList();
        if ( ! is_array($trackers) || empty($trackers))
            return $results;

        foreach ($trackers as $tracker)
        {
            if ( ! Database::checkTrackersCredentialsExist($tracker))
            {
                $results[] = [
                    'id'     => 'tracker_' . str_replace('.', '_', $tracker),
                    'title'  => htmlspecialchars($tracker),
                    'status' => 'warning',
                    'detail' => 'Учётные данные не указаны'
                ];
                continue;
            }

            $cookie = Database::getCookie($tracker);
            $status = ! empty($cookie) ? 'ok' : 'warning';
            $detail = ! empty($cookie) ? 'Куки в наличии' : 'Куки отсутствуют';

            $results[] = [
                'id'     => 'tracker_' . str_replace('.', '_', $tracker),
                'title'  => htmlspecialchars($tracker),
                'status' => $status,
                'detail' => $detail
            ];
        }

        return $results;
    }

    // Получение прогресса загрузок из торрент-клиента
    public static function getTorrentProgress($settings = null)
    {
        if ($settings === null)
        {
            $allSettings = Database::getAllSetting();
            $settings = [];
            if ($allSettings)
            {
                foreach ($allSettings as $row)
                    $settings[key($row)] = $row[key($row)];
            }
        }

        if (empty($settings['useTorrent']) || empty($settings['torrentClient']))
            return [];

        $client = $settings['torrentClient'];
        $progress = [];

        try {
            switch ($client)
            {
                case 'Transmission':
                    $progress = self::getTransmissionProgress($settings);
                    break;
                case 'qBittorrent':
                    $progress = self::getQBittorrentProgress($settings);
                    break;
                default:
                    return [];
            }
        } catch (Exception $e) {
            return [];
        }

        // Сопоставляем с БД по хешу
        $torrents = Database::getTorrentsList('name');
        $hashMap = [];
        if (is_array($torrents))
        {
            foreach ($torrents as $t)
            {
                if ( ! empty($t['hash']))
                    $hashMap[strtolower($t['hash'])] = $t;
            }
        }

        $result = [];
        foreach ($progress as $item)
        {
            $hash = strtolower($item['hash']);
            if (isset($hashMap[$hash]))
            {
                $item['torrent_id'] = $hashMap[$hash]['id'];
                $item['torrent_name'] = $hashMap[$hash]['name'];
                $item['tracker'] = $hashMap[$hash]['tracker'];
                $result[] = $item;
            }
        }

        return $result;
    }

    // Прогресс Transmission
    private static function getTransmissionProgress($settings)
    {
        $address = $settings['torrentAddress'] ?? '';
        // Transmission RPC ожидает полный URL http://host:port/transmission/rpc
        if (strpos($address, 'http') !== 0)
            $address = 'http://' . $address;
        // Убираем trailing slash
        $address = rtrim($address, '/');
        if (strpos($address, '/transmission') === false)
            $address .= '/transmission/rpc';

        $rpc = new TransmissionRPC(
            $address,
            $settings['torrentLogin'] ?? '',
            $settings['torrentPassword'] ?? ''
        );

        $result = $rpc->get([], ['hashString', 'name', 'percentDone', 'status', 'rateDownload', 'rateUpload', 'totalSize', 'eta']);

        $progress = [];
        if (isset($result->arguments->torrents))
        {
            foreach ($result->arguments->torrents as $t)
            {
                $status = 'unknown';
                switch ($t->status)
                {
                    case 0: $status = 'stopped'; break;
                    case 1: $status = 'check_wait'; break;
                    case 2: $status = 'checking'; break;
                    case 3: $status = 'download_wait'; break;
                    case 4: $status = 'downloading'; break;
                    case 5: $status = 'seed_wait'; break;
                    case 6: $status = 'seeding'; break;
                }

                $progress[] = [
                    'hash'          => $t->hashString,
                    'name'          => $t->name,
                    'percent'       => round($t->percentDone * 100, 1),
                    'status'        => $status,
                    'download_rate' => $t->rateDownload,
                    'upload_rate'   => $t->rateUpload,
                    'total_size'    => $t->totalSize,
                    'eta'           => $t->eta,
                ];
            }
        }
        return $progress;
    }

    // Прогресс qBittorrent
    private static function getQBittorrentProgress($settings)
    {
        $address = $settings['torrentAddress'] ?? '';
        // Добавляем протокол если нет
        if ( ! empty($address) && strpos($address, 'http') !== 0)
            $address = 'http://' . $address;
        $address = rtrim($address, '/');

        $login = $settings['torrentLogin'] ?? '';
        $password = $settings['torrentPassword'] ?? '';

        // Авторизация
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $address . '/api/v2/auth/login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HEADER         => true,
            CURLOPT_POSTFIELDS     => http_build_query(['username' => $login, 'password' => $password])
        ]);
        $response = curl_exec($ch);

        if ($response === false)
        {
            curl_close($ch);
            return [];
        }

        preg_match_all('/SID=(.*?);/', $response, $match);
        if ( ! isset($match[1][0]))
        {
            curl_close($ch);
            return [];
        }

        $cookie = 'SID=' . $match[1][0];

        // Список торрентов
        curl_setopt_array($ch, [
            CURLOPT_URL    => $address . '/api/v2/torrents/info',
            CURLOPT_COOKIE => $cookie,
            CURLOPT_HEADER => false,
            CURLOPT_POST   => false,
        ]);
        $response = curl_exec($ch);

        // Выход
        curl_setopt($ch, CURLOPT_URL, $address . '/api/v2/auth/logout');
        curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if ( ! is_array($data))
            return [];

        $progress = [];
        foreach ($data as $t)
        {
            $status = 'unknown';
            switch ($t['state'] ?? '')
            {
                case 'downloading':
                case 'forcedDL':
                    $status = 'downloading'; break;
                case 'uploading':
                case 'forcedUP':
                    $status = 'seeding'; break;
                case 'pausedDL':
                case 'pausedUP':
                    $status = 'stopped'; break;
                case 'stalledDL':
                    $status = 'download_wait'; break;
                case 'stalledUP':
                    $status = 'seed_wait'; break;
                case 'checkingDL':
                case 'checkingUP':
                    $status = 'checking'; break;
            }

            $progress[] = [
                'hash'          => $t['hash'],
                'name'          => $t['name'],
                'percent'       => round(($t['progress'] ?? 0) * 100, 1),
                'status'        => $status,
                'download_rate' => $t['dlspeed'] ?? 0,
                'upload_rate'   => $t['upspeed'] ?? 0,
                'total_size'    => $t['total_size'] ?? $t['size'] ?? 0,
                'eta'           => $t['eta'] ?? -1,
            ];
        }
        return $progress;
    }
}
?>
