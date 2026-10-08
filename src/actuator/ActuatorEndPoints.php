<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

use dev\winterframework\type\Arrays;

class ActuatorEndPoints {
    private static array $endPoints = [
        'management.endpoint.beans' => [
            'enabled' => false,
            'path' => 'beans',
            'handler' => 'getBeans'
        ],
        // Displays Properties from application.yml
        'management.endpoint.configprops' => [
            'enabled' => false,
            'path' => 'configprops',
            'handler' => 'getConfigProps'
        ],
        'management.endpoint.env' => [
            'enabled' => false,
            'path' => 'env',
            'handler' => 'getEnv'
        ],
        'management.endpoint.health' => [
            'enabled' => false,
            'path' => 'health',
            'handler' => 'getHealth'
        ],
        'management.endpoint.info' => [
            'enabled' => false,
            'path' => 'info',
            'handler' => 'getInfo'
        ],
        // Displays whole list of all @RequestMapping paths.
        'management.endpoint.mappings' => [
            'enabled' => false,
            'path' => 'mappings',
            'handler' => 'getMappings'
        ],
        'management.endpoint.prometheus' => [
            'enabled' => false,
            'path' => 'prometheus',
            'handler' => 'getPrometheus'
        ],
        'management.endpoint.scheduledtasks' => [
            'enabled' => false,
            'path' => 'scheduledtasks',
            'handler' => 'getScheduledTasks'
        ],
        'management.endpoint.heapdump' => [
            'enabled' => false,
            'path' => 'heapdump',
            'handler' => 'getHeapDump'
        ],
    ];

    private const SENSITIVE_KEY = '/pass(word|wd)?|secret|token|credential|api[-_.]?key|private[-_.]?key|access[-_.]?key|auth|dsn|cookie|session/i';
    public const MASK = '******';

    /**
     * Replace values whose key looks secret (password, token, api key, ...)
     * at any depth, so configprops/env never print credentials.
     */
    public static function maskSensitive(array $values): array {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = self::maskSensitive($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function getFormattedEndPoints(): array {
        return Arrays::flattenByKey(self::$endPoints);
    }

    public static function getEndPoints(): array {
        return self::$endPoints;
    }

}