<?php
declare(strict_types=1);

namespace dev\winterframework\txn\aop;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\PropagatesCommitFailure;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\txn\stereotype\Transactional;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\ExceptionUtils;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class TransactionalAspect implements WinterAspect, PropagatesCommitFailure {
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

        // Per call: DEBUG, not INFO.
        self::logDebug('Transaction started on method ' . ReflectionUtil::getFqName($ctx->getMethod()));

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
        self::logDebug('Committing transaction on method '
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
        if (empty($txnStatus) || $txnStatus->isCompleted()) {
            // The failed commit already completed the transaction; a second
            // rollback would throw and hide the commit failure.
            return;
        }
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
                self::logDebug('NoRollback setup for exception ' . $cls . ', hence not rolling back!');
                $rollBack = false;
                break;
            }
        }

        // The exception itself propagates to the caller, which reports it;
        // logging its stack trace here too would report every failure twice.
        $willRollBack = $rollBack || $txnStatus->isRollbackOnly();
        self::logInfo(($willRollBack ? 'Rolling back' : 'Committing') . ' transaction on method '
            . ReflectionUtil::getFqName($ctx->getMethod()) . ' after ' . $ex::class);

        self::completeAfterFailure($txnMgr, $txnStatus, $rollBack);
    }

    /**
     * Finish a transaction whose method threw: roll back, or commit when
     * noRollbackFor matched. Leaving it open would keep the connection in
     * a transaction (and the status on the stack) after the method ends.
     */
    public static function completeAfterFailure(
        PlatformTransactionManager $txnMgr,
        TransactionStatus $txnStatus,
        bool $rollBack
    ): void {
        if ($txnStatus->isCompleted()) {
            return;
        }
        if ($rollBack || $txnStatus->isRollbackOnly()) {
            $txnMgr->rollback($txnStatus);
        } else {
            $txnMgr->commit($txnStatus);
        }
    }

}