<?php
declare(strict_types=1);

namespace test\winterframework\core\aop\classes;

use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\Service;

#[Service]
class A001Class {

    #[Lockable(name: __METHOD__)]
    public function syncMethod(int $seconds): void {
        echo "\nSleeping for $seconds seconds ...\n";
        sleep($seconds);
        echo "Sync Method Executed\n";
    }

    #[Lockable(name: 'id_#{id}')]
    public function syncIdMethod(int $id): void {
        echo "syncIdMethod($id) Executed\n";
    }

    #[Lockable(name: 'id_#{id}_#{name}')]
    public function syncIdNameMethod(int $id, string $name): void {
        echo "syncIdNameMethod($id, $name) Executed\n";
    }

    #[Lockable(name: 'id_#{target.getName()}')]
    public function syncGetterMethod(): void {
        echo "syncGetterMethod() Executed\n";
    }

    public function getName(): string {
        return 'Bruce Banner';
    }
}