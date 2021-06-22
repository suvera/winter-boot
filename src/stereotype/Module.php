<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;

#[Attribute(Attribute::TARGET_CLASS)]
class Module implements StereoType {
    use StereoTypeValidations;

    private string $className;
    private array $config = [];
    public string $name = '';

    public function __construct(
        public string $title = '',
        public string $initMethod = 'init',
        public string $destroyMethod = '',
        public array $namespaces = [],
    ) {
    }

    public function getClassName(): string {
        return $this->className;
    }

    public function getConfig(): array {
        return $this->config;
    }

    public function setConfig(array $config): void {
        $this->config = $config;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);

        $this->cannotBeAbstractClass($ref, 'Module');

        if (!$this->title) {
            $this->title = $ref->getShortName();
        }

        $this->className = $ref->getName();

        if (empty($this->namespaces)) {
            $this->namespaces[] = [$ref->getNamespaceName(), dirname($ref->getFileName())];
        }
    }

}