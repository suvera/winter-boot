<?php
declare(strict_types=1);

namespace test\winterframework\stereotype;

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\RequestMapping;
use PHPUnit\Framework\TestCase;
use test\winterframework\stereotype\classes\FailedRequestMapping02;
use test\winterframework\stereotype\classes\RequestMapping01;
use test\winterframework\stereotype\classes\RequestMappingMethod03;
use Throwable;

class RequestMappingTests extends TestCase {

    public function testRequestMappingClass01(): void {
        $scanner = ClassResourceScanner::getDefaultScanner();

        $resource = $scanner->scanDefaultClass(RequestMapping01::class);

        $this->assertSame($resource->getAttributes()->count(), 2);
        $this->assertSame($resource->getMethods()->count(), 0);
        $this->assertSame($resource->getVariables()->count(), 0);
        $this->assertSame($resource->getClass()->getName(), RequestMapping01::class);

        $attrs = $resource->getAttributes();

        $this->assertTrue($attrs[0] instanceof RestController);
        $this->assertTrue($attrs[1] instanceof RequestMapping);

        /** @var RequestMapping $reqMap */
        $reqMap = $attrs[1];
        $this->assertSame(count($reqMap->getUriPaths()), 1);
        $this->assertSame($reqMap->path, '/users/');
        $this->assertSame($reqMap->method, RequestMethod::getAll());

        $paths = $reqMap->getUriPaths();
        $this->assertSame($paths['users']->getRegex(), 'users');
    }

    public function testRequestMappingClass02(): void {
        $scanner = ClassResourceScanner::getDefaultScanner();

        try {
            $scanner->scanDefaultClass(FailedRequestMapping02::class);
            $this->assertSame('RequestMapping02 must throw exception',
                'but it did not, find the root cause');
        } catch (Throwable $e) {
            $this->assertTrue($e instanceof InvalidSyntaxException);
        }
    }

    public function testRequestMappingMethod03(): void {
        $scanner = ClassResourceScanner::getDefaultScanner();

        $resource = $scanner->scanDefaultClass(RequestMappingMethod03::class);

        $this->assertSame($resource->getAttributes()->count(), 2);
        $this->assertSame($resource->getMethods()->count(), 1);
        $this->assertSame($resource->getVariables()->count(), 0);
        $this->assertSame($resource->getClass()->getName(), RequestMappingMethod03::class);
//
//        $attrs2 = $resource->getAttributes();
//
//        $this->assertTrue($attrs[0] instanceof RestController);
//        $this->assertTrue($attrs[1] instanceof RequestMapping);
//
//        /** @var RequestMapping $reqMap */
//        $reqMap = $attrs[1];
//        $this->assertSame(count($reqMap->getUriPaths()), 1);
//        $this->assertSame($reqMap->path, '/users/');
//        $this->assertEmpty($reqMap->method);
//
//        $paths = $reqMap->getUriPaths();
//        $this->assertSame($paths['users']->getRegex(), 'users');
    }

}