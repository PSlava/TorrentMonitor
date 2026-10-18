<?php
//Класс для параллельного выполнения HTTP-запросов через curl_multi с дедупликацией одинаковых запросов
class CurlMultiFetcher
{
    private $handles = array();
    private $aliases = array();
    private $requestMeta = array();
    private $defaultOptions = array();
    private $concurrencyLimit;
    private $cfFallback;

    public function __construct($cfFallback = true)
    {
        $this->cfFallback = $cfFallback;
        $this->defaultOptions = array(
            CURLOPT_USERAGENT         => Database::getSetting('userAgent'),
            CURLOPT_TIMEOUT           => Database::getSetting('httpTimeout'),
            CURLOPT_RETURNTRANSFER    => 1,
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
        );
        $limit = (int) Config::read('curl.concurrency');
        $this->concurrencyLimit = ($limit > 0) ? $limit : 10;
    }

    //регистрируем запрос; если такой же url+options уже зарегистрирован — добавляем $id как алиас существующего запроса
    public function add($id, $url, array $curlOptions = array())
    {
        $key = md5($url.serialize($curlOptions));

        if (isset($this->handles[$key]))
        {
            $this->aliases[$key][] = $id;
            return;
        }

        $options = $this->defaultOptions;
        $options[CURLOPT_URL] = $url;
        foreach ($curlOptions as $opt => $val)
            $options[$opt] = $val;

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $this->handles[$key] = $ch;
        $this->aliases[$key] = array($id);
        $this->requestMeta[$key] = array(
            'url'    => $url,
            'cookie' => isset($curlOptions[CURLOPT_COOKIE]) ? $curlOptions[CURLOPT_COOKIE] : '',
            //часть запросов собирается без getProxyOptions (торрент-клиент в локальной сети) -
            //их сбои нельзя приписывать прокси только потому, что он включён в настройках
            'proxy'  => ! empty($curlOptions[CURLOPT_PROXY]),
            //полный набор опций - для повтора запроса с Cloudflare-допуском
            'options' => $options,
        );
    }

