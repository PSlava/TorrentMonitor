<?php
class qBittorrent
{
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

        $data = array('username' => $torrentLogin, 'password' => $torrentPassword);

        //Авторизация
        $MainCurl = curl_init();
        curl_setopt_array($MainCurl, array(
            CURLOPT_URL => $torrentAddress."/api/v2/auth/login",
            CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_VERBOSE => true,
            CURLOPT_HEADER => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ));
    
        $response=curl_exec($MainCurl);
    
        preg_match_all("/SID=(.*?);/", $response, $match);
        if (!isset($match[1][0])) {
            $return['status'] = FALSE;
            $return['msg'] = 'auth_failed';
            return $return;
        }
        $cookie = "SID=".$match[1][0];
        curl_setopt($MainCurl, CURLOPT_COOKIE, $cookie);
        curl_setopt($MainCurl, CURLOPT_HEADER, false);

        if ( ! empty($hash))
        {
            $data = array(
                'hashes' => $hash,
                'deleteFiles' => 'false'
            );

            if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' ||  $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
            {
                if ($deleteOldFiles)
                    $data['deleteFiles'] = 'true';
                #удаляем существующую закачку из torrent-клиента
                if ($deleteDistribution)
                {
                    curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/delete");
                    curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query($data));
                    curl_exec($MainCurl);
                }
            }
            else
            {
                #удаляем существующую закачку из torrent-клиента
                curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/delete");
                curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_exec($MainCurl);
            }
        }
        
        //Формируется тело запроса
        $data = array(
            'urls' => $file,
            'autoTMM' => true,
            'savepath' => $pathToDownload,
            'root_folder' => true,
        );
        
        //формируется заголовок запроса
        $request_headers = array(
            "Cookie: ".$cookie
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $torrentAddress."/api/v2/torrents/add",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_VERBOSE => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $request_headers,
            CURLOPT_COOKIE => $cookie,
            CURLOPT_POSTFIELDS => $data
        ));
        $response = curl_exec($ch);
        curl_close($ch);

        if (preg_match('/Ok/', $response)) {
            sleep(3);
            
            //получение хэша торрента
            $data = array(
                'filter' => 'all',
                'limit' => '1',
                'sort' => 'added_on',
                'reverse' => 'true'
            );
            curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/info");
            curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query($data));
            $response = curl_exec($MainCurl);
            $rdata = json_decode($response, true);
            if (!is_array($rdata) || empty($rdata) || !isset($rdata[0]['hash'])) {
                $return['status'] = FALSE;
                $return['msg'] = 'add_fail';
                return $return;
            }
            $hashNew = $rdata[0]['hash'];

            #обновляем hash в базе
            Database::updateHash($id, $hashNew);

            //сбрасываем варнинг
            Database::clearWarnings('qBittorrent');
            $return['status'] = TRUE;
            $return['hash'] = $hashNew;
        } else {
            $return['status'] = FALSE;
            $return['msg'] = 'add_fail';
        }

        //выход
        curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/auth/logout");
        curl_exec($MainCurl);
        curl_close($MainCurl);

        return $return;
    }

    #маппинг статусов qBittorrent
    private static $stateMap = [
        'downloading'  => 'downloading',
        'stalledDL'    => 'downloading',
        'metaDL'       => 'downloading',
        'forcedDL'     => 'downloading',
        'uploading'    => 'seeding',
        'stalledUP'    => 'seeding',
        'forcedUP'     => 'seeding',
        'pausedDL'     => 'paused',
        'pausedUP'     => 'paused',
        'checkingDL'   => 'checking',
        'checkingUP'   => 'checking',
        'checkingResumeData' => 'checking',
        'queuedDL'     => 'queued',
        'queuedUP'     => 'queued',
        'error'        => 'error',
        'missingFiles' => 'error',
        'moving'       => 'downloading',
    ];

    #форматируем данные торрента в единый формат
    private static function formatTorrentStatus($torrent)
    {
        $state = isset($torrent['state']) ? $torrent['state'] : '';
        $status = isset(self::$stateMap[$state]) ? self::$stateMap[$state] : 'unknown';
        $progress = round($torrent['progress'] * 100, 1);
        $speedBytes = isset($torrent['dlspeed']) ? $torrent['dlspeed'] : 0;
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

            $data = array('username' => $torrentLogin, 'password' => $torrentPassword);

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $torrentAddress."/api/v2/auth/login",
                CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HEADER => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
            ));
            $response = curl_exec($ch);

            preg_match_all("/SID=(.*?);/", $response, $match);
            if (!isset($match[1][0]))
            {
                curl_close($ch);
                return [];
            }
            $cookie = "SID=".$match[1][0];

            #один запрос на все торренты
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            curl_setopt($ch, CURLOPT_URL, $torrentAddress."/api/v2/torrents/info");
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            $response = curl_exec($ch);

            #выход
            curl_setopt($ch, CURLOPT_URL, $torrentAddress."/api/v2/auth/logout");
            curl_exec($ch);
            curl_close($ch);

            $rdata = json_decode($response, true);
            if (!is_array($rdata))
                return [];

            #индексируем по хешу
            $hashSet = array_flip($hashes);
            $result = [];
            foreach ($rdata as $torrent)
            {
                if (isset($torrent['hash']) && isset($hashSet[$torrent['hash']]))
                {
                    $result[$torrent['hash']] = self::formatTorrentStatus($torrent);
                }
            }
            return $result;
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

            $data = array('username' => $torrentLogin, 'password' => $torrentPassword);

            #авторизация
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $torrentAddress."/api/v2/auth/login",
                CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HEADER => true,
                CURLOPT_POSTFIELDS => http_build_query($data)
            ));
            $response = curl_exec($ch);

            preg_match_all("/SID=(.*?);/", $response, $match);
            if (!isset($match[1][0]))
            {
                curl_close($ch);
                return ['status' => 'unknown'];
            }
            $cookie = "SID=".$match[1][0];

            #запрашиваем информацию о торренте
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            curl_setopt($ch, CURLOPT_URL, $torrentAddress."/api/v2/torrents/info");
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('hashes' => $hash)));
            $response = curl_exec($ch);

            #выход
            curl_setopt($ch, CURLOPT_URL, $torrentAddress."/api/v2/auth/logout");
            curl_exec($ch);
            curl_close($ch);

            $rdata = json_decode($response, true);
            if (!is_array($rdata) || empty($rdata) || !isset($rdata[0]))
                return ['status' => 'unknown'];

            $torrent = $rdata[0];
            return self::formatTorrentStatus($torrent);
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>
