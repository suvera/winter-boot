<?php
declare(strict_types=1);

namespace dev\winterframework\txn\aop;

use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\txn\stereotype\Transactional;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use Throwable;

class TransactionalAspect implements WinterAspect {
    const OPERATION = 'Transactional';

    private function getTransactionManager(
        AopContext $ctx
    ): PlatformTransactionManager {
        /** @var Transactional $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();
        $transactionManager = empty($stereo->transactionManager) ?
            $appCtx->beanByClass(PlatformTransactionManager::class)
            : $appCtx->beanByName($stereo->transactionManager);
        TypeAssert::typeOf($transactionManager, PlatformTransactionManager::class);

        return $transactionManager;
    }

    public function begin(
        AopContext $ctx,
        object $target,
        array $args
    ): void {
        /** @var Transactional $stereo */
        $stereo = $ctx->getStereoType();

        $txnMgr = $this->getTransactionManager($ctx);
        $txnStatus = $txnMgr->getTransaction($stereo->getTransactionDefinition());
        $ctx->setCtxData($target, self::OPERATION, $txnStatus);
    }

    public function beginFailed(
        AopContext $ctx,
        object $target,
        array $args,
        Throwable $ex
    ): void {
        /** @var TransactionStatus $txnStatus */
        $txnStatus = $ctx->getCtxData($target, self::OPERATION);
        if (empty($txnStatus)) {
            return;
        }
        $txnMgr = $this->getTransactionManager($ctx);
        $txnMgr->rollback($txnStatus);
    }

    public function commit(
        AopContext $ctx,
        object $target,
        array $args,
        mixed $result
    ): void {
        /** @var TransactionStatus $txnStatus */
        $txnStatus = $ctx->getCtxData($target, self::OPERATION);
        $txnMgr = $this->getTransactionManager($ctx);

        if ($txnStatus->isRollbackOnly()) {
            $txnMgr->rollback($txnStatus);
        } else {
            $txnMgr->commit($txnStatus);
        }
    }

    public function commitFailed(
        AopContext $ctx,
        object $target,
        array $args,
        mixed $result,
        Throwable $ex
    ): void {
        /** @var TransactionStatus $txnStatus */
        $txnStatus = $ctx->getCtxData($target, self::OPERATION);
        $txnMgr = $this->getTransactionManager($ctx);

        $txnMgr->rollback($txnStatus);
    }

    public function failed(
        AopContext $ctx,
        object $target,
        array $args,
        Throwable $ex
    ): void {
        /** @var TransactionStatus $txnStatus */
        $txnStatus = $ctx->getCtxData($target, self::OPERATION);
        if (empty($txnStatus)) {
            return;
        }
        $txnMgr = $this->getTransactionManager($ctx);

        $txnMgr->rollback($txnStatus);
    }

}