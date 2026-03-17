<?php
$dir = dirname(__FILE__).'/';

class TorrServer
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

        $return = ['status' => FALSE, 'msg' => ''];

    	try
    	{
            if ( ! empty($hash))
            {
                if ($deleteDistribution)
                {
                    $data = 
                        array(
                            "action" => "rem",
                            "hash" => $hash
                        );
                    $data = json_encode($data);
                                        
                    $request_headers[] = "Content-Type: application/json";
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, array(
                        CURLOPT_POST => 1,
                        CURLOPT_FOLLOWLOCATION => 1,
                        CURLOPT_URL => 'http://'.$torrentAddress.'/torrents/',
                        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_USERPWD => $torrentLogin.':'.$torrentPassword,
                        CURLOPT_HTTPHEADER => $request_headers,
                        CURLOPT_POSTFIELDS => $data,
                    ));
                    $response = curl_exec($ch);
                    curl_close($ch);
                }
            }
            
            $name = '';
            $torrent = Database::getTorrent($id);
            if ($torrent)
                $name = str_replace(' ', '.', $torrent[0]['name']);

            #добавляем торрент в torrent-клиент
            $params = http_build_query(['link' => $file, 'save' => '', 'title' => $name, 'stat' => '']);
            $url = 'http://'.$torrentAddress.'/stream/fname?'.$params;
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_URL => $url,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $torrentLogin.':'.$torrentPassword,
            ));
            $response = curl_exec($ch);
            curl_close($ch);
            
            preg_match_all('/\"hash\":\"(.*)\"/U', $response, $res);
            if ($res[1])
            {
                $hashNew = $res[1][0];
    
                Database::updateHash($id, $hashNew);
                Database::clearWarnings('TorrServer');
                
                $return['status'] = TRUE;
                $return['hash'] = $hashNew;
            }
            else
            {
                $return['status'] = FALSE;
                $return['msg'] = 'add_fail';
            }
        }
        catch (Exception $e)
        {
            $return['status'] = FALSE;
            $return['msg'] = $e->getMessage();
        }
        
        return $return;
    }

    #форматирование статуса торрента в единый формат
    private static function formatTorrentStatus($torrent)
    {
        #TorrServer statuses: 0=TorrentAdded, 1=GettingInfo, 2=Preload, 3=Working, 4=Closed, 5=InDB
        $statMap = [
            0 => 'queued',
            1 => 'checking',
            2 => 'downloading',
            3 => 'downloading',
            4 => 'stopped',
            5 => 'stopped',
        ];

        $stat = intval($torrent['stat']);
        $status = isset($statMap[$stat]) ? $statMap[$stat] : 'unknown';
        $progress = ($stat >= 4) ? 100.0 : 0.0;
        $speed = '0 KB/s';

        return ['status' => $status, 'progress' => $progress, 'speed' => $speed];
    }

    #пакетный запрос статусов всех торрентов (один запрос списка)
    public static function getStatusBatch($hashes)
    {
        try
        {
            $settings = Database::getAllSetting();
            foreach ($settings as $row) { extract($row); }

            #запрашиваем список всех торрентов
            $data = json_encode(array('action' => 'list'));
            $request_headers = array('Content-Type: application/json');

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_URL            => 'http://'.$torrentAddress.'/torrents',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $torrentLogin.':'.$torrentPassword,
                CURLOPT_HTTPHEADER     => $request_headers,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ));
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false)
                return [];

            $torrents = json_decode($response, true);
            if (!is_array($torrents))
                return [];

            $hashSet = array_flip($hashes);
            $statuses = [];
            foreach ($torrents as $torrent)
            {
                if (isset($torrent['hash']) && isset($hashSet[$torrent['hash']]))
                    $statuses[$torrent['hash']] = self::formatTorrentStatus($torrent);
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

            #запрашиваем информацию о торренте
            $data = json_encode(array('action' => 'get', 'hash' => $hash));
            $request_headers = array('Content-Type: application/json');

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_URL            => 'http://'.$torrentAddress.'/torrents',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $torrentLogin.':'.$torrentPassword,
                CURLOPT_HTTPHEADER     => $request_headers,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ));
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false)
                return ['status' => 'unknown'];

            $torrent = json_decode($response, true);
            if (!is_array($torrent) || !isset($torrent['stat']))
                return ['status' => 'unknown'];

            return self::formatTorrentStatus($torrent);
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>