<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\cli;

use Attribute;
use dev\winterframework\io\process\ServerWorkerProcess;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class DaemonThread implements StereoType {
    use StereoTypeValidations;

    public function __construct(
        public string $name = '',
        public int $coreSize = 1
    ) {
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        if (!$this->name) {
            $this->name = static::class;
        }

        TypeAssert::typeOf($ref, RefKlass::class);
        $this->cannotBeAbstractClass($ref, 'DaemonThread');
        $this->mustExtends($ref, 'DaemonThread', ServerWorkerProcess::class);
    }

}