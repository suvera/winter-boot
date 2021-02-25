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
    ];

    public static function getFormattedEndPoints(): array {
        return Arrays::flattenByKey(self::$endPoints);
    }

    public static function getEndPoints(): array {
        return self::$endPoints;
    }

}