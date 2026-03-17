<?php
$dir = dirname(__FILE__).'/';

class SynologyDS
{
    public static $torrentAddress;
    public static $torrentLogin;
    public static $torrentPassword;
    public static $debug;
    public static $schema;

    private static function _login()
    {
        $query = http_build_query([
            'api' => 'SYNO.API.Auth',
            'version' => '7',
            'method' => 'login',
            'account' => self::$torrentLogin,
            'passwd' => self::$torrentPassword,
            'session' => 'DownloadStation',
            'format' => 'sid'
        ]);
        $raw = @file_get_contents(self::$schema.'://'.self::$torrentAddress.'/webapi/auth.cgi?'.$query);
        if ($raw === false) return FALSE;
        $response = json_decode($raw);
        if ($response === null)
            return FALSE;
        if ($response->success)
        {
            return $response->data->sid;
        }
        elseif ($response->error)
        {
            return FALSE;
        }
    }

    private static function _logout()
    {
        $query = http_build_query([
            'api' => 'SYNO.API.Auth',
            'version' => '1',
            'method' => 'logout',
            'session' => 'DownloadStation'
        ]);
        @file_get_contents(self::$schema.'://'.self::$torrentAddress.'/webapi/auth.cgi?'.$query);
    }

    private static function _list_downloads($sid)
    {
        $query = http_build_query([
            'api' => 'SYNO.DownloadStation.Task',
            'version' => '1',
            'method' => 'list',
            'additional' => 'detail',
            '_sid' => $sid
        ]);
        $raw = @file_get_contents(self::$schema.'://'.self::$torrentAddress.'/webapi/DownloadStation/task.cgi?'.$query);
        if ($raw === false) return null;
        return json_decode($raw);
    }

    private static function _download_already_exists($sid, $url)
    {
        $list = SynologyDS::_list_downloads($sid);
        if ($list === NULL)
            return NULL;

        foreach ($list->data->tasks as $task)
        {
            if ($task->additional->detail->uri == $url)
                return TRUE;
        }
        return FALSE;
    }

    private static function _find_id($sid)
    {
        $list = SynologyDS::_list_downloads($sid);
        if ($list === NULL)
            return NULL;

        $id = NULL;
        foreach ($list->data->tasks as $task)
        {
            $id = $task->id;
        }
        return $id;
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

        $pieces = explode(':', $torrentAddress);
        if ($pieces[1] == 5000)
            self::$schema = 'http';
        elseif ($pieces[1] == 5001)
            self::$schema = 'https';
        else
            self::$schema = 'http';

        self::$torrentAddress = $torrentAddress;
        self::$torrentLogin = $torrentLogin;
        self::$torrentPassword = $torrentPassword;
        self::$debug = $debug;

        $return = ['status' => FALSE, 'msg' => ''];

        $sid = SynologyDS::_login();
        if ($sid)
        {
    	    try
    	    {
                if ( ! empty($hash))
                {
                    if ($deleteDistribution)
                    {
                        $query = http_build_query([
                            'api' => 'SYNO.DownloadStation.Task',
                            'version' => '3',
                            'method' => 'delete',
                            'id' => $hash,
                            '_sid' => $sid
                        ]);
                        @file_get_contents(self::$schema.'://'.$torrentAddress.'/webapi/DownloadStation/task.cgi?'.$query);
                    }
                }

                if ( ! SynologyDS::_download_already_exists($sid, $file))
                {
                    $individualPath = Database::getTorrentDownloadPath($id);
                    if ( ! empty($individualPath))
                        $pathToDownload = $individualPath;

                    $data = array(
                            'api' => 'SYNO.DownloadStation.Task',
                            'version' => '3',
                            'method' => 'create',
                            'session' => 'DownloadStation',
                            'uri' => $file,
                            'destination' => $pathToDownload,
                            '_sid' => $sid
                        );
                    $data = http_build_query($data);

                    if ($debug)
                        $param = TRUE;
                    else
                        $param = FALSE;

                    $ch = curl_init();
                    curl_setopt_array($ch, array(
                        CURLOPT_POST => 1,
                        CURLOPT_FOLLOWLOCATION => 1,
                        CURLOPT_URL => self::$schema.'://'.$torrentAddress.'/webapi/DownloadStation/task.cgi',
                        CURLOPT_POSTFIELDS => $data,
                        CURLOPT_VERBOSE => $param,
                    ));
                    $response = curl_exec($ch);
                    curl_close($ch);
                    if (preg_match('/\"code\":403/i', $response))
                    {
                        $return['status'] = FALSE;
                        $return['msg'] = 'destination_does_not_exist';
                    }
                    else
                    {
                        $hashNew = SynologyDS::_find_id($sid);
                        if ($hashNew)
                        {
                            Database::updateHash($id, $hashNew);
                            Database::clearWarnings('SynologyDS');

                            $return['status'] = TRUE;
                            $return['hash'] = $hashNew;
                        }
                        else
                        {
                            $return['status'] = FALSE;
                            $return['msg'] = 'add_fail';
                        }
                        SynologyDS::_logout();
                    }
                }
                else
                {
                    $return['status'] = FALSE;
                    $return['msg'] = 'duplicate_torrent';
                }
            }
            catch (Exception $e)
            {
                $return['status'] = FALSE;
                $return['msg'] = $e->getMessage();
            }
        }
        else
        {
            $return['status'] = FALSE;
            $return['msg'] = 'log_passwd';
        }

        return $return;
    }

    #инициализация статических свойств из настроек БД
    private static function initSettings()
    {
        $settings = Database::getAllSetting();
        foreach ($settings as $row) { extract($row); }

        $pieces = explode(':', $torrentAddress);
        if (isset($pieces[1]) && $pieces[1] == 5001)
            self::$schema = 'https';
        else
            self::$schema = 'http';

        self::$torrentAddress = $torrentAddress;
        self::$torrentLogin = $torrentLogin;
        self::$torrentPassword = $torrentPassword;
    }

    #запрос списка задач с transfer-данными, возврат массива задач или null
    private static function fetchTasks($sid)
    {
        $query = http_build_query([
            'api' => 'SYNO.DownloadStation.Task',
            'version' => '1',
            'method' => 'list',
            'additional' => 'detail,transfer',
            '_sid' => $sid
        ]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::$schema.'://'.self::$torrentAddress.'/webapi/DownloadStation/task.cgi?'.$query,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $response = ($raw !== false) ? json_decode($raw, true) : null;

        if (!$response || !$response['success'] || empty($response['data']['tasks']))
            return null;

        return $response['data']['tasks'];
    }

    #форматирование статуса задачи в единый формат
    private static function formatTaskStatus($torrent)
    {
        $stateMap = [
            'downloading'         => 'downloading',
            'paused'              => 'paused',
            'finished'            => 'seeding',
            'seeding'             => 'seeding',
            'error'               => 'error',
            'waiting'             => 'queued',
            'finishing'           => 'downloading',
            'hash_checking'       => 'checking',
            'filehosting_waiting' => 'queued',
        ];

        $state = isset($torrent['status']) ? $torrent['status'] : '';
        $status = isset($stateMap[$state]) ? $stateMap[$state] : 'unknown';

        $size = intval($torrent['size']);
        $downloaded = isset($torrent['additional']['transfer']['size_downloaded'])
            ? intval($torrent['additional']['transfer']['size_downloaded']) : 0;
        $progress = ($size > 0) ? round($downloaded / $size * 100, 1) : 0;

        $speedBytes = isset($torrent['additional']['transfer']['speed_download'])
            ? intval($torrent['additional']['transfer']['speed_download']) : 0;
        $speed = ($speedBytes >= 1048576)
            ? round($speedBytes / 1048576, 1) . ' MB/s'
            : round($speedBytes / 1024, 1) . ' KB/s';

        return ['status' => $status, 'progress' => $progress, 'speed' => $speed];
    }

    #пакетный запрос статусов всех торрентов (одна авторизация, один запрос списка)
    public static function getStatusBatch($hashes)
    {
        try
        {
            self::initSettings();

            $sid = self::_login();
            if (!$sid)
                return [];

            $tasks = self::fetchTasks($sid);
            self::_logout();

            if ($tasks === null)
                return [];

            $hashSet = array_flip($hashes);
            $statuses = [];
            foreach ($tasks as $task)
            {
                if (isset($hashSet[$task['id']]))
                    $statuses[$task['id']] = self::formatTaskStatus($task);
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
            self::initSettings();

            $sid = self::_login();
            if (!$sid)
                return ['status' => 'unknown'];

            $tasks = self::fetchTasks($sid);
            self::_logout();

            if ($tasks === null)
                return ['status' => 'unknown'];

            foreach ($tasks as $task)
            {
                if ($task['id'] === $hash)
                    return self::formatTaskStatus($task);
            }

            return ['status' => 'unknown'];
        }
        catch (Exception $e)
        {
            return ['status' => 'unknown'];
        }
    }
}
?>