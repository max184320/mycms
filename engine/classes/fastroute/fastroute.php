<?php

namespace FastRoute;

class Dispatcher
{
    public const NOT_FOUND = 0;
    public const FOUND = 1;

    private const VARIABLE_REGEX = <<<'REGEX'
\{
    \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;
    private const DEFAULT_DISPATCH_REGEX = '[^/]+';
    private const APPROX_CHUNK_SIZE = 10;

    private $buildStaticRoutes = [];
    private $buildRegexRoutes = [];
    private $staticRouteMap = [];
    private $variableRouteData = [];

    public static function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])
    {
        $routeCollector = new self();
        $routeDefinitionCallback($routeCollector);

        return $routeCollector->finalize();
    }

    public static function cachedDispatcher(callable $routeDefinitionCallback, array $options = [])
    {
        $options += ['cacheDisabled' => false];

        if (!isset($options['cacheFile'])) {
            throw new \LogicException('Must specify "cacheFile" option');
        }

        if (!$options['cacheDisabled'] && file_exists($options['cacheFile'])) {
            $dispatchData = require $options['cacheFile'];

            if (!is_array($dispatchData)) {
                throw new \RuntimeException('Invalid cache file "' . $options['cacheFile'] . '"');
            }

            return (new self())->setDispatchData($dispatchData);
        }

        $routeCollector = new self();
        $routeDefinitionCallback($routeCollector);
        $dispatchData = $routeCollector->buildData();

        if (!$options['cacheDisabled']) {
            file_put_contents(
                $options['cacheFile'],
                '<?php return ' . var_export($dispatchData, true) . ';'
            );
            @chmod($options['cacheFile'], 0666);
        }

        return $routeCollector->setDispatchData($dispatchData);
    }

    public function addRoute($httpMethod, $route, $handler)
    {
        $routeDatas = self::parse($route);

        foreach ((array) $httpMethod as $method) {
            foreach ($routeDatas as $routeData) {
                $this->addParsedRoute($method, $routeData, $handler);
            }
        }
    }

    public function dispatch($httpMethod, $uri)
    {
        if (isset($this->staticRouteMap[$httpMethod][$uri])) {
            return [self::FOUND, $this->staticRouteMap[$httpMethod][$uri], []];
        }

        if (isset($this->variableRouteData[$httpMethod])) {
            return $this->dispatchVariableRoute($this->variableRouteData[$httpMethod], $uri);
        }

        return [self::NOT_FOUND];
    }

    private function finalize()
    {
        return $this->setDispatchData($this->buildData());
    }

    private function setDispatchData(array $data)
    {
        $this->buildStaticRoutes = [];
        $this->buildRegexRoutes = [];
        $this->staticRouteMap = $data[0] ?? [];
        $this->variableRouteData = $data[1] ?? [];

        return $this;
    }

    private function addParsedRoute($httpMethod, array $routeData, $handler)
    {
        if ($this->isStaticRoute($routeData)) {
            $this->addStaticRoute($httpMethod, $routeData, $handler);
            return;
        }

        $this->addVariableRoute($httpMethod, $routeData, $handler);
    }

    private function isStaticRoute(array $routeData)
    {
        return count($routeData) === 1 && is_string($routeData[0]);
    }

    private function addStaticRoute($httpMethod, array $routeData, $handler)
    {
        $routeStr = $routeData[0];

        if (isset($this->buildStaticRoutes[$httpMethod][$routeStr])) {
            throw new \LogicException(sprintf(
                'Cannot register two routes matching "%s" for method "%s"',
                $routeStr,
                $httpMethod
            ));
        }

        if (isset($this->buildRegexRoutes[$httpMethod])) {
            foreach ($this->buildRegexRoutes[$httpMethod] as $regex => $route) {
                if (preg_match('~^' . $regex . '$~', $routeStr)) {
                    throw new \LogicException(sprintf(
                        'Static route "%s" is shadowed by previously defined variable route "%s" for method "%s"',
                        $routeStr,
                        $regex,
                        $httpMethod
                    ));
                }
            }
        }

        $this->buildStaticRoutes[$httpMethod][$routeStr] = $handler;
    }

    private function addVariableRoute($httpMethod, array $routeData, $handler)
    {
        [$regex, $variables] = $this->buildRegexForRoute($routeData);

        if (isset($this->buildRegexRoutes[$httpMethod][$regex])) {
            throw new \LogicException(sprintf(
                'Cannot register two routes matching "%s" for method "%s"',
                $regex,
                $httpMethod
            ));
        }

        $this->buildRegexRoutes[$httpMethod][$regex] = [
            'handler' => $handler,
            'variables' => $variables,
        ];
    }

    private function buildRegexForRoute(array $routeData)
    {
        $regex = '';
        $variables = [];

        foreach ($routeData as $part) {
            if (is_string($part)) {
                $regex .= preg_quote($part, '~');
                continue;
            }

            [$varName, $regexPart] = $part;

            if (isset($variables[$varName])) {
                throw new \LogicException(sprintf(
                    'Cannot use the same placeholder "%s" twice',
                    $varName
                ));
            }

            if ($this->regexHasCapturingGroups($regexPart)) {
                throw new \LogicException(sprintf(
                    'Regex "%s" for parameter "%s" contains a capturing group',
                    $regexPart,
                    $varName
                ));
            }

            $variables[$varName] = $varName;
            $regex .= '(' . $regexPart . ')';
        }

        return [$regex, $variables];
    }

    private function regexHasCapturingGroups($regex)
    {
        if (false === strpos($regex, '(')) {
            return false;
        }

        return (bool) preg_match(
            '~
                (?:
                    \(\?\(
                  | \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
                  | \\\\ .
                ) (*SKIP)(*FAIL) |
                \(
                (?!
                    \? (?! <(?![!=]) | P< | \' )
                  | \*
                )
            ~x',
            $regex
        );
    }

    private function buildData()
    {
        if (empty($this->buildRegexRoutes)) {
            return [$this->buildStaticRoutes, []];
        }

        $data = [];

        foreach ($this->buildRegexRoutes as $method => $regexToRoutesMap) {
            $chunkSize = $this->computeChunkSize(count($regexToRoutesMap));
            $chunks = array_chunk($regexToRoutesMap, $chunkSize, true);
            $data[$method] = array_map([$this, 'processChunk'], $chunks);
        }

        return [$this->buildStaticRoutes, $data];
    }

    private function computeChunkSize($count)
    {
        $numParts = max(1, round($count / self::APPROX_CHUNK_SIZE));

        return (int) ceil($count / $numParts);
    }

    private function processChunk(array $regexToRoutesMap)
    {
        $routeMap = [];
        $regexes = [];
        $numGroups = 0;

        foreach ($regexToRoutesMap as $regex => $route) {
            $numVariables = count($route['variables']);
            $numGroups = max($numGroups, $numVariables);

            $regexes[] = $regex . str_repeat('()', $numGroups - $numVariables);
            $routeMap[$numGroups + 1] = [$route['handler'], $route['variables']];

            ++$numGroups;
        }

        return [
            'regex' => '~^(?|' . implode('|', $regexes) . ')$~',
            'routeMap' => $routeMap,
        ];
    }

    private function dispatchVariableRoute(array $routeData, $uri)
    {
        foreach ($routeData as $data) {
            if (!preg_match($data['regex'], $uri, $matches)) {
                continue;
            }

            [$handler, $varNames] = $data['routeMap'][count($matches)];
            $vars = [];
            $i = 0;

            foreach ($varNames as $varName) {
                $vars[$varName] = $matches[++$i];
            }

            return [self::FOUND, $handler, $vars];
        }

        return [self::NOT_FOUND];
    }

    private static function parse($route)
    {
        $routeWithoutClosingOptionals = rtrim($route, ']');
        $numOptionals = strlen($route) - strlen($routeWithoutClosingOptionals);
        $segments = preg_split('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \[~x', $routeWithoutClosingOptionals);

        if ($numOptionals !== count($segments) - 1) {
            if (preg_match('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \]~x', $routeWithoutClosingOptionals)) {
                throw new \LogicException('Optional segments can only occur at the end of a route');
            }

            throw new \LogicException("Number of opening '[' and closing ']' does not match");
        }

        $currentRoute = '';
        $routeDatas = [];

        foreach ($segments as $n => $segment) {
            if ($segment === '' && $n !== 0) {
                throw new \LogicException('Empty optional part');
            }

            $currentRoute .= $segment;
            $routeDatas[] = self::parsePlaceholders($currentRoute);
        }

        return $routeDatas;
    }

    private static function parsePlaceholders($route)
    {
        if (!preg_match_all(
            '~' . self::VARIABLE_REGEX . '~x',
            $route,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            return [$route];
        }

        $offset = 0;
        $routeData = [];

        foreach ($matches as $set) {
            if ($set[0][1] > $offset) {
                $routeData[] = substr($route, $offset, $set[0][1] - $offset);
            }

            $routeData[] = [
                $set[1][0],
                isset($set[2]) ? trim($set[2][0]) : self::DEFAULT_DISPATCH_REGEX,
            ];
            $offset = $set[0][1] + strlen($set[0][0]);
        }

        if ($offset !== strlen($route)) {
            $routeData[] = substr($route, $offset);
        }

        return $routeData;
    }
}
