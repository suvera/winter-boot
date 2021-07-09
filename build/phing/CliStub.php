<?php
declare(strict_types=1);

use Phing\Io\File;

class CliStub {
    protected string $name;
    protected File $scriptPath;

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getScriptPath(): File {
        return $this->scriptPath;
    }

    public function setScriptPath(File $scriptPath): void {
        $this->scriptPath = $scriptPath;
    }

}