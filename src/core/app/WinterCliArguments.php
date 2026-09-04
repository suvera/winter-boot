<?php

declare(strict_types=1);

namespace dev\winterframework\core\app;

use Garden\Cli\Args;
use Garden\Cli\Cli;

class WinterCliArguments {
    protected Args $args;
    protected Cli $cli;

    /**
     * @throws
     */
    public function __construct() {
        $this->cli = new Cli();

        $this->cli->description('Service arguments')
            ->opt('configDir:c', 'Config directory.', false)
            ->opt('stub:s', 'Stub name to execute', false)
            ->opt('sqlPath:s', 'SQL migration path.', false)
            ->opt('migrationType:m', 'Type of migration to execute (sql|opensearch). Defaults to sql.', false)
            ->opt('version:v', 'Show Version', false, 'boolean');

        $this->args = $this->cli->parse($_SERVER['argv'], true);
    }

    public function writeHelp(): void {
        $this->cli->writeHelp();
    }

    public function printError(string $text): void {
        echo $this->cli->red($text . PHP_EOL);
    }

    public function description(?string $str = null): void {
        $this->cli->meta("description", $str);
    }

    public function get(string $name, mixed $default = null) {
        return $this->args->getOpt($name, $default);
    }

    public function has(string $name): bool {
        return $this->args->hasOpt($name);
    }

    public function getConfigDir(): ?string {
        return $this->get('configDir', null);
    }

    public function getStub(): ?string {
        return $this->get('stub', null);
    }

    public function getSqlPath(): ?string {
        return $this->get('sqlPath', null);
    }

    public function getMigrationType(): ?string {
        return $this->get('migrationType', null);
    }

    public function getVersion(): ?bool {
        return $this->get('version', null);
    }
}
