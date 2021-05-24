<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\context\WinterWebSwooleContext;
use dev\winterframework\web\http\SwooleRequest;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\HTTP\Server;

class WinterWebSwooleApplication extends WinterApplicationRunner implements WinterApplication {

    protected WinterWebSwooleContext $webContext;

    protected function runBootApp(): void {
        $this->webContext = new WinterWebSwooleContext(
            $this->appCtxData,
            $this->applicationContext
        );

        $this->startServer();
    }

    public function serveRequest(Request $request, Response $response): void {
        //self::logInfo(print_r($request, true));

        $wrapperReq = new SwooleRequest($request, $response);

        $this->webContext->getDispatcher()->dispatch($wrapperReq);
    }

    protected function startServer() {
        $prop = $this->appCtxData->getPropertyContext();
        $address = $prop->get('server.address', '127.0.0.1');
        $port = $prop->get('server.port', '8080');

        $http = new Server($address, $port);

        $args = $this->getServerArgs();
        if (is_array($args)) {
            $http->set($args);
        }

        $http->on('request', [$this, 'serveRequest']);
        $http->on('start', function ($server) use ($address, $port) {
            self::logInfo("Http server started on $address:$port");
        });

        self::logInfo("Starting Http server on $address:$port");
        $http->start();
    }

    protected function getServerArgs(): array {
        $args = [];
        $prop = $this->appCtxData->getPropertyContext();

        $pf = 'server.swoole.';
        $pfLen = strlen($pf);

        foreach ($prop->getAll() as $key => $value) {
            if (str_starts_with($key, $pf)) {
                $args[substr($key, $pfLen)] = $value;
            }
        }

        return $args;
    }
}