<?php
declare(strict_types=1);

namespace dev\winterframework\txn\stereotype;

use Attribute;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\txn\aop\TransactionalAspect;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\type\TypeAssert;
use TypeError;

#[Attribute(Attribute::TARGET_METHOD)]
class Transactional implements AopStereoType {
    private TransactionalAspect $aspect;
    private TransactionDefinition $txnDef;
    private string $name;

    public function __construct(
        public string $transactionManager = 'default',
        public int $propagation = 0,
        public int $isolation = -1,
        public int $timeout = -1,
        public bool $readOnly = false,
        public array $rollbackFor = [],
        public array $noRollbackFor = [],
        public array $label = []
    ) {
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->aspect)) {
            $this->aspect = new TransactionalAspect();
        }
        return $this->aspect;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);

        if ($ref->isConstructor() || $ref->isDestructor()) {
            throw new TypeError("#[Transactional] Annotation is not allowed on Constructor/Destructor "
                . ReflectionUtil::getFqName($ref));
        }

        $this->name = $ref->getName();
    }

    public function getTransactionDefinition(): TransactionDefinition {
        if (!isset($this->txnDef)) {
            $this->txnDef = new DefaultTransactionDefinition();
            $this->txnDef->setName($this->name);
            $this->txnDef->setIsolationLevel($this->isolation);
            $this->txnDef->setPropagationBehavior($this->propagation);
            $this->txnDef->setReadOnly($this->readOnly);
            $this->txnDef->setTimeout($this->timeout);
        }
        return $this->txnDef;
    }
}