<?php
$dir = dirname(__FILE__).'/';
include_once $dir.'TransmissionRPC.class.php';

class Transmission
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

    	try
    	{
            $rpc = new TransmissionRPC('http://'.$torrentAddress.'/transmission/rpc', $torrentLogin, $torrentPassword);
            if ($debug)
        	    $rpc->debug=true;
        	$result = $rpc->sstats();
    
        	$individualPath = Database::getTorrentDownloadPath($id);
        	if ( ! empty($individualPath))
            	$pathToDownload = $individualPath;

        	if ( ! empty($hash))
        	{
            	$delOpt = 'false';
            	if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' || $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
            	{
                    if ($deleteOldFiles)
                        $delOpt = 'true';
            	    #удяляем существующую закачку из torrent-клиента
            	    if ($deleteDistribution)
                	    $result = $rpc->remove($hash, $delOpt);                    
            	}
            	else
            	{
            	    #удяляем существующую закачку из torrent-клиента
            	    $result = $rpc->remove($hash, $delOpt);
            	}
            }

            #добавляем торрент в torrent-клиент
            $result = $rpc->add($file, $pathToDownload);

            if (isset($result->arguments->torrent_added))
            {
                $hashNew = $result->arguments->torrent_added->hashString;
                #обновляем hash в базе
                Database::updateHash($id, $hashNew);
                
                //сбрасываем варнинг
                Database::clearWarnings('Transmission');
                $return['status'] = TRUE;
                $return['hash'] = $hashNew;
            }
            elseif (isset($result->arguments->torrent_duplicate))
            {
                $hashNew = $result->arguments->torrent_duplicate->hashString;
                #обновляем hash в базе
                Database::updateHash($id, $hashNew);
                
                //сбрасываем варнинг
                Database::clearWarnings('Transmission');
                $return['status'] = TRUE;
                $return['hash'] = $hashNew;                
            }
            elseif (preg_match('/invalid or corrupt torrent file/i', $result->result))
            {
                $return['status'] = FALSE;
                $return['msg'] = 'torrent_file_fail';
            }
            elseif (preg_match('/http error 0: No Response/i', $result->result))
            {
                $return['status'] = FALSE;
                $return['msg'] = 'no_response';
            }
            else
            {
        	    $return['status'] = FALSE;
                $return['msg'] = 'unknown';
    	    }
        }
        catch (Exception $e)
        {
    	    if (preg_match('/Invalid username\/password\./', $e->getMessage()))
    	    {
    		    $return['status'] = FALSE;
                $return['msg'] = 'log_passwd';
    	    }
    	    elseif (preg_match('/Unable to connect to/U', $e->getMessage()))
    	    {
    		    $return['status'] = FALSE;
                $return['msg'] = 'connect_fail';
    	    }
    	    else
    	    {
        	    $return['status'] = FALSE;
        	    $return['msg'] = 'unknown';
            }
        }
    	return $return;
    }

    #форматируем данные торрента в единый формат
    private static function formatTorrentStatus($torrent)
    {
        $statusMap = [
            0 => 'stopped',
            1 => 'queued',     // check_wait
            2 => 'checking',
            3 => 'queued',     // download_wait
            4 => 'downloading',
            5 => 'queued',     // seed_wait
            6 => 'seeding',
        ];
        $st = isset($torrent->status) ? $torrent->status : -1;
        $status = isset($statusMap[$st]) ? $statusMap[$st] : 'unknown';
        $progress = isset($torrent->percentDone) ? round($torrent->percentDone * 100, 1) : 0;
        $speedBytes = isset($torrent->rateDownload) ? $torrent->rateDownload : 0;
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

            $rpc = new TransmissionRPC('http://'.$torrentAddress.'/transmission/rpc', $torrentLogin, $torrentPassword);
            #передаем все хеши, чтобы получить статусы за один запрос
            $result = $rpc->get($hashes, ['status', 'percentDone', 'rateDownload', 'hashString']);

            if (!isset($result->arguments->torrents))
                return [];

            $hashSet = array_flip($hashes);
            $statuses = [];
            foreach ($result->arguments->torrents as $torrent)
            {
                if (isset($torrent->hashString) && isset($hashSet[$torrent->hashString]))
                {
                    $statuses[$torrent->hashString] = self::formatTorrentStatus($torrent);
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

            $rpc = new TransmissionRPC('http://'.$torrentAddress.'/transmission/rpc', $torrentLogin, $torrentPassword);
            $result = $rpc->get($hash, ['status', 'percentDone', 'rateDownload']);

            if ( ! isset($result->arguments->torrents[0]))
                return ['status' => 'unknown'];

            $torrent = $result->arguments->torrents[0];
            return self::formatTorrentStatus($torrent);
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>