    //выполняем запросы параллельно с лимитом одновременных соединений (скользящее окно)
    //возвращаем $id => ['body'=>..., 'http_code'=>..., 'error'=>...]
    //$onDone($done, $total, $ids, $waiting) вызывается после каждого завершённого запроса - для прогресса
    //в интерфейсе; $waiting = TRUE - перед долгим обходом Cloudflare по этому запросу (он ещё не завершён)
    public function execute($onDone = NULL)
    {
        $results = array();

        if (empty($this->handles))
            return $results;

        $total    = count($this->handles);
        $done     = 0;
        //ответы с Cloudflare-челленджем: обходим после опроса, чтобы долгий Byparr
        //не держал уже полученные ответы остальных трекеров
        $deferred = array();

        $queue    = $this->handles;  // ключ => ch, ещё не добавленные в multi
        $chToKey  = array();         // int(ch) => key, для обратного поиска по handle
        $mh       = curl_multi_init();

        // запускаем первую порцию до лимита
        $initial = array_splice($queue, 0, $this->concurrencyLimit);
        foreach ($initial as $key => $ch)
        {
            curl_multi_add_handle($mh, $ch);
            $chToKey[(int)$ch] = $key;
        }

        $running = null;
        while (!empty($chToKey))
        {
            do
            {
                $status = curl_multi_exec($mh, $running);
                if ($running)
                    curl_multi_select($mh);
            }
            while ($running && $status == CURLM_OK);

            //curl_multi сломался (не CURLM_OK) - завершённых дескрипторов больше не будет.
            //без этого внешний цикл крутился бы вхолостую без таймаута
            if ($status != CURLM_OK)
            {
                foreach ($chToKey as $deadKey)
                {
                    foreach ($this->aliases[$deadKey] as $id)
                    {
                        $results[$id] = array(
                            'body'          => '',
                            'http_code'     => 0,
                            'error'         => curl_multi_strerror($status),
                            'proxy_failure' => NULL,
                        );
                    }
                }
                break;
            }

            // обрабатываем все завершившиеся дескрипторы
            while (($info = curl_multi_info_read($mh)) !== false)
            {
                if ($info['msg'] !== CURLMSG_DONE)
                    continue;

                $ch  = $info['handle'];
                $key = $chToKey[(int)$ch];
                unset($chToKey[(int)$ch]);

                $body     = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                //curl_errno() на multi-хэндле не заполняется - код завершения берём из curl_multi_info_read()
                $errno    = $info['result'];
                //буфер ошибки на multi-хэндле, как правило, пуст - тогда берём текст по коду завершения
                $error    = curl_error($ch);
                if ($error === '' && $errno)
                    $error = curl_strerror($errno);

                $meta = $this->requestMeta[$key];

                //сбой прокси возвращает пустое тело - без явного предупреждения это выглядит как «трекер недоступен»
                $proxyFailure = Sys::detectProxyFailure($meta['url'], array(
                    'http_code'    => $httpCode,
                    'connect_code' => curl_getinfo($ch, CURLINFO_HTTP_CONNECTCODE),
                    'errno'        => $errno,
                ), $meta['proxy']);
                if ($proxyFailure !== NULL)
                    $error = Errors::getWarning($proxyFailure).($error !== '' ? ' ('.$error.')' : '');

                // rutracker.org исключён: у него своя explicit-обработка CF-challenge
                // в rutracker.org.engine.php::parse() (сохраняет cf_clearance/UA для dl.php).
                // Если фолбэк отработает здесь, parse() получит уже решённую (или пустую)
                // страницу и никогда не увидит CF-challenge — cf_cookies/cf_userAgent не сохранятся.
                $cfChallenge = $this->cfFallback && ($httpCode == 403 || $httpCode == 503) && !empty($body) && Sys::isCloudflarePage($body) && strpos($meta['url'], 'rutracker.org') === false;

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($cfChallenge)
                    $deferred[] = $key;
                else
                {
                    $this->setResult($results, $key, $body, $httpCode, $error, $proxyFailure);
                    $done++;
                    if ($onDone !== NULL)
                        call_user_func($onDone, $done, $total, $this->aliases[$key], FALSE);
                }

                // добавляем следующий из очереди, если есть
                if (!empty($queue))
                {
                    $nextKey = key($queue);
                    $nextCh  = array_shift($queue);
                    //регистрируем в карте только реально принятый дескриптор, иначе
                    //curl_multi_info_read() никогда его не вернёт и цикл не завершится
                    if (curl_multi_add_handle($mh, $nextCh) === 0)
                        $chToKey[(int)$nextCh] = $nextKey;
                    else
                    {
                        foreach ($this->aliases[$nextKey] as $id)
                        {
                            $results[$id] = array(
                                'body'          => '',
                                'http_code'     => 0,
                                'error'         => 'curl_multi_add_handle failed',
                                'proxy_failure' => NULL,
                            );
                        }
                        curl_close($nextCh);
                    }
                }
            }
        }

        curl_multi_close($mh);

        foreach ($deferred as $key)
        {
            $meta = $this->requestMeta[$key];
            if ($onDone !== NULL)
                call_user_func($onDone, $done, $total, $this->aliases[$key], TRUE);

            $page = $this->fetchThroughCloudflare($meta);
            if ($page !== NULL)
                $this->setResult($results, $key, $page['body'], $page['http_code'], '', NULL);
            else
                // Byparr не смог обойти CF — возвращаем пустое тело,
                // чтобы parse() не делал повторный вызов Byparr
                $this->setResult($results, $key, '', 0, 'cf_bypass_failed', NULL);

            $done++;
            if ($onDone !== NULL)
                call_user_func($onDone, $done, $total, $this->aliases[$key], FALSE);
        }

        $this->handles     = array();
        $this->aliases     = array();
        $this->requestMeta = array();

        return $results;
    }

    private function setResult(array &$results, $key, $body, $httpCode, $error, $proxyFailure)
    {
        foreach ($this->aliases[$key] as $id)
        {
            $results[$id] = array(
                'body'          => $body,
                'http_code'     => $httpCode,
                'error'         => $error,
                'proxy_failure' => $proxyFailure,
            );
        }
    }

    //обход Cloudflare: сначала обычный cURL с допуском, полученным один раз на хост
    //(CloudflareBypass), и лишь при неудаче - полный рендер страницы через Byparr
    private function fetchThroughCloudflare(array $meta)
    {
        CloudflareBypass::solve($meta['url'], $meta['cookie']);
        $cf = CloudflareBypass::get($meta['url']);
        if ($cf !== NULL)
        {
            $options = $meta['options'];
            $options[CURLOPT_COOKIE] = CloudflareBypass::cookieWith($meta['cookie'], $meta['url']);
            if ($cf['userAgent'] !== '')
                $options[CURLOPT_USERAGENT] = $cf['userAgent'];
            $ch = curl_init();
            curl_setopt_array($ch, $options);
            $body     = (string) curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode == 200 && $body !== '' && ! Sys::isCloudflarePage($body))
                return array('body' => $body, 'http_code' => $httpCode);
            CloudflareBypass::forget($meta['url']);
        }

        $fsResult = Sys::getViaFlareSolverr($meta['url'], $meta['cookie']);
        if ($fsResult === null)
            return NULL;
        CloudflareBypass::remember($meta['url'], $fsResult);
        return array('body' => $fsResult['body'], 'http_code' => $fsResult['status']);
    }
}
?>
