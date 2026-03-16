<?php
class aria2
{
    #вспомогательный метод: отправка JSON-RPC запроса к aria2
    private static function rpcRequest($url, $secret, $method, $params = [])
    {
        #если указан secret token — добавляем его первым параметром
        if ( ! empty($secret))
            array_unshift($params, 'token:' . $secret);

        $data = json_encode([
            'jsonrpc' => '2.0',
            'id'      => '1',
            'method'  => $method,
            'params'  => $params
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false)
            return ['error' => $error];

        $result = json_decode($response, true);
        if ( ! is_array($result))
            return ['error' => 'Некорректный ответ aria2'];

        if (isset($result['error']))
            return ['error' => $result['error']['message'] ?? 'Неизвестная ошибка RPC'];

        return $result;
    }

    #формирование URL для JSON-RPC из адреса трекера
    private static function buildUrl($torrentAddress)
    {
        $url = $torrentAddress;
        #добавляем http:// если протокол не указан
        if ( ! preg_match('#^https?://#i', $url))
            $url = 'http://' . $url;

        #добавляем /jsonrpc если нет пути
        $parsed = parse_url($url);
        if (empty($parsed['path']) || $parsed['path'] === '/')
            $url = rtrim($url, '/') . '/jsonrpc';

        return $url;
    }

    #поиск GID закачки по BitTorrent info hash
    private static function findGidByHash($url, $secret, $hash)
    {
        if (empty($hash))
            return null;

        $hashLower = strtolower($hash);

        #ищем среди активных закачек
        $result = self::rpcRequest($url, $secret, 'aria2.tellActive', [['gid', 'infoHash']]);
        if ( ! isset($result['error']) && isset($result['result']))
        {
            foreach ($result['result'] as $item)
            {
                if (isset($item['infoHash']) && strtolower($item['infoHash']) === $hashLower)
                    return $item['gid'];
            }
        }

        #ищем среди ожидающих (до 1000 записей)
        $result = self::rpcRequest($url, $secret, 'aria2.tellWaiting', [0, 1000, ['gid', 'infoHash']]);
        if ( ! isset($result['error']) && isset($result['result']))
        {
            foreach ($result['result'] as $item)
            {
                if (isset($item['infoHash']) && strtolower($item['infoHash']) === $hashLower)
                    return $item['gid'];
            }
        }

        #ищем среди остановленных (до 1000 записей)
        $result = self::rpcRequest($url, $secret, 'aria2.tellStopped', [0, 1000, ['gid', 'infoHash']]);
        if ( ! isset($result['error']) && isset($result['result']))
        {
            foreach ($result['result'] as $item)
            {
                if (isset($item['infoHash']) && strtolower($item['infoHash']) === $hashLower)
                    return $item['gid'];
            }
        }

        return null;
    }

    #добавляем новую закачку в torrent-клиент, обновляем hash в базе
    public static function addNew($id, $file, $hash, $tracker)
    {
        #получаем настройки из базы
        $settings = Database::getAllSetting();
        foreach ($settings as $row)
        {
        	extract($row);
        }
        $individualPath = Database::getTorrentDownloadPath($id);
        if ( ! empty($individualPath))
            $pathToDownload = $individualPath;

        $url    = self::buildUrl($torrentAddress);
        $secret = $torrentPassword;

        try
        {
            #удяляем существующую закачку из torrent-клиента
            if ( ! empty($hash))
            {
                $gid = self::findGidByHash($url, $secret, $hash);

                if ($gid !== null)
                {
                    if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' || $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
                    {
                        if ($deleteDistribution)
                        {
                            #удаляем с файлами если настроено
                            if ($deleteOldFiles)
                                self::rpcRequest($url, $secret, 'aria2.removeDownloadResult', [$gid]);

                            self::rpcRequest($url, $secret, 'aria2.forceRemove', [$gid]);
                        }
                    }
                    else
                    {
                        self::rpcRequest($url, $secret, 'aria2.forceRemove', [$gid]);
                    }
                }
            }

            #добавляем торрент в aria2
            $options = ['dir' => $pathToDownload];
            $result = self::rpcRequest($url, $secret, 'aria2.addUri', [[$file], $options]);

            if (isset($result['error']))
            {
                $return['status'] = FALSE;
                $return['msg']    = 'add_fail';
                return $return;
            }

            $gidNew = $result['result'];

            #пробуем получить info hash с несколькими попытками
            $hashNew = $gidNew;
            for ($attempt = 0; $attempt < 5; $attempt++)
            {
                sleep(1);
                $statusResult = self::rpcRequest($url, $secret, 'aria2.tellStatus', [$gidNew, ['infoHash']]);
                if (!isset($statusResult['error']) && isset($statusResult['result']['infoHash']))
                {
                    $hashNew = $statusResult['result']['infoHash'];
                    break;
                }
            }

            #обновляем hash в базе
            Database::updateHash($id, $hashNew);

            #сбрасываем варнинг
            Database::clearWarnings('aria2');
            $return['status'] = TRUE;
            $return['hash']   = $hashNew;
        }
        catch (Exception $e)
        {
            if (preg_match('/Unable to connect/i', $e->getMessage()))
            {
                $return['status'] = FALSE;
                $return['msg']    = 'connect_fail';
            }
            else
            {
                $return['status'] = FALSE;
                $return['msg']    = 'add_fail';
            }
        }

        return $return;
    }

    #получаем статус закачки из торрент-клиента
    public static function getStatus($hash)
    {
        try
        {
            #получаем настройки из базы
            $settings = Database::getAllSetting();
            foreach ($settings as $row) { extract($row); }

            $url    = self::buildUrl($torrentAddress);
            $secret = $torrentPassword;

            #ищем GID по хешу торрента
            $gid = self::findGidByHash($url, $secret, $hash);
            if ($gid === null)
                return ['status' => 'unknown'];

            #запрашиваем статус
            $result = self::rpcRequest($url, $secret, 'aria2.tellStatus', [
                $gid,
                ['status', 'completedLength', 'totalLength', 'downloadSpeed']
            ]);

            if (isset($result['error']) || !isset($result['result']))
                return ['status' => 'unknown'];

            $torrent = $result['result'];

            #маппинг статусов aria2
            $stateMap = [
                'active'   => 'downloading',
                'waiting'  => 'queued',
                'paused'   => 'paused',
                'error'    => 'error',
                'complete' => 'seeding',
                'removed'  => 'stopped',
            ];

            $state = isset($torrent['status']) ? $torrent['status'] : '';

            #active может быть раздачей если уже скачано полностью
            if ($state === 'active')
            {
                $completed = intval($torrent['completedLength']);
                $total = intval($torrent['totalLength']);
                if ($total > 0 && $completed >= $total)
                    $status = 'seeding';
                else
                    $status = 'downloading';
            }
            else
            {
                $status = isset($stateMap[$state]) ? $stateMap[$state] : 'unknown';
            }

            #прогресс
            $completed = intval($torrent['completedLength']);
            $total = intval($torrent['totalLength']);
            $progress = ($total > 0) ? round($completed / $total * 100, 1) : 0;

            #скорость загрузки
            $speedBytes = intval($torrent['downloadSpeed']);
            $speed = ($speedBytes >= 1048576)
                ? round($speedBytes / 1048576, 1) . ' MB/s'
                : round($speedBytes / 1024, 1) . ' KB/s';

            return ['status' => $status, 'progress' => $progress, 'speed' => $speed];
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>
