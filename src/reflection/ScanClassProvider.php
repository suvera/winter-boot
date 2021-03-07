<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

interface ScanClassProvider {

    public function provideClasses(): array;

}