--TEST--
native interception: sibling beans sharing an inherited method both stay advised under their own class
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static array $seen = [];
        public static function begin($target, string $class, string $method, array $args): array {
            self::$seen[] = $class . '::' . $method;
            return ['proceed' => false, 'value' => "advised"];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class BaseSvc {
        public function save(): string { return "live"; }
        public static function tag(): string { return "live"; }
    }
    class OrderSvc extends BaseSvc {}
    class UserSvc extends BaseSvc {}
    class SubOrderSvc extends OrderSvc {}
    class OtherSvc extends BaseSvc {}
    winter_boot_advise(OrderSvc::class, 'save');
    winter_boot_advise(UserSvc::class, 'save');
    winter_boot_advise(UserSvc::class, 'save'); // idempotent
    winter_boot_advise(OrderSvc::class, 'tag');
    var_dump((new OrderSvc())->save());
    var_dump((new UserSvc())->save());
    var_dump((new SubOrderSvc())->save());
    var_dump((new OtherSvc())->save());
    var_dump(OrderSvc::tag());
    var_dump(winter_boot_is_advised(OrderSvc::class, 'save'));
    echo implode("\n", NativeAopDriver::$seen), "\n";
}
?>
--EXPECT--
string(7) "advised"
string(7) "advised"
string(7) "advised"
string(4) "live"
string(7) "advised"
bool(true)
OrderSvc::save
UserSvc::save
OrderSvc::save
OrderSvc::tag
