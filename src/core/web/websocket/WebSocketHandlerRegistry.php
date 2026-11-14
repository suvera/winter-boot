<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\DuplicatePathException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\stereotype\web\WebSocketController;
use dev\winterframework\web\websocket\WebSocketHandler;

/**
 * Maps handshake paths (relative to the context path, without slashes at
 * either end) to #[WebSocketController] beans. Exact matches only; the
 * table is fixed at startup.
 */
final class WebSocketHandlerRegistry {
    /** @var array<string, array{class: string, name: string, path: string}> */
    private array $handlers = [];

    public static function fromResources(ClassResources $resources): self {
        $registry = new self();
        foreach ($resources->getClassesByAttribute(WebSocketController::class) as $clsRes) {
            /** @var ClassResource $clsRes */
            /** @var WebSocketController $attr */
            $attr = $clsRes->getAttribute(WebSocketController::class);
            $registry->register($attr->path, $clsRes->getClass()->getName(), $attr->name);
        }
        return $registry;
    }

    public static function normalisePath(string $path): string {
        return trim((string)preg_replace('#/+#', '/', $path), '/');
    }

    public function register(string $path, string $class, string $beanName = ''): void {
        $key = self::normalisePath($path);
        if ($key === '' || preg_match('#[\s?\#{}*]#', $key)) {
            throw new WinterException("Invalid #[WebSocketController] path '$path' on $class");
        }
        $existing = $this->handlers[$key] ?? null;
        if ($existing !== null && $existing['class'] !== $class) {
            throw new DuplicatePathException(
                "WebSocket path '/$key' is used by both {$existing['class']} and $class"
            );
        }
        $this->handlers[$key] = ['class' => $class, 'name' => $beanName, 'path' => '/' . $key];
    }

    public function isEmpty(): bool {
        return $this->handlers === [];
    }

    /** @return array{class: string, name: string, path: string}|null */
    public function find(string $uri): ?array {
        return $this->handlers[self::normalisePath($uri)] ?? null;
    }

    /** @return string[] registered paths, with a leading slash */
    public function getPaths(): array {
        return array_column($this->handlers, 'path');
    }

    /** @param array{class: string, name: string, path: string} $entry */
    public static function resolve(ApplicationContext $ctx, array $entry): WebSocketHandler {
        $bean = $entry['name'] !== '' ? $ctx->beanByName($entry['name']) : $ctx->beanByClass($entry['class']);
        if (!$bean instanceof WebSocketHandler) {
            throw new WinterException($entry['class'] . ' must implement ' . WebSocketHandler::class);
        }
        return $bean;
    }
}
