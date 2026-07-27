<?php
class Deluge
{
    #выполняем JSON-RPC запрос к deluge-web
    private static function request($baseUrl, $cookieFile, $method, array $params = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $baseUrl.'/json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS     => json_encode(array('method' => $method, 'params' => $params, 'id' => 1)),
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => 30,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === FALSE || $error !== '' || $httpCode != 200)
            return NULL;

        $data = json_decode($response, true);
        if ( ! is_array($data) || ! empty($data['error']))
            return NULL;

        return isset($data['result']) ? $data['result'] : NULL;
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
        if (!preg_match('~^https?://~i', $torrentAddress))
            $torrentAddress = 'http://'.$torrentAddress;

        $individualPath = Database::getTorrentDownloadPath($id);
        if ( ! empty($individualPath))
            $pathToDownload = $individualPath;

        $cookieFile = tempnam(sys_get_temp_dir(), 'deluge');

        try
        {
            #авторизация в deluge-web
            $loggedIn = self::request($torrentAddress, $cookieFile, 'auth.login', array($torrentPassword));
            if ($loggedIn !== TRUE)
            {
                $return['status'] = FALSE;
                $return['msg'] = 'log_passwd';
                return $return;
            }

            #проверяем подключение к демону, при необходимости подключаемся к первому доступному хосту
            $connected = self::request($torrentAddress, $cookieFile, 'web.connected');
            if ($connected !== TRUE)
            {
                $hosts = self::request($torrentAddress, $cookieFile, 'web.get_hosts');
                if (empty($hosts[0][0]))
                {
                    $return['status'] = FALSE;
                    $return['msg'] = 'connect_fail';
                    return $return;
                }

                $connectResult = self::request($torrentAddress, $cookieFile, 'web.connect', array($hosts[0][0]));
                if ($connectResult === NULL)
                {
                    $return['status'] = FALSE;
                    $return['msg'] = 'connect_fail';
                    return $return;
                }
            }

            if ( ! empty($hash))
            {
                $removeData = FALSE;
                $doRemove = TRUE;
                if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' ||  $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
                {
                    $removeData = ! empty($deleteOldFiles);
                    $doRemove = ! empty($deleteDistribution);
                }

                if ($doRemove)
                {
                    #удаляем существующую закачку из torrent-клиента
                    self::request($torrentAddress, $cookieFile, 'core.remove_torrent', array($hash, $removeData));
                }
            }

            #добавляем торрент в torrent-клиент
            $fileContent = file_get_contents($file);
            if ($fileContent === FALSE)
            {
                $return['status'] = FALSE;
                $return['msg'] = 'add_fail';
                return $return;
            }

            $hashNew = self::request($torrentAddress, $cookieFile, 'core.add_torrent_file', array(
                basename($file),
                base64_encode($fileContent),
                array('download_location' => $pathToDownload),
            ));

            if (empty($hashNew))
            {
                $return['status'] = FALSE;
                $return['msg'] = 'add_fail';
            }
            else
            {
                #обновляем hash в базе
                Database::updateHash($id, $hashNew);

                //сбрасываем варнинг
                Database::clearWarnings('Deluge');
                $return['status'] = TRUE;
                $return['hash'] = $hashNew;
            }
        }
        finally
        {
            @unlink($cookieFile);
        }

        return $return;
    }

    #удаляем раздачу из torrent-клиента (без добавления новой)
    public static function remove($hash)
    {
        $settings = Database::getAllSetting();
        foreach ($settings as $row)
        {
            extract($row);
        }

        if (!preg_match('~^https?://~i', $torrentAddress))
            $torrentAddress = 'http://'.$torrentAddress;

        $cookieFile = tempnam(sys_get_temp_dir(), 'deluge');
        try
        {
            $loggedIn = self::request($torrentAddress, $cookieFile, 'auth.login', array($torrentPassword));
            if ($loggedIn !== TRUE)
                return array('status' => FALSE, 'msg' => 'log_passwd');

            $connected = self::request($torrentAddress, $cookieFile, 'web.connected');
            if ($connected !== TRUE)
            {
                $hosts = self::request($torrentAddress, $cookieFile, 'web.get_hosts');
                if (empty($hosts[0][0]))
                    return array('status' => FALSE, 'msg' => 'connect_fail');

                if (self::request($torrentAddress, $cookieFile, 'web.connect', array($hosts[0][0])) === NULL)
                    return array('status' => FALSE, 'msg' => 'connect_fail');
            }

            self::request($torrentAddress, $cookieFile, 'core.remove_torrent', array($hash, ! empty($deleteOldFiles)));

            Database::clearWarnings('Deluge');
            return array('status' => TRUE);
        }
        finally
        {
            @unlink($cookieFile);
        }
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
        $progress = round(floatval(isset($torrent['progress']) ? $torrent['progress'] : 0), 1);
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
            if (!preg_match('~^https?://~i', $torrentAddress))
                $torrentAddress = 'http://'.$torrentAddress;

            $cookieFile = tempnam(sys_get_temp_dir(), 'deluge');
            try
            {
                $loggedIn = self::request($torrentAddress, $cookieFile, 'auth.login', array($torrentPassword));
                if ($loggedIn !== TRUE)
                    return [];

                $connected = self::request($torrentAddress, $cookieFile, 'web.connected');
                if ($connected !== TRUE)
                {
                    $hosts = self::request($torrentAddress, $cookieFile, 'web.get_hosts');
                    if (empty($hosts[0][0]))
                        return [];
                    if (self::request($torrentAddress, $cookieFile, 'web.connect', array($hosts[0][0])) === NULL)
                        return [];
                }

                #один запрос на все торренты: web.update_ui принимает (keys, filter_dict)
                $result = self::request($torrentAddress, $cookieFile, 'web.update_ui', array(
                    array('state', 'progress', 'download_payload_rate', 'hash'),
                    (object)array(),
                ));

                if (!is_array($result) || !isset($result['torrents']))
                    return [];

                $hashSet = array_flip($hashes);
                $statuses = [];
                foreach ($result['torrents'] as $hash => $torrent)
                {
                    if (isset($hashSet[$hash]))
                        $statuses[$hash] = self::formatTorrentStatus($torrent);
                }
                return $statuses;
            }
            finally
            {
                @unlink($cookieFile);
            }
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
            $settings = Database::getAllSetting();
            foreach ($settings as $row) { extract($row); }
            if (!preg_match('~^https?://~i', $torrentAddress))
                $torrentAddress = 'http://'.$torrentAddress;

            $cookieFile = tempnam(sys_get_temp_dir(), 'deluge');
            try
            {
                $loggedIn = self::request($torrentAddress, $cookieFile, 'auth.login', array($torrentPassword));
                if ($loggedIn !== TRUE)
                    return ['status' => 'unknown'];

                $connected = self::request($torrentAddress, $cookieFile, 'web.connected');
                if ($connected !== TRUE)
                {
                    $hosts = self::request($torrentAddress, $cookieFile, 'web.get_hosts');
                    if (empty($hosts[0][0]))
                        return ['status' => 'unknown'];
                    if (self::request($torrentAddress, $cookieFile, 'web.connect', array($hosts[0][0])) === NULL)
                        return ['status' => 'unknown'];
                }

                $torrent = self::request($torrentAddress, $cookieFile, 'core.get_torrent_status', array(
                    $hash,
                    array('state', 'progress', 'download_payload_rate'),
                ));

                if (!is_array($torrent) || empty($torrent))
                    return ['status' => 'unknown'];

                return self::formatTorrentStatus($torrent);
            }
            finally
            {
                @unlink($cookieFile);
            }
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>
