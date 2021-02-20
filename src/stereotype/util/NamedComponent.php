<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\util;

trait NamedComponent {
    protected ComponentName $nameObject;

    protected function initNameObject(string $name): void {
        $this->nameObject = new ComponentName($name);
    }

    public function getNameObject(): ComponentName {
        return $this->nameObject;
    }
}