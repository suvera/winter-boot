<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

use Swoole\Http\Request;
use Swoole\Http\Response;

class SwooleRequest extends HttpRequest {
    protected Response $response;

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct(Request $request, Response $response) {
        $this->response = $response;

        $this->queryParams = $request->get ?? [];
        $this->postParams = $request->post ?? [];
        $this->headers = new HttpHeaders();
        $this->cookies = $request->cookie ?? [];
        $this->method = $request->getMethod();
        $this->uri = $request->server['request_uri'];
        $this->body = $request->getContent();
        $this->contentType = $request->header['content-type'] ?? '';

        // WB-010: intentional mirror for app code reading $_SERVER under Swoole.
        $_SERVER['REQUEST_METHOD'] = $this->method;
        $_SERVER['REQUEST_URI'] = $this->uri;
        // Mirror the TCP peer address: Swoole keeps it in
        // $request->server['remote_addr'] and never fills
        // $_SERVER['REMOTE_ADDR'], so app-level localhost checks would
        // otherwise see '' and reject every request, including local ones.
        // This is the direct peer only; X-Forwarded-For is deliberately
        // not used here since any client can forge it.
        $serverMirror = [
            'remote_addr' => 'REMOTE_ADDR',
            'remote_port' => 'REMOTE_PORT',
            'server_addr' => 'SERVER_ADDR',
            'server_port' => 'SERVER_PORT',
            'server_protocol' => 'SERVER_PROTOCOL',
            'query_string' => 'QUERY_STRING',
            'request_time' => 'REQUEST_TIME',
            'request_time_float' => 'REQUEST_TIME_FLOAT',
        ];
        foreach ($serverMirror as $src => $dest) {
            if (isset($request->server[$src])) {
                $_SERVER[$dest] = $request->server[$src];
            }
        }

        foreach ($request->header as $name => $value) {
            // Canonical 'X-Admin-Api-Key' casing: Swoole lowercases names
            // on the wire, so capitalise every dash-separated word.
            $ucWord = str_replace(' ', '-', ucwords(strtolower(str_replace(['-', '_'], ' ', $name))));
            $this->headers->add($ucWord, $value);
        }

        $files = $request->files ?? [];
        $this->files = $this->loadUploadedFiles($files);
    }

    public function getResponse(): Response {
        return $this->response;
    }

}
