<?php
class Deluge
{
    /**
     * Выполняет JSON-RPC запрос к Deluge Web UI
     *
     * @param resource $ch       cURL-хэндл
     * @param string   $method   Имя метода JSON-RPC
     * @param array    $params   Параметры метода
     * @param int      $reqId    ID запроса
     * @return array|false       Декодированный ответ или false при ошибке
     */
    private static function jsonRpc($ch, $method, $params, $reqId = 1)
    {
        $payload = json_encode(array(
            'method' => $method,
            'params' => $params,
            'id'     => $reqId,
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);

        if ($response === false)
            return false;

        $data = json_decode($response, true);
        if (!is_array($data))
            return false;

        return $data;
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

        #инициализируем cURL для работы с Deluge Web JSON-RPC
        $ch = curl_init();
        $cookieFile = tempnam(sys_get_temp_dir(), 'deluge_');
        curl_setopt_array($ch, array(
            CURLOPT_URL            => 'http://' . $torrentAddress . '/json',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ));

        #авторизация — в Deluge Web UI нужен только пароль
        $auth = self::jsonRpc($ch, 'auth.login', array($torrentPassword), 1);
        if ($auth === false || empty($auth['result']))
        {
            curl_close($ch);
            @unlink($cookieFile);
            $return['status'] = FALSE;
            $return['msg'] = 'auth_failed';
            return $return;
        }

        #подключаемся к первому доступному демону
        $hosts = self::jsonRpc($ch, 'web.get_hosts', array(), 2);
        if ($hosts !== false && !empty($hosts['result']))
        {
            $hostId = $hosts['result'][0][0];
            $connectResult = self::jsonRpc($ch, 'web.connect', array($hostId), 3);
            if ($connectResult === false)
            {
                curl_close($ch);
                @unlink($cookieFile);
                $return['status'] = FALSE;
                $return['msg'] = 'connect_fail';
                return $return;
            }
        }
        else
        {
            curl_close($ch);
            @unlink($cookieFile);
            $return['status'] = FALSE;
            $return['msg'] = 'connect_fail';
            return $return;
        }

        #удаляем старую раздачу если нужно
        if ( ! empty($hash))
        {
            $removeData = false;

            if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' || $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
            {
                if ($deleteOldFiles)
                    $removeData = true;
                #удяляем существующую закачку из torrent-клиента
                if ($deleteDistribution)
                    self::jsonRpc($ch, 'core.remove_torrent', array($hash, $removeData), 4);
            }
            else
            {
                #удяляем существующую закачку из torrent-клиента
                self::jsonRpc($ch, 'core.remove_torrent', array($hash, $removeData), 4);
            }
        }

        #добавляем торрент в torrent-клиент
        $addResult = self::jsonRpc($ch, 'core.add_torrent_url', array(
            $file,
            array('download_location' => $pathToDownload),
        ), 5);

        if ($addResult === false || $addResult['result'] === null)
        {
            curl_close($ch);
            @unlink($cookieFile);
            $return['status'] = FALSE;
            $return['msg'] = 'add_fail';
            return $return;
        }

        #результат add_torrent_url — хеш добавленного торрента
        $hashNew = $addResult['result'];

        #обновляем hash в базе
        Database::updateHash($id, $hashNew);

        #сбрасываем варнинг
        Database::clearWarnings('Deluge');
        $return['status'] = TRUE;
        $return['hash'] = $hashNew;

        curl_close($ch);
        @unlink($cookieFile);
        return $return;
    }

    #форматируем данные торрента в единый формат
    private static function formatTorrentStatus($torrent)
    {
        $stateMap = [
            'Downloading' => 'downloading',
            'Seeding'     => 'seeding',
            'Paused'      => 'paused',
            'Checking'    => 'checking',
            'Queued'      => 'queued',
            'Error'       => 'error',
        ];
        $state = isset($torrent['state']) ? $torrent['state'] : '';
        $status = isset($stateMap[$state]) ? $stateMap[$state] : 'unknown';
        $progress = round(floatval($torrent['progress']), 1);
        $speedBytes = isset($torrent['download_payload_rate']) ? $torrent['download_payload_rate'] : 0;
        $speed = ($speedBytes >= 1048576)
            ? round($speedBytes / 1048576, 1) . ' MB/s'
            : round($speedBytes / 1024, 1) . ' KB/s';
        return ['status' => $status, 'progress' => $progress, 'speed' => $speed];
    }

    #пакетный запрос статусов всех торрентов (одна авторизация)
    public static function getStatusBatch($hashes)
    {
        try
        {
            $settings = Database::getAllSetting();
            foreach ($settings as $row) { extract($row); }

            $ch = curl_init();
            $cookieFile = tempnam(sys_get_temp_dir(), 'deluge_');
            curl_setopt_array($ch, array(
                CURLOPT_URL            => 'http://' . $torrentAddress . '/json',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ));

            $auth = self::jsonRpc($ch, 'auth.login', array($torrentPassword), 1);
            if ($auth === false || empty($auth['result']))
            {
                curl_close($ch);
                @unlink($cookieFile);
                return [];
            }

            $hosts = self::jsonRpc($ch, 'web.get_hosts', array(), 2);
            if ($hosts !== false && !empty($hosts['result']))
            {
                $hostId = $hosts['result'][0][0];
                self::jsonRpc($ch, 'web.connect', array($hostId), 3);
            }

            #один запрос на все торренты: web.update_ui принимает (keys, filter_dict)
            $result = self::jsonRpc($ch, 'web.update_ui', array(
                array('state', 'progress', 'download_payload_rate', 'hash'),
                (object)array()
            ), 4);

            curl_close($ch);
            @unlink($cookieFile);

            if ($result === false || !isset($result['result']['torrents']))
                return [];

            $hashSet = array_flip($hashes);
            $statuses = [];
            foreach ($result['result']['torrents'] as $hash => $torrent)
            {
                if (isset($hashSet[$hash]))
                {
                    $statuses[$hash] = self::formatTorrentStatus($torrent);
                }
            }
            return $statuses;
        }
        catch (Exception $e)
        {
            return [];
        }
    }

    #получаем статус закачки из торрент-клиента
    public static function getStatus($hash)
    {
        try
        {
            #получаем настройки из базы
            $settings = Database::getAllSetting();
            foreach ($settings as $row) { extract($row); }

            #инициализируем cURL
            $ch = curl_init();
            $cookieFile = tempnam(sys_get_temp_dir(), 'deluge_');
            curl_setopt_array($ch, array(
                CURLOPT_URL            => 'http://' . $torrentAddress . '/json',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ));

            #авторизация
            $auth = self::jsonRpc($ch, 'auth.login', array($torrentPassword), 1);
            if ($auth === false || empty($auth['result']))
            {
                curl_close($ch);
                @unlink($cookieFile);
                return ['status' => 'unknown'];
            }

            #подключаемся к демону
            $hosts = self::jsonRpc($ch, 'web.get_hosts', array(), 2);
            if ($hosts !== false && !empty($hosts['result']))
            {
                $hostId = $hosts['result'][0][0];
                self::jsonRpc($ch, 'web.connect', array($hostId), 3);
            }
            else
            {
                curl_close($ch);
                @unlink($cookieFile);
                return ['status' => 'unknown'];
            }

            #запрашиваем статус торрента
            $result = self::jsonRpc($ch, 'core.get_torrent_status', array(
                $hash,
                array('state', 'progress', 'download_payload_rate')
            ), 4);

            curl_close($ch);
            @unlink($cookieFile);

            if ($result === false || !isset($result['result']) || empty($result['result']))
                return ['status' => 'unknown'];

            $torrent = $result['result'];
            return self::formatTorrentStatus($torrent);
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>
