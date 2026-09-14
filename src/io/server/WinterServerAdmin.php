<?php
declare(strict_types=1);

namespace dev\winterframework\io\server;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\WinterException;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\SwooleRequest;
use Swoole\Http\Request;
use Swoole\Http\Response;

class WinterServerAdmin {
    use Wlf4p;

    private string $token = '';

    public function __construct(
        protected ApplicationContext $ctx,
        protected ServerPidManager $pidManager
    ) {
        $tokenFile = $this->ctx->getPropertyStr('winter.admin.auth.tokenFile', '');
        if ($tokenFile) {
            if (!file_exists($tokenFile)) {
                throw new WinterException('File does not exist "winter.admin.auth.tokenFile"');
            }

            $data = file_get_contents($tokenFile);
            if ($data === false) {
                throw new WinterException('Could not read File "winter.admin.auth.tokenFile"');
            }
            $this->token = trim($data);
        }
    }

    // WB-008: admin acts only when a non-empty token is configured.
    public function hasAuth(): bool {
        return $this->token !== '';
    }

    public function serveRequest(Request $request, Response $response): void {
        $req = new SwooleRequest($request, $response);

        $token = '' . $req->getPostParam('token');
        $action = $req->getPostParam('action');

        // WB-008: fail closed on missing auth; constant-time token compare.
        if (!$this->hasAuth() || !hash_equals($this->token, $token)) {
            $response->status(400, 'Bad Request');
            $response->end("NOK\n");
            return;
        }

        switch ($action) {
            case 'shutdown':
                $response->end("OK\n");
                $this->shutdown();
                exit;

            default:
                $response->status(400, 'Bad Request');
                $response->end("NOK\n");
        }
    }

    private function shutdown(): void {
        self::logInfo("Shutting down the Service");
        $this->pidManager->killAll();
    }

}