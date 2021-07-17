<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\route;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\web\MatchedRequestMapping;
use dev\winterframework\exception\DuplicatePathException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\util\UriPath;
use dev\winterframework\stereotype\util\UriPathPart;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\util\log\Wlf4p;

final class WinterRequestMappingRegistry implements RequestMappingRegistry {
    use Wlf4p;

    /**
     * @var RequestMapping[]
     */
    private static array $byId = [];

    /**
     * @var RequestMapping[][]
     */
    private static array $byUriMethod = [];

    /**
     * @var RequestMapping[][]
     */
    private static array $byRegex = [];

    /**
     * @var array
     */
    private static array $fullTextIndex = [];

    /**
     * @var array
     */
    private static array $cachedPaths = [];

    public function __construct(
        private ApplicationContextData $ctxData,
        private ApplicationContext $appCtx
    ) {
        if (empty(self::$byId)) {
            $this->processResources();
        }
    }

    private function processResources(): void {
        foreach ($this->ctxData->getResources() as $class) {

            /** @var ClassResource $class */
            if ($class->getAttribute(RestController::class) === null) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                /** @var MethodResource $method */
                $this->processMethodAttribute($class, $method);
            }
        }
    }

    /** @noinspection PhpUnusedParameterInspection */
    private function processMethodAttribute(
        ClassResource $class,
        MethodResource $method
    ): void {

        $attributes = $method->getAttributes();
        foreach ($attributes as $attribute) {
            if ($attribute instanceof RequestMapping) {
                $this->put($attribute);
            }
        }
    }

    public function put(RequestMapping $mapping) {
        if ($mapping->isRestController()) {
            return;
        }

        $id = $mapping->getId();
        if (isset(self::$byId[$id])) {
            // already scanned, so skip it
            return;
        }
        self::$byId[$id] = $mapping;

        foreach ($mapping->getUriPaths() as $uriPath) {
            $normalized = $uriPath->getNormalized();
            $regex = $uriPath->getRegex();
            $fullRegex = '/^' . $regex . '$/';

            foreach ($mapping->method as $method) {
                if (isset(self::$byUriMethod[$normalized][$method])) {
                    throw new DuplicatePathException("Duplicate Path '$normalized' for '$method' detected at "
                        . ReflectionUtil::getFqName($mapping->getRefOwner())
                        . ', similar path already defined by '
                        . ReflectionUtil::getFqName(self::$byUriMethod[$normalized][$method]->getRefOwner())
                    );
                }

                if (isset(self::$byRegex[$fullRegex][$method])) {
                    throw new DuplicatePathException("Duplicate Path '$normalized' detected at "
                        . ReflectionUtil::getFqName($mapping->getRefOwner())
                        . ', similar path already defined by '
                        . ReflectionUtil::getFqName(self::$byRegex[$fullRegex][$method]->getRefOwner())
                    );
                } else {
                    foreach (self::$byRegex as $otherRegex => $otherMappings) {
                        if (isset($otherMappings[$method])) {
                            if (preg_match($otherRegex, $normalized)) {
                                throw new DuplicatePathException("Duplicate Path '$normalized' detected at "
                                    . ReflectionUtil::getFqName($mapping->getRefOwner())
                                    . ', similar path already defined by '
                                    . ReflectionUtil::getFqName($otherMappings[$method]->getRefOwner())
                                );
                            }

                            $otherMapping = $otherMappings[$method];
                            foreach ($otherMapping->getUriPaths() as $uriPath2) {
                                $otherNormalized = $uriPath2->getNormalized();
                                if (preg_match($fullRegex, $otherNormalized)) {
                                    throw new DuplicatePathException("Duplicate Path '$normalized' "
                                        . 'detected at '
                                        . ReflectionUtil::getFqName($mapping->getRefOwner())
                                        . ', similar path already defined by '
                                        . ReflectionUtil::getFqName($otherMappings[$method]->getRefOwner())
                                    );
                                }
                            }
                        }
                    }
                }

                self::$byRegex[$fullRegex][$method] = $mapping;
                self::$byUriMethod[$normalized][$method] = $mapping;
                self::logInfo('Route [' . $method . '] "' . $normalized . '" registered.');
            }
            $this->addToFullTextIndex($uriPath, $mapping, $fullRegex);
        }
    }

    private function addToFullTextIndex(
        UriPath $uriPath,
        RequestMapping $mapping,
        string $fullRegex
    ): void {
        foreach ($mapping->method as $method) {
            if (!isset(self::$fullTextIndex[$method])) {
                self::$fullTextIndex[$method] = [];
            }
            $hasParts = false;
            $ref = &self::$fullTextIndex[$method];

            //echo "FullRegex: $fullRegex \n";
            foreach ($uriPath->getArray() as $pathPart) {
                /** @var $pathPart UriPathPart */
                if ($pathPart->isPathVariable()) {
                    $part = '/^' . $pathPart->getRegex() . '$/';
                } else {
                    $part = $pathPart->getPart();
                }

                if (!isset($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
                $hasParts = true;
            }

            if (!$hasParts) {
                $ref[''] = [
                    '<mapping>' => [$mapping, $fullRegex]
                ];
            } else {
                $ref['<mapping>'] = [$mapping, $fullRegex];
            }
        }
    }

    public function find(string $path, string $method): ?MatchedRequestMapping {
        self::logInfo("Finding route for '$path', '$method' ");
        $path = trim($path, '/');
        $method = strtoupper($method);

        if (isset(self::$cachedPaths[$path][$method])) {
            $m = self::$cachedPaths[$path][$method];
            return new MatchedRequestMapping($m['obj'], $m['matches']);
        }

        if (!isset(self::$fullTextIndex[$method])) {
            return null;
        }

        $path = preg_replace('/\/+/', '/', $path);
        $pathParts = explode('/', $path);
        $textIndex = self::$fullTextIndex[$method];
        $matches = [];

        foreach ($pathParts as $part) {
            if (isset($textIndex[$part])) {
                $textIndex = $textIndex[$part];
            } else {
                $found = false;
                foreach ($textIndex as $regex => $others) {
                    $newMatches = [];
                    if (strlen($regex) > 0
                        && $regex[0] === '/'
                        && preg_match($regex, $part, $newMatches)
                    ) {
                        $textIndex = $others;
                        $matches = array_merge($matches, $newMatches);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return null;
                }
            }
        }

        if (isset($textIndex['<mapping>'])) {
            self::$cachedPaths[$path][$method] = [
                'obj' => $textIndex['<mapping>'][0],
                'regex' => $textIndex['<mapping>'][1],
                'matches' => $matches
            ];

            return new MatchedRequestMapping(self::$cachedPaths[$path][$method]['obj'], $matches);
        }

        return null;
    }

    public function delete(string $path): void {
        $path = trim($path, '/');

        if (isset(self::$cachedPaths[$path])) {
            foreach (self::$cachedPaths[$path] as $def) {
                unset(self::$byRegex[$def['regex']]);
            }

            unset(self::$cachedPaths[$path]);
            return;
        }

        foreach (self::$byRegex as $regex => $mapping) {
            if (preg_match($regex, $path)) {
                unset(self::$byRegex[$regex]);
                break;
            }
        }
    }

    public function getAll(): array {
        return self::$byId;
    }


}