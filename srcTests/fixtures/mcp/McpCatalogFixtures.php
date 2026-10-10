<?php
declare(strict_types=1);

namespace winterBootTests\Fixtures\Mcp;

use dev\winterframework\exception\HttpRestException;
use dev\winterframework\mcp\exception\McpResourceNotFoundException;
use dev\winterframework\stereotype\mcp\McpPrompt;
use dev\winterframework\stereotype\mcp\McpResource;
use dev\winterframework\stereotype\Service;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;

#[Service]
class SiteCatalog {

    private const SITES = ['example.com', 'shop.example'];

    #[McpResource(uri: 'config://app', name: 'app_config', title: 'App configuration',
        description: 'Public settings of this server.')]
    public function config(): array {
        return ['version' => '1.0', 'empty' => new \stdClass()];
    }

    /**
     * Traffic overview for a site; range is "today" or "last-7-days".
     */
    #[McpResource(uri: 'site://{domain}/summary/{range}', name: 'site_summary', title: 'Site traffic summary',
        listMethod: 'listSummaries')]
    public function summary(string $domain, string $range, HttpRequest $request): array {
        if ($domain === 'secret.example') {
            throw new HttpRestException(HttpStatus::$FORBIDDEN, 'not yours');
        }
        if (!in_array($domain, self::SITES, true) || !in_array($range, ['today', 'last-7-days'], true)) {
            throw new McpResourceNotFoundException('no such site or range');
        }
        return ['site' => $domain, 'range' => $range, 'visitors' => 42, 'key' => $request->getFirstHeader('X-Key')];
    }

    /** Concrete summaries this caller may read. */
    public function listSummaries(HttpRequest $request): array {
        $out = [];
        foreach (self::SITES as $site) {
            if ($request->getFirstHeader('X-Key') === 'limited' && $site !== 'example.com') {
                continue;
            }
            $out[] = ['uri' => "site://$site/summary/today", 'name' => "$site today", 'title' => "$site: today"];
        }
        return $out;
    }

    #[McpResource(uri: 'item://{id}', description: 'One item as text.')]
    public function item(int $id): ?string {
        return $id === 404 ? null : "item #$id";
    }

    /**
     * Summarise the last 7 days against the week before.
     *
     * @param string      $site   Site domain.
     * @param string|null $period Period, e.g. 30d.
     */
    #[McpPrompt(name: 'weekly_report', title: 'Weekly report')]
    public function weekly(string $site, ?string $period = null): string {
        return "Write a weekly report for $site over " . ($period ?? 'the last 7 days') . '.';
    }

    /** @param string $site Site domain. */
    #[McpPrompt(description: 'Find out what caused a spike.')]
    public function explainSpike(string $site, string $date = 'the recent change'): array {
        return [
            ['role' => 'user', 'text' => "Traffic for $site changed around $date."],
            ['role' => 'assistant', 'text' => 'I will compare the days before and after.'],
        ];
    }

    #[McpPrompt(description: 'Broken: returns a number.')]
    public function broken(): int {
        return 5;
    }
}
