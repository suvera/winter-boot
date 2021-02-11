<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0001;

use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;

#[Configuration]
class T0030Service {

    #[Bean]
    public function getSomeBean(): T0031Service {
        return new T0031Service();
    }

    #[Bean]
    public function getOtherBeanByInterface(): T0032Service {
        return new T0032ServiceImpl();
    }
}