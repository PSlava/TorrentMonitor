<?php
class rTorrent
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

        #формируем URL для XML-RPC
        $rpcUrl = self::buildRpcUrl($torrentAddress);

        if ( ! empty($hash))
        {
            if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' || $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
            {
                #удяляем существующую закачку из torrent-клиента
                if ($deleteDistribution)
                {
                    $result = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.erase', array($hash));
                }
            }
            else
            {
                #удяляем существующую закачку из torrent-клиента
                $result = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.erase', array($hash));
            }
        }

        #добавляем торрент в torrent-клиент с указанием пути загрузки
        $result = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'load.start', array(
            '',
            $file,
            'd.directory.set=' . $pathToDownload
        ));

        if ($result === false)
        {
            $return['status'] = FALSE;
            $return['msg'] = 'add_fail';
            return $return;
        }

        #даём rTorrent время на обработку торрента
        sleep(3);

        #получаем список раздач и ищем по URL добавленного торрента
        $listResult = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'download_list', array(''));

        if ($listResult === false || empty($listResult))
        {
            $return['status'] = FALSE;
            $return['msg'] = 'add_fail';
            return $return;
        }

        #ищем хеш добавленного торрента, проверяя каждый элемент списка
        $hashNew = null;
        foreach (array_reverse($listResult) as $candidateHash)
        {
            $torrentName = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.name', array($candidateHash));
            if ($torrentName !== false)
            {
                $hashNew = $candidateHash;
                break;
            }
        }

        if (empty($hashNew))
        {
            $return['status'] = FALSE;
            $return['msg'] = 'add_fail';
            return $return;
        }

        #обновляем hash в базе
        Database::updateHash($id, $hashNew);

        #сбрасываем варнинг
        Database::clearWarnings('rTorrent');
        $return['status'] = TRUE;
        $return['hash'] = $hashNew;

        return $return;
    }

    #формируем URL для XML-RPC endpoint
    private static function buildRpcUrl($address)
    {
        #добавляем http:// если протокол не указан
        if ( ! preg_match('/^https?:\/\//', $address))
            $address = 'http://' . $address;

        #добавляем /RPC2 если путь не указан
        $parsed = parse_url($address);
        if (empty($parsed['path']) || $parsed['path'] === '/')
            $address = rtrim($address, '/') . '/RPC2';

        return $address;
    }

    #выполняем XML-RPC запрос к rTorrent
    private static function xmlRpcCall($url, $login, $password, $method, $params = array())
    {
        #формируем XML-тело запроса
        $xml = '<?xml version="1.0"?>' . "\n";
        $xml .= '<methodCall>' . "\n";
        $xml .= '  <methodName>' . htmlspecialchars($method) . '</methodName>' . "\n";
        $xml .= '  <params>' . "\n";
        foreach ($params as $param)
        {
            $xml .= '    <param><value><string>' . htmlspecialchars($param) . '</string></value></param>' . "\n";
        }
        $xml .= '  </params>' . "\n";
        $xml .= '</methodCall>';

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: text/xml',
                'Content-Length: ' . strlen($xml)
            ),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ));

        #авторизация если указаны логин и пароль
        if ( ! empty($login))
        {
            curl_setopt($ch, CURLOPT_USERPWD, $login . ':' . $password);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode != 200)
        {
            return false;
        }

        #парсим XML-RPC ответ
        return self::parseXmlRpcResponse($response);
    }

    #парсим ответ XML-RPC
    private static function parseXmlRpcResponse($xmlString)
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false)
            return false;

        #проверяем наличие ошибки (fault)
        if (isset($xml->fault))
            return false;

        #извлекаем значения из ответа
        if (isset($xml->params->param->value))
            return self::extractValue($xml->params->param->value);

        return true;
    }

    #извлекаем значение из XML-RPC value-узла
    private static function extractValue($valueNode)
    {
        if (isset($valueNode->string))
            return (string) $valueNode->string;

        if (isset($valueNode->int) || isset($valueNode->i4))
            return (int) ($valueNode->int ?? $valueNode->i4);

        if (isset($valueNode->boolean))
            return (bool) (int) $valueNode->boolean;

        if (isset($valueNode->array))
        {
            $result = array();
            if (isset($valueNode->array->data->value))
            {
                foreach ($valueNode->array->data->value as $val)
                {
                    $result[] = self::extractValue($val);
                }
            }
            return $result;
        }

        #если тип не определён, возвращаем текстовое содержимое
        return (string) $valueNode;
    }

    #получаем статус закачки из торрент-клиента
    public static function getStatus($hash)
    {
        try
        {
            #получаем настройки из базы
            $settings = Database::getAllSetting();
            foreach ($settings as $row) { extract($row); }

            $rpcUrl = self::buildRpcUrl($torrentAddress);

            #запрашиваем параметры торрента
            $state     = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.state', array($hash));
            $complete  = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.complete', array($hash));
            $downRate  = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.down.rate', array($hash));
            $bytesDone = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.bytes_done', array($hash));
            $sizeBytes = self::xmlRpcCall($rpcUrl, $torrentLogin, $torrentPassword, 'd.size_bytes', array($hash));

            if ($state === false || $complete === false)
                return ['status' => 'unknown'];

            #определяем статус: d.state 0=stopped, 1=started; d.complete 0=downloading, 1=complete
            if ($state == 0)
                $status = 'stopped';
            elseif ($complete == 1)
                $status = 'seeding';
            else
                $status = 'downloading';

            #прогресс
            $progress = ($sizeBytes > 0) ? round($bytesDone / $sizeBytes * 100, 1) : 0;

            #скорость загрузки
            $speedBytes = intval($downRate);
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
