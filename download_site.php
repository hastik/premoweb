#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript je urceny pro spusteni z prikazove radky.\n");
    exit(1);
}

final class SiteDownloader
{
    private const MAX_PAGES = 500;
    private const REQUEST_TIMEOUT_SECONDS = 20;
    private const USER_AGENT = 'LocalSiteDownloader/1.0 (+https://localhost)';
    private const HTML_EXTENSIONS = ['html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'cfm', 'shtml'];
    private const ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'json', 'xml', 'txt', 'map',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'avif', 'bmp',
        'mp4', 'webm', 'mp3', 'wav', 'ogg', 'mov', 'avi', 'm4a',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'pdf', 'zip', 'rar', '7z', 'tar', 'gz',
    ];

    private string $rootUrl;
    private string $rootHost;
    /** @var array<string, bool> */
    private array $allowedHosts = [];
    /** @var array<int, string> */
    private array $queue = [];
    /** @var array<string, bool> */
    private array $queued = [];
    /** @var array<string, bool> */
    private array $visitedPages = [];
    /** @var array<string, string> */
    private array $pageLocalPathByUrl = [];
    /** @var array<string, string> */
    private array $assetLocalPathByUrl = [];
    /**
     * Hierarchy nodes keyed by page id from body[id=page-N].
     *
     * @var array<string, array{
     *   id: string,
     *   bodyId: string,
     *   title: string,
     *   sourceUrl: string,
     *   file: string,
     *   path: string,
     *   parents: list<string>,
     *   children: list<string>
     * }>
     */
    private array $pageNodes = [];
    /**
     * Pages without body id=page-N.
     *
     * @var list<array{title: string, sourceUrl: string, file: string, path: string}>
     */
    private array $pagesWithoutId = [];

    private string $projectRoot;
    private string $pagesRoot;
    private string $assetsRoot;
    private string $treeJsonPath;

    public function __construct(string $rootUrl, string $projectRoot)
    {
        $normalized = $this->normalizeUrl($rootUrl);
        if ($normalized === null) {
            throw new InvalidArgumentException("Neplatna URL adresa: {$rootUrl}");
        }

        $parts = parse_url($normalized);
        if ($parts === false || !isset($parts['host'])) {
            throw new InvalidArgumentException("Nepodarilo se zpracovat host z URL: {$rootUrl}");
        }

        $this->rootUrl = $normalized;
        $this->rootHost = strtolower($parts['host']);
        $this->allowedHosts = [$this->rootHost => true];
        if (str_starts_with($this->rootHost, 'www.')) {
            $this->allowedHosts[substr($this->rootHost, 4)] = true;
        } else {
            $this->allowedHosts['www.' . $this->rootHost] = true;
        }

        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->pagesRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'pages';
        $this->assetsRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $this->treeJsonPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'pages-tree.json';
    }

    public function run(): void
    {
        $this->prepareOutputDirectories();
        $this->enqueuePage($this->rootUrl);

        $downloadedPages = 0;
        while (!empty($this->queue)) {
            if ($downloadedPages >= self::MAX_PAGES) {
                echo "Dosazen limit " . self::MAX_PAGES . " stranek. Crawlovani zastaveno.\n";
                break;
            }

            $url = array_shift($this->queue);
            if ($url === null) {
                break;
            }
            unset($this->queued[$url]);

            if (isset($this->visitedPages[$url])) {
                continue;
            }

            $this->visitedPages[$url] = true;
            echo "Stahuji stranku: {$url}\n";

            $response = $this->download($url);
            if ($response === null) {
                continue;
            }

            $effectiveUrl = $response['effective_url'];
            if (!$this->isInternalUrl($effectiveUrl)) {
                echo "Preskakuji - presmerovani na externi domenu: {$effectiveUrl}\n";
                continue;
            }

            $this->visitedPages[$effectiveUrl] = true;
            if ($effectiveUrl !== $url) {
                $this->pageLocalPathByUrl[$url] = $this->getPageLocalRelativePath($effectiveUrl);
            }

            if (!$this->looksLikeHtml($effectiveUrl, $response['content_type'], $response['body'])) {
                echo "Preskakuji - obsah neni HTML: {$effectiveUrl}\n";
                continue;
            }

            $pageRelativePath = $this->getPageLocalRelativePath($effectiveUrl);
            $this->registerPageHierarchy($response['body'], $effectiveUrl, $pageRelativePath);
            $rewrittenHtml = $this->rewriteHtmlAndQueueLinks(
                $response['body'],
                $effectiveUrl
            );

            $targetFile = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pageRelativePath);
            $this->ensureDirectory(dirname($targetFile));
            file_put_contents($targetFile, $rewrittenHtml);

            $downloadedPages++;
        }

        $this->writePageTreeJson();

        echo "\nHotovo.\n";
        echo "Stazenych HTML stranek: {$downloadedPages}\n";
        echo "Stazenych assetu: " . count($this->assetLocalPathByUrl) . "\n";
        echo "Stranek ve stromu (page-ID): " . count($this->pageNodes) . "\n";
        echo "Strom stranek: {$this->treeJsonPath}\n";
        echo "Vystup: {$this->pagesRoot} a {$this->assetsRoot}\n";
    }

    private function prepareOutputDirectories(): void
    {
        $this->ensureDirectory($this->pagesRoot);
        $this->ensureDirectory($this->assetsRoot);
        foreach (['img', 'css', 'js', 'fonts', 'media', 'other'] as $subDir) {
            $this->ensureDirectory($this->assetsRoot . DIRECTORY_SEPARATOR . $subDir);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Nepodarilo se vytvorit slozku: {$path}");
        }
    }

    private function enqueuePage(string $url): void
    {
        if (isset($this->visitedPages[$url]) || isset($this->queued[$url])) {
            return;
        }

        $this->queue[] = $url;
        $this->queued[$url] = true;
    }

    /**
     * @return array{effective_url: string, content_type: string, body: string}|null
     */
    private function download(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            echo "Chyba: curl_init selhalo pro {$url}\n";
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_FAILONERROR => false,
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false) {
            echo "Chyba stahovani {$url}: {$curlError}\n";
            return null;
        }

        if ($statusCode >= 400) {
            echo "HTTP chyba {$statusCode}: {$url}\n";
            return null;
        }

        $normalizedEffectiveUrl = $this->normalizeUrl($effectiveUrl);
        if ($normalizedEffectiveUrl === null) {
            echo "Chyba: nelze normalizovat URL po presmerovani {$effectiveUrl}\n";
            return null;
        }

        return [
            'effective_url' => $normalizedEffectiveUrl,
            'content_type' => strtolower($contentType),
            'body' => $body,
        ];
    }

    private function looksLikeHtml(string $url, string $contentType, string $body): bool
    {
        if (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml')) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::HTML_EXTENSIONS, true) || $extension === '') {
            return true;
        }

        return str_starts_with(ltrim($body), '<!DOCTYPE html')
            || str_starts_with(ltrim($body), '<html');
    }

    private function registerPageHierarchy(string $html, string $sourceUrl, string $localRelativePath): void
    {
        $title = $this->extractTitle($html);
        $bodyId = $this->extractBodyPageId($html);
        $parents = $this->extractBodyParentIds($html, $bodyId);
        $publicPath = $this->toPublicUrl($localRelativePath);

        if ($bodyId === null) {
            $this->pagesWithoutId[] = [
                'title' => $title,
                'sourceUrl' => $sourceUrl,
                'file' => $localRelativePath,
                'path' => $publicPath,
            ];
            return;
        }

        $existingParents = $this->pageNodes[$bodyId]['parents'] ?? [];
        $mergedParents = array_values(array_unique(array_merge($existingParents, $parents)));
        sort($mergedParents, SORT_NATURAL);

        $this->pageNodes[$bodyId] = [
            'id' => $bodyId,
            'bodyId' => 'page-' . $bodyId,
            'title' => $title,
            'sourceUrl' => $sourceUrl,
            'file' => $localRelativePath,
            'path' => $publicPath,
            'parents' => $mergedParents,
            'children' => [],
        ];
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return '';
        }

        $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    private function extractBodyPageId(string $html): ?string
    {
        if (preg_match('/<body\b([^>]*)>/is', $html, $bodyMatch) !== 1) {
            return null;
        }

        if (preg_match('/\bid\s*=\s*(["\'])\s*(page-(\d+))\s*\1/i', $bodyMatch[1], $idMatch) !== 1) {
            return null;
        }

        return $idMatch[3];
    }

    /**
     * @return list<string>
     */
    private function extractBodyParentIds(string $html, ?string $selfId): array
    {
        if (preg_match('/<body\b([^>]*)>/is', $html, $bodyMatch) !== 1) {
            return [];
        }

        if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/is', $bodyMatch[1], $styleMatch) !== 1) {
            return [];
        }

        if (preg_match_all('/page-(\d+)/i', $styleMatch[2], $parentMatches) === false) {
            return [];
        }

        $parents = [];
        foreach ($parentMatches[1] as $parentId) {
            if ($selfId !== null && $parentId === $selfId) {
                continue;
            }
            $parents[] = $parentId;
        }

        $parents = array_values(array_unique($parents));
        sort($parents, SORT_NATURAL);
        return $parents;
    }

    private function writePageTreeJson(): void
    {
        ksort($this->pageNodes, SORT_NATURAL);

        foreach ($this->pageNodes as &$node) {
            $node['children'] = [];
        }
        unset($node);

        foreach ($this->pageNodes as $id => $node) {
            foreach ($node['parents'] as $parentId) {
                if (!isset($this->pageNodes[$parentId])) {
                    continue;
                }
                $this->pageNodes[$parentId]['children'][] = $id;
            }
        }

        foreach ($this->pageNodes as &$node) {
            $node['children'] = array_values(array_unique($node['children']));
            sort($node['children'], SORT_NATURAL);
        }
        unset($node);

        $rootIds = [];
        foreach ($this->pageNodes as $id => $node) {
            $hasKnownParent = false;
            foreach ($node['parents'] as $parentId) {
                if (isset($this->pageNodes[$parentId])) {
                    $hasKnownParent = true;
                    break;
                }
            }
            if (!$hasKnownParent) {
                $rootIds[] = $id;
            }
        }

        $tree = [];
        foreach ($rootIds as $rootId) {
            $tree[] = $this->buildTreeNode($rootId, []);
        }

        $payload = [
            'rootUrl' => $this->rootUrl,
            'generatedAt' => gmdate('c'),
            'pages' => $this->pageNodes,
            'tree' => $tree,
            'pagesWithoutId' => $this->pagesWithoutId,
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Nepodarilo se serializovat pages-tree.json');
        }

        file_put_contents($this->treeJsonPath, $json . "\n");
    }

    /**
     * @param list<string> $ancestors
     * @return array{id: string, bodyId: string, title: string, path: string, file: string, children: list<array<mixed>>}
     */
    private function buildTreeNode(string $id, array $ancestors): array
    {
        $node = $this->pageNodes[$id];
        $children = [];

        if (!in_array($id, $ancestors, true)) {
            $nextAncestors = [...$ancestors, $id];
            foreach ($node['children'] as $childId) {
                if (!isset($this->pageNodes[$childId])) {
                    continue;
                }
                $children[] = $this->buildTreeNode($childId, $nextAncestors);
            }
        }

        return [
            'id' => $node['id'],
            'bodyId' => $node['bodyId'],
            'title' => $node['title'],
            'path' => $node['path'],
            'file' => $node['file'],
            'children' => $children,
        ];
    }

    private function rewriteHtmlAndQueueLinks(string $html, string $pageUrl): string
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//*[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $this->rewriteUrlAttribute($node, 'href', $pageUrl);
        }

        foreach ($xpath->query('//*[@src]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $this->rewriteUrlAttribute($node, 'src', $pageUrl);
        }

        foreach ($xpath->query('//*[@poster]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $this->rewriteAssetAttribute($node, 'poster', $pageUrl);
        }

        foreach ($xpath->query('//*[@data]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if (strtolower($node->nodeName) === 'object') {
                $this->rewriteAssetAttribute($node, 'data', $pageUrl);
            }
        }

        foreach ($xpath->query('//*[@srcset]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $srcset = $node->getAttribute('srcset');
            $rewritten = $this->rewriteSrcset($srcset, $pageUrl);
            $node->setAttribute('srcset', $rewritten);
        }

        foreach ($xpath->query('//*[@style]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $style = $node->getAttribute('style');
            $node->setAttribute('style', $this->rewriteCssUrls($style, $pageUrl));
        }

        foreach ($xpath->query('//style') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $css = $node->textContent;
            if ($css === null || $css === '') {
                continue;
            }
            $node->textContent = $this->rewriteCssUrls($css, $pageUrl);
        }

        $result = $dom->saveHTML();
        return $result === false ? $html : $result;
    }

    private function rewriteUrlAttribute(
        DOMElement $node,
        string $attribute,
        string $pageUrl
    ): void {
        $rawValue = trim($node->getAttribute($attribute));
        if ($rawValue === '' || $this->isSkippableReference($rawValue)) {
            return;
        }

        $fragment = parse_url($rawValue, PHP_URL_FRAGMENT);
        $absoluteUrl = $this->toAbsoluteUrl($pageUrl, $rawValue);
        if ($absoluteUrl === null || !$this->isInternalUrl($absoluteUrl)) {
            return;
        }

        $tag = strtolower($node->nodeName);
        $isPage = $this->shouldTreatAsPageLink($tag, $node, $absoluteUrl);

        if ($isPage) {
            $this->enqueuePage($absoluteUrl);
            $localTarget = $this->getPageLocalRelativePath($absoluteUrl);
            $rewritten = $this->toPublicUrl($localTarget);
            if ($fragment !== null && $fragment !== '') {
                $rewritten .= '#' . $fragment;
            }
            $node->setAttribute($attribute, $rewritten);
            return;
        }

        $assetPath = $this->downloadAsset($absoluteUrl);
        if ($assetPath === null) {
            return;
        }

        $rewritten = $this->toPublicUrl($assetPath);
        if ($fragment !== null && $fragment !== '') {
            $rewritten .= '#' . $fragment;
        }
        $node->setAttribute($attribute, $rewritten);
    }

    private function rewriteAssetAttribute(
        DOMElement $node,
        string $attribute,
        string $pageUrl
    ): void {
        $rawValue = trim($node->getAttribute($attribute));
        if ($rawValue === '' || $this->isSkippableReference($rawValue)) {
            return;
        }

        $absoluteUrl = $this->toAbsoluteUrl($pageUrl, $rawValue);
        if ($absoluteUrl === null || !$this->isInternalUrl($absoluteUrl)) {
            return;
        }

        $assetPath = $this->downloadAsset($absoluteUrl);
        if ($assetPath === null) {
            return;
        }

        $node->setAttribute($attribute, $this->toPublicUrl($assetPath));
    }

    private function rewriteSrcset(string $srcset, string $pageUrl): string
    {
        $parts = array_map('trim', explode(',', $srcset));
        $rewrittenParts = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $part, 2);
            if ($tokens === false || $tokens === []) {
                continue;
            }

            $urlToken = $tokens[0];
            $descriptor = $tokens[1] ?? '';

            $absoluteUrl = $this->toAbsoluteUrl($pageUrl, $urlToken);
            if ($absoluteUrl !== null && $this->isInternalUrl($absoluteUrl)) {
                $assetPath = $this->downloadAsset($absoluteUrl);
                if ($assetPath !== null) {
                    $urlToken = $this->toPublicUrl($assetPath);
                }
            }

            $rewrittenParts[] = trim($urlToken . ' ' . $descriptor);
        }

        return implode(', ', $rewrittenParts);
    }

    private function rewriteCssUrls(string $css, string $pageUrl): string
    {
        $result = preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
            function (array $matches) use ($pageUrl): string {
                $rawUrl = trim($matches[2]);
                if ($rawUrl === '' || $this->isSkippableReference($rawUrl)) {
                    return $matches[0];
                }

                $absoluteUrl = $this->toAbsoluteUrl($pageUrl, $rawUrl);
                if ($absoluteUrl === null || !$this->isInternalUrl($absoluteUrl)) {
                    return $matches[0];
                }

                $assetPath = $this->downloadAsset($absoluteUrl);
                if ($assetPath === null) {
                    return $matches[0];
                }

                $publicUrl = $this->toPublicUrl($assetPath);
                return 'url(' . $matches[1] . $publicUrl . $matches[1] . ')';
            },
            $css
        );

        if ($result === null) {
            return $css;
        }

        return preg_replace_callback(
            '/@import\s+(["\'])([^"\']+)\1/i',
            function (array $matches) use ($pageUrl): string {
                $rawUrl = trim($matches[2]);
                if ($rawUrl === '' || $this->isSkippableReference($rawUrl)) {
                    return $matches[0];
                }

                $absoluteUrl = $this->toAbsoluteUrl($pageUrl, $rawUrl);
                if ($absoluteUrl === null || !$this->isInternalUrl($absoluteUrl)) {
                    return $matches[0];
                }

                $assetPath = $this->downloadAsset($absoluteUrl);
                if ($assetPath === null) {
                    return $matches[0];
                }

                $publicUrl = $this->toPublicUrl($assetPath);
                return '@import ' . $matches[1] . $publicUrl . $matches[1];
            },
            $result
        ) ?? $result;
    }

    private function shouldTreatAsPageLink(string $tag, DOMElement $node, string $absoluteUrl): bool
    {
        if ($tag === 'a' || $tag === 'area' || $tag === 'iframe') {
            return $this->isLikelyPageUrl($absoluteUrl);
        }

        if ($tag === 'link') {
            $rel = strtolower($node->getAttribute('rel'));
            $assetRels = ['stylesheet', 'icon', 'preload', 'manifest', 'apple-touch-icon', 'mask-icon'];
            foreach ($assetRels as $assetRel) {
                if (str_contains($rel, $assetRel)) {
                    return false;
                }
            }
            return $this->isLikelyPageUrl($absoluteUrl);
        }

        if ($tag === 'script') {
            return false;
        }

        return $this->isLikelyPageUrl($absoluteUrl) && !in_array($tag, ['img', 'source', 'video', 'audio'], true);
    }

    private function isLikelyPageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === '') {
            return true;
        }

        if (in_array($ext, self::HTML_EXTENSIONS, true)) {
            return true;
        }

        if (in_array($ext, self::ASSET_EXTENSIONS, true)) {
            return false;
        }

        return false;
    }

    private function downloadAsset(string $url): ?string
    {
        if (isset($this->assetLocalPathByUrl[$url])) {
            return $this->assetLocalPathByUrl[$url];
        }

        echo "  - Stahuji asset: {$url}\n";
        $response = $this->download($url);
        if ($response === null) {
            return null;
        }

        $effectiveUrl = $response['effective_url'];
        if (!$this->isInternalUrl($effectiveUrl)) {
            return null;
        }

        $contentType = $response['content_type'];
        $subDir = $this->pickAssetSubDir($effectiveUrl, $contentType);
        $fileName = $this->buildAssetFileName($effectiveUrl, $contentType, $response['body']);
        $relativePath = 'assets/' . $subDir . '/' . $fileName;

        $absolutePath = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $absolutePath = $this->avoidCollision($absolutePath);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($this->projectRoot) + 1));

        $this->ensureDirectory(dirname($absolutePath));
        file_put_contents($absolutePath, $response['body']);

        $this->assetLocalPathByUrl[$url] = $relativePath;
        $this->assetLocalPathByUrl[$effectiveUrl] = $relativePath;
        return $relativePath;
    }

    private function pickAssetSubDir(string $url, string $contentType): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (preg_match('/^image\//', $contentType) || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'avif', 'bmp'], true)) {
            return 'img';
        }

        if (str_contains($contentType, 'text/css') || $extension === 'css') {
            return 'css';
        }

        if (str_contains($contentType, 'javascript') || in_array($extension, ['js', 'mjs'], true)) {
            return 'js';
        }

        if (preg_match('/font|woff|ttf|otf|eot/', $contentType) || in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true)) {
            return 'fonts';
        }

        if (preg_match('/audio|video/', $contentType) || in_array($extension, ['mp4', 'webm', 'mp3', 'wav', 'ogg', 'mov', 'avi', 'm4a'], true)) {
            return 'media';
        }

        return 'other';
    }

    private function buildAssetFileName(string $url, string $contentType, string $body): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $baseName = basename($path);
        if ($baseName === '' || $baseName === '.' || $baseName === '/') {
            $baseName = 'asset';
        }

        $baseName = $this->sanitizeFileName($baseName);
        $extension = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $baseName;
        }

        $inferredExt = $this->extensionFromContentType($contentType);
        if ($inferredExt !== null) {
            return $baseName . '.' . $inferredExt;
        }

        if ($this->looksBinary($body)) {
            return $baseName . '.bin';
        }

        return $baseName . '.txt';
    }

    private function extensionFromContentType(string $contentType): ?string
    {
        $map = [
            'text/css' => 'css',
            'application/javascript' => 'js',
            'text/javascript' => 'js',
            'application/json' => 'json',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'font/woff' => 'woff',
            'font/woff2' => 'woff2',
            'text/plain' => 'txt',
            'application/pdf' => 'pdf',
        ];

        foreach ($map as $needle => $ext) {
            if (str_contains($contentType, $needle)) {
                return $ext;
            }
        }

        return null;
    }

    private function looksBinary(string $body): bool
    {
        return preg_match('~[^\x09\x0A\x0D\x20-\x7E]~', substr($body, 0, 512)) === 1;
    }

    private function avoidCollision(string $absolutePath): string
    {
        if (!file_exists($absolutePath)) {
            return $absolutePath;
        }

        $dir = dirname($absolutePath);
        $name = pathinfo($absolutePath, PATHINFO_FILENAME);
        $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);

        for ($i = 1; $i < 10000; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $name . '-' . $i;
            if ($ext !== '') {
                $candidate .= '.' . $ext;
            }
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Nelze najit volny nazev souboru pro {$absolutePath}");
    }

    private function getPageLocalRelativePath(string $url): string
    {
        if (isset($this->pageLocalPathByUrl[$url])) {
            return $this->pageLocalPathByUrl[$url];
        }

        $parts = parse_url($url);
        $rawPath = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        $trailingSlash = str_ends_with($rawPath, '/');

        $pathSegments = $this->sanitizePathSegments($rawPath);

        if ($pathSegments === '') {
            $filePath = 'index.html';
        } elseif ($trailingSlash) {
            $filePath = $pathSegments . '/index.html';
        } else {
            $extension = strtolower(pathinfo($pathSegments, PATHINFO_EXTENSION));
            if ($extension === '') {
                $filePath = $pathSegments . '.html';
            } elseif (in_array($extension, self::HTML_EXTENSIONS, true)) {
                $name = pathinfo($pathSegments, PATHINFO_FILENAME);
                $dir = dirname($pathSegments);
                $base = $name . '.html';
                $filePath = ($dir === '.' ? '' : $dir . '/') . $base;
            } else {
                $name = pathinfo($pathSegments, PATHINFO_FILENAME);
                $dir = dirname($pathSegments);
                $base = $name . '.html';
                $filePath = ($dir === '.' ? '' : $dir . '/') . $base;
            }
        }

        if ($query !== '') {
            $hash = substr(sha1($query), 0, 10);
            $filePath = preg_replace('/\.html$/', "--q-{$hash}.html", $filePath) ?? $filePath;
        }

        $relativePath = 'pages/' . ltrim($filePath, '/');
        $this->pageLocalPathByUrl[$url] = $relativePath;
        return $relativePath;
    }

    private function sanitizePathSegments(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        $sanitized = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $decoded = rawurldecode($part);
            $clean = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $decoded) ?? $decoded;
            $clean = trim($clean, '-');
            $sanitized[] = $clean !== '' ? $clean : 'part';
        }

        return implode('/', $sanitized);
    }

    private function sanitizeFileName(string $fileName): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $fileName) ?? $fileName;
        $clean = trim($clean, '-');
        return $clean === '' ? 'file' : $clean;
    }

    private function isSkippableReference(string $value): bool
    {
        $lower = strtolower($value);
        return str_starts_with($lower, '#')
            || str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:');
    }

    private function toAbsoluteUrl(string $baseUrl, string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        $reference = trim($reference);
        if ($this->isSkippableReference($reference)) {
            return null;
        }

        $refParts = parse_url($reference);
        if ($refParts !== false && isset($refParts['scheme'])) {
            return $this->normalizeUrl($reference);
        }

        if (str_starts_with($reference, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
            return $this->normalizeUrl($scheme . ':' . $reference);
        }

        $baseParts = parse_url($baseUrl);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $scheme = strtolower($baseParts['scheme']);
        $host = strtolower($baseParts['host']);
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (str_starts_with($reference, '/')) {
            return $this->normalizeUrl($origin . $reference);
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
        if ($baseDir === '//' || $baseDir === '\\') {
            $baseDir = '/';
        }

        $mergedPath = $this->removeDotSegments($baseDir . $reference);
        return $this->normalizeUrl($origin . $mergedPath);
    }

    private function removeDotSegments(string $path): string
    {
        $hasTrailingSlash = str_ends_with($path, '/');
        $input = explode('/', $path);
        $output = [];

        foreach ($input as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($output);
                continue;
            }
            $output[] = $segment;
        }

        $normalized = '/' . implode('/', $output);
        if ($hasTrailingSlash && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }

    private function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = '';
        if (isset($parts['port']) && !(($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))) {
            $port = ':' . $parts['port'];
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $path = $this->removeDotSegments($path);

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function isInternalUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        return isset($this->allowedHosts[$host]);
    }

    /**
     * Convert a stored local path into a public URL used with .htaccess rewrites.
     * pages/index.html → /
     * pages/xyz.html → /xyz
     * pages/blog/post.html → /blog/post
     * assets/img/logo.png → /assets/img/logo.png
     */
    private function toPublicUrl(string $localRelativePath): string
    {
        $path = ltrim(str_replace('\\', '/', $localRelativePath), '/');

        if (str_starts_with($path, 'pages/')) {
            $pagePath = substr($path, strlen('pages/'));

            if ($pagePath === 'index.html') {
                return '/';
            }

            if (str_ends_with($pagePath, '/index.html')) {
                return '/' . substr($pagePath, 0, -strlen('/index.html'));
            }

            if (str_ends_with($pagePath, '.html')) {
                return '/' . substr($pagePath, 0, -strlen('.html'));
            }

            return '/' . $pagePath;
        }

        return '/' . $path;
    }
}

function ask(string $message): string
{
    if (function_exists('readline')) {
        $line = readline($message);
    } else {
        echo $message;
        $line = fgets(STDIN);
    }

    if ($line === false) {
        return '';
    }

    return trim($line);
}

try {
    $inputUrl = ask("Zadej URL webu (napr. https://example.com): ");
    if ($inputUrl === '') {
        throw new InvalidArgumentException("Nebyla zadana URL adresa.");
    }

    if (!preg_match('#^https?://#i', $inputUrl)) {
        $inputUrl = 'https://' . $inputUrl;
    }

    $downloader = new SiteDownloader($inputUrl, __DIR__);
    $downloader->run();
} catch (Throwable $e) {
    fwrite(STDERR, "Chyba: " . $e->getMessage() . "\n");
    exit(1);
}
