<?php
declare(strict_types=1);

namespace dev\winterframework\core\data\provider;

use dev\winterframework\core\data\Options;

interface OptionsProvider {

    public function provide(): Options;

}