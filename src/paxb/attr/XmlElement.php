<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use Attribute;
use dev\winterframework\paxb\XmlStereoTypeTrait;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ScanClassProvider;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class XmlElement implements XmlStereoType, ScanClassProvider {
    use XmlStereoTypeTrait;
    use StereoTypeValidations;

    private RefProperty $refOwner;

    protected ParameterType $elementType;

    public function __construct(
        protected string $name = '',
        protected bool $nillable = false,
        protected bool $required = false,
        protected string $namespace = '',
        protected mixed $defaultValue = null,
        protected bool $list = false,
        protected string $listClass = '',
        protected string $valueAdapter = '',
        protected array $filters = []
    ) {
        if (!empty($this->listClass)) {
            $this->list = true;
            $listCls = RefKlass::getInstance($this->listClass);
            if (!$listCls->isInstantiable()) {
                throw new TypeError('#[XmlElement] attribute "listClass" must be Instantiable, '
                    . 'interface/abstract class given "' . $this->listClass . '"');
            }
        } else if ($this->list) {
            throw new TypeError('#[XmlElement] attribute must have "listClass" defined where "list=true" set');
        }

        if ($this->valueAdapter) {
            $this->validateValueAdapter($this->valueAdapter, 'XmlElement');
        }
    }

    public function getFilters(): array {
        return $this->filters;
    }

    public function getValueAdapter(): string {
        return $this->valueAdapter;
    }

    public function getName(): string {
        return $this->name;
    }

    public function isNillable(): bool {
        return $this->nillable;
    }

    public function isRequired(): bool {
        return $this->required;
    }

    public function getNamespace(): string {
        return $this->namespace;
    }

    public function getElementType(): ParameterType {
        return $this->elementType;
    }

    public function hasWrapper(): bool {
        return isset($this->wrapper);
    }

    public function isList(): bool {
        return $this->list;
    }

    public function getListClass(): string {
        return $this->listClass;
    }

    public function provideClasses(): array {
        return empty($this->listClass) ? [] : [$this->listClass];
    }

    public function getDefaultValue(): mixed {
        return $this->defaultValue;
    }

    public function getRefOwner(): RefProperty {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefProperty $ref */
        TypeAssert::typeOf($ref, RefProperty::class);

        $this->cannotBeStaticProperty($ref, 'XmlElement');
        if ($this->list) {
            $this->assertParameterIsArray($ref->getDelegate(), 'XmlElement');
        }
        $this->elementType = ParameterType::fromType($ref->getType());

        $this->cannotBeCombinedWith(
            $ref,
            'XmlElement',
            'Xml',
            [XmlStereoType::class]
        );

        $this->refOwner = $ref;

        if ($this->name == '') {
            $this->name = $this->findName($ref);
        }

        $type = ParameterType::fromType($ref->getType());
        if ($type->allowsNull()) {
            $this->nillable = true;
        }
        if (!$ref->hasDefaultValue() && !$type->allowsNull()) {
            $this->required = true;
        }
    }

}