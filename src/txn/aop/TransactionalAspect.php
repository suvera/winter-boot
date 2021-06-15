<?php
declare(strict_types=1);

namespace dev\winterframework\txn\aop;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\txn\stereotype\Transactional;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\ExceptionUtils;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class TransactionalAspect implements WinterAspect {
    use Wlf4p;

    const OPERATION = 'Transactional';

    private function getTransactionManager(
        AopContext $ctx
    ): PlatformTransactionManager {
        /** @var Transactional $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();
        $transactionManager = (empty($stereo->transactionManager) || $stereo->transactionManager == 'default') ?
            $appCtx->beanByClass(PlatformTransactionManager::class)
            : $appCtx->beanByName($stereo->transactionManager);
        TypeAssert::typeOf($transactionManager, PlatformTransactionManager::class);

        return $transactionManager;
    }

    public function begin(
        AopContext $ctx,
        AopExecutionContext $exCtx,
    ): void {
        /** @var Transactional $stereo */
        $stereo = $ctx->getStereoType();

        self::logInfo('Transaction started on method ' . ReflectionUtil::getFqName($ctx->getMethod()));

        $txnMgr = $this->getTransactionManager($ctx);
        $txnStatus = $txnMgr->getTransaction($stereo->getTransactionDefinition());
        $exCtx->setVariable(self::OPERATION, $txnStatus);
    }

    public function beginFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void {
        self::logException($ex);

        /** @var TransactionStatus $txnStatus */
        $txnStatus = $exCtx->getVariable(self::OPERATION);
        if (empty($txnStatus)) {
            return;
        }
        $txnMgr = $this->getTransactionManager($ctx);
        $txnMgr->rollback($txnStatus);
    }

    public function commit(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result
    ): void {
        self::logInfo('Committing transaction on method '
            . ReflectionUtil::getFqName($ctx->getMethod()));
        /** @var TransactionStatus $txnStatus */
        $txnStatus = $exCtx->getVariable(self::OPERATION);
        $txnMgr = $this->getTransactionManager($ctx);

        if ($txnStatus->isRollbackOnly()) {
            $txnMgr->rollback($txnStatus);
        } else {
            $txnMgr->commit($txnStatus);
        }
    }

    public function commitFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $ex
    ): void {
        self::logException($ex);

        /** @var TransactionStatus $txnStatus */
        $txnStatus = $exCtx->getVariable(self::OPERATION);
        $txnMgr = $this->getTransactionManager($ctx);

        $txnMgr->rollback($txnStatus);
    }

    public function failed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void {
        /** @var Transactional $stereo */
        $stereo = $ctx->getStereoType();

        self::logException($ex);

        /** @var TransactionStatus $txnStatus */
        $txnStatus = $exCtx->getVariable(self::OPERATION);
        if (empty($txnStatus)) {
            return;
        }
        $txnMgr = $this->getTransactionManager($ctx);
        
        if (empty($stereo->rollbackFor)) {
            $rollBack = true;
        } else {
            $rollBack = false;
            foreach ($stereo->rollbackFor as $cls) {
                if (ExceptionUtils::containsException($ex, $cls)) {
                    $rollBack = true;
                    break;
                }
            }
        }
        foreach ($stereo->noRollbackFor as $cls) {
            if (ExceptionUtils::containsException($ex, $cls)) {
                self::logInfo('NoRollback setup for exception ' . $cls . ', hence not rolling back!');
                $rollBack = false;
                break;
            }
        }

        if ($rollBack) {
            $txnMgr->rollback($txnStatus);
        }
    }

}