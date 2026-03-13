<?php
class HealthCheck
{
    // Запуск всех проверок
    public static function runAll()
    {
        $checks = [];

        // 1. Интернет
        $checks[] = self::check('internet', 'Подключение к интернету', function() {
            return Sys::checkInternet();
        });

        // 2. Конфиг
        $checks[] = self::check('config', 'Конфигурационный файл', function() {
            return Sys::checkConfigExist();
        });

        // 3. cURL
        $checks[] = self::check('curl', 'Расширение cURL', function() {
            return Sys::checkCurl();
        });

        // 4. Запись в директорию торрентов
        $torrentPath = dirname(__FILE__) . '/../torrents/';
        $checks[] = self::check('write_torrents', 'Запись в ' . basename($torrentPath), function() use ($torrentPath) {
            return Sys::checkWriteToPath($torrentPath);
        });

        // 5. Запись в системную директорию
        $sysPath = dirname(__FILE__) . '/../';
        $checks[] = self::check('write_system', 'Запись в системную директорию', function() use ($sysPath) {
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

    private static function check($id, $title, $fn)
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
            $hours = round($diff / 3600, 1);

            $status = 'ok';
            if ($diff > 7200)
                $status = 'warning';
            if ($diff > 86400)
                $status = 'error';

            return [
                'id'     => 'last_run',
                'title'  => 'Последний запуск',
                'status' => $status,
                'detail' => $content . ' (' . $hours . ' ч. назад)'
            ];
        }

        return [
            'id'     => 'last_run',
            'title'  => 'Последний запуск',
            'status' => 'ok',
            'detail' => $content
        ];
    }

    // Проверка подключения к торрент-клиенту
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

        $client = Database::getSetting('torrentClient');
        $address = Database::getSetting('torrentAddress');

        // Извлекаем host:port, убирая протокол если есть
        $parsed = self::parseAddress($address);
        $host = $parsed['host'];
        $port = $parsed['port'];

        $conn = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($conn)
        {
            fclose($conn);
            return [
                'id'     => 'torrent_client',
                'title'  => 'Торрент-клиент (' . htmlspecialchars($client) . ')',
                'status' => 'ok',
                'detail' => htmlspecialchars($address)
            ];
        }

        return [
            'id'     => 'torrent_client',
            'title'  => 'Торрент-клиент (' . htmlspecialchars($client) . ')',
            'status' => 'error',
            'detail' => 'Недоступен: ' . htmlspecialchars($address)
        ];
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
