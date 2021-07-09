<?php
/** @noinspection SpellCheckingInspection */
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);

use Phing\Io\File;

class RpmScriptlet {

    /**
     * preinstall, postinstall, preremove, postremove
     */
    protected string $onEvent;
    protected File $file;

    public function getOnEvent(): string {
        return $this->onEvent;
    }

    public function setOnEvent(string $onEvent): void {
        $this->onEvent = strtolower($onEvent);
    }

    public function getFile(): File {
        return $this->file;
    }

    public function setFile(File $file): void {
        $this->file = $file;
    }

}