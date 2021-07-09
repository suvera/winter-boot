<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use Garden\Cli\Args;
use Garden\Cli\Cli;

class WinterCliArguments {
    protected Args $args;

    /**
     * @throws
     */
    public function __construct() {
        $cli = new Cli();

        $cli->description('Service arguments')
            ->opt('configDir:c', 'Config directory.', false)
            ->opt('stub:s', 'Stub name to execute', false);

        $this->args = $cli->parse($_SERVER['argv'], true);
    }

    public function get(string $name, mixed $default = null) {
        return $this->args->getOpt($name, $default);
    }

    public function has(string $name): bool {
        return $this->args->hasOpt($name);
    }
}