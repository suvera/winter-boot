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

        // Per-request server values live on this object (see the
        // getters on HttpRequest), never in $_SERVER: the worker
        // process is shared by concurrent coroutines, so writing the
        // superglobal here would let one request overwrite another's.
        // Swoole keeps these in $request->server. remote_addr is the
        // direct TCP peer only; X-Forwarded-For is deliberately not
        // used since any client can forge it.
        $server = $request->server ?? [];
        if (isset($server['remote_addr'])) {
            $this->remoteAddr = (string)$server['remote_addr'];
        }
        if (isset($server['remote_port'])) {
            $this->remotePort = (int)$server['remote_port'];
        }
        if (isset($server['server_addr'])) {
            $this->serverAddr = (string)$server['server_addr'];
        }
        if (isset($server['server_port'])) {
            $this->serverPort = (int)$server['server_port'];
        }
        if (isset($server['server_protocol'])) {
            $this->serverProtocol = (string)$server['server_protocol'];
        }
        if (isset($server['query_string'])) {
            $this->queryString = (string)$server['query_string'];
        }
        if (isset($server['request_time'])) {
            $this->requestTime = (int)$server['request_time'];
        }
        if (isset($server['request_time_float'])) {
            $this->requestTimeFloat = (float)$server['request_time_float'];
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
