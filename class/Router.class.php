<?php
class Router
{
    private static $routes = [];

    public static function get($path, $handler)
    {
        self::$routes[] = ['method' => 'GET', 'path' => $path, 'handler' => $handler];
    }

    public static function post($path, $handler)
    {
        self::$routes[] = ['method' => 'POST', 'path' => $path, 'handler' => $handler];
    }

    public static function put($path, $handler)
    {
        self::$routes[] = ['method' => 'PUT', 'path' => $path, 'handler' => $handler];
    }

    public static function delete($path, $handler)
    {
        self::$routes[] = ['method' => 'DELETE', 'path' => $path, 'handler' => $handler];
    }

    public static function dispatch($method, $uri)
    {
        // Убираем query string
        $uri = strtok($uri, '?');
        // Убираем базовый путь к api.php
        $uri = preg_replace('#^.*/api\.php#', '', $uri);
        if (empty($uri))
            $uri = '/';

        foreach (self::$routes as $route)
        {
            if ($route['method'] !== $method)
                continue;

            $pattern = self::pathToRegex($route['path']);
            if (preg_match($pattern, $uri, $matches))
            {
                // Извлекаем именованные параметры
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return call_user_func($route['handler'], $params);
            }
        }

        self::json(['error' => 'Маршрут не найден'], 404);
    }

    private static function pathToRegex($path)
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    public static function json($data, $code = 200)
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public static function getInput()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        return is_array($data) ? $data : [];
    }
}
?>
