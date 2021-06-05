<?php
declare(strict_types=1);

namespace dev\winterframework\task;

use dev\winterframework\core\context\WinterTable;

trait TaskPoolExecutorTrait {

    /**
     * @param WinterTable[] $tables
     * @return int
     */
    protected function findAvailableWorker(array $tables): ?int {
        $min = PHP_INT_MAX;
        $workerId = null;

        foreach ($tables as $id => $workTable) {
            $cnt = count($workTable->getTable());
            if ($min > $cnt) {
                $min = $cnt;
                $workerId = $id;
            }
        }

        return $workerId;
    }

}