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

        $_SERVER['REQUEST_METHOD'] = $this->method;
        $_SERVER['REQUEST_URI'] = $this->uri;

        foreach ($request->header as $name => $value) {
            $ucWord = ucwords(strtolower(str_replace('_', ' ', $name)));
            $this->headers->add(str_replace(' ', '-', $ucWord), $value);
        }

        $files = $request->files ?? [];
        $this->files = $this->loadUploadedFiles($files);
    }

    public function getResponse(): Response {
        return $this->response;
    }

}
