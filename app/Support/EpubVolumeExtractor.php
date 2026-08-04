<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use FilesystemIterator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class EpubVolumeExtractor
{
    private const XHTML_MEDIA_TYPES = [
        'application/xhtml+xml',
        'text/html',
        'application/xml',
        'text/xml',
    ];

    private const IMAGE_MEDIA_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    public function __construct(private readonly UploadedArchive $uploadedArchive)
    {
    }

    public function buildImport(string $extractRoot, UploadedFile $archive): array
    {
        return $this->buildImportFromExtractedEpub($extractRoot, $archive->getClientOriginalName());
    }

    public function buildImportsFromZipRoot(string $zipRoot): array
    {
        $root = realpath($zipRoot) ?: $zipRoot;
        $epubFiles = $this->listEpubFiles($root);

        if ($epubFiles === [] && is_file($root.DIRECTORY_SEPARATOR.'META-INF'.DIRECTORY_SEPARATOR.'container.xml')) {
            return [$this->buildImportFromExtractedEpub($root, basename($root))];
        }

        if ($epubFiles === []) {
            return [];
        }

        $workRoot = $root.DIRECTORY_SEPARATOR.'__epub_extracts';
        File::ensureDirectoryExists($workRoot);

        $imports = [];
        foreach ($epubFiles as $index => $epubPath) {
            $epubRoot = $workRoot.DIRECTORY_SEPARATOR.sprintf('%03d-%s', $index + 1, $this->safeSegment(pathinfo($epubPath, PATHINFO_FILENAME)));
            File::ensureDirectoryExists($epubRoot);
            $this->extractEpubFile($epubPath, $epubRoot);

            $imports[] = $this->buildImportFromExtractedEpub($epubRoot, basename($epubPath));
        }

        return $imports;
    }

    private function buildImportFromExtractedEpub(string $extractRoot, string $originalName): array
    {
        $root = realpath($extractRoot) ?: $extractRoot;
        $opfPath = $this->locatePackageDocument($root);
        [$manifest, $spine, $coverId] = $this->readPackageDocument($opfPath, $root);
        $coverPath = $this->resolveCoverPath($manifest, $coverId);

        $pageDir = $root.DIRECTORY_SEPARATOR.'__reader_pages';
        File::ensureDirectoryExists($pageDir);

        $pages = [];
        foreach ($spine as $idref) {
            $item = $manifest[$idref] ?? null;
            if (!$item || !in_array($item['media_type'], self::XHTML_MEDIA_TYPES, true)) {
                continue;
            }

            $documentPages = $this->buildReaderPages((string) $item['path'], $root);
            foreach ($documentPages as $html) {
                $pageNumber = count($pages) + 1;
                $pagePath = $pageDir.DIRECTORY_SEPARATOR.sprintf('%03d.html', $pageNumber);
                File::put($pagePath, $html);
                $pages[] = $pagePath;
            }
        }

        if ($pages === []) {
            throw new \RuntimeException('EPUB does not contain readable spine documents.');
        }

        $title = trim((string) pathinfo($originalName, PATHINFO_FILENAME));

        return [
            'title' => $title,
            'number' => $this->parseVolumeNumber($title),
            'pages' => $pages,
            'thumbnail' => $coverPath,
        ];
    }

    private function listEpubFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'epub') {
                continue;
            }

            if (!$this->isVisiblePath($path, $root)) {
                continue;
            }

            $files[] = $path;
        }

        natcasesort($files);

        return array_values($files);
    }

    private function extractEpubFile(string $epubPath, string $targetRoot): void
    {
        try {
            $this->uploadedArchive->extractArchiveFileToDirectory($epubPath, $targetRoot, 'EPUB file');
        } catch (\Throwable $e) {
            $message = trim($e->getMessage());

            throw new \RuntimeException(
                $message !== ''
                    ? basename($epubPath).': '.$message
                    : "Could not extract EPUB file '".basename($epubPath)."'.",
                previous: $e
            );
        }
    }

    private function isVisiblePath(string $path, string $root): bool
    {
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)))), '/');

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '__MACOSX' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    private function safeSegment(string $value): string
    {
        $segment = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($value)));
        $segment = trim($segment, '-_');

        return $segment !== '' ? $segment : 'epub';
    }

    private function locatePackageDocument(string $root): string
    {
        $containerPath = $root.DIRECTORY_SEPARATOR.'META-INF'.DIRECTORY_SEPARATOR.'container.xml';
        if (!is_file($containerPath)) {
            throw new \RuntimeException('EPUB is missing META-INF/container.xml.');
        }

        $xml = @simplexml_load_file($containerPath);
        if (!$xml) {
            throw new \RuntimeException('EPUB container.xml could not be read.');
        }

        $rootfiles = $xml->xpath('//*[local-name()="rootfile"]') ?: [];
        foreach ($rootfiles as $rootfile) {
            $fullPath = trim((string) ($rootfile['full-path'] ?? ''));
            if ($fullPath === '') {
                continue;
            }

            $resolved = $this->resolvePath($root, $fullPath, $root);
            if ($resolved && is_file($resolved)) {
                return $resolved;
            }
        }

        throw new \RuntimeException('EPUB package document could not be found.');
    }

    private function readPackageDocument(string $opfPath, string $root): array
    {
        $xml = @simplexml_load_file($opfPath);
        if (!$xml) {
            throw new \RuntimeException('EPUB package document could not be read.');
        }

        $opfDir = dirname($opfPath);
        $manifest = [];
        $coverId = null;

        foreach ($xml->xpath('//*[local-name()="metadata"]/*[local-name()="meta"]') ?: [] as $meta) {
            $name = strtolower(trim((string) ($meta['name'] ?? '')));
            if ($name === 'cover') {
                $coverId = trim((string) ($meta['content'] ?? '')) ?: null;
                break;
            }
        }

        foreach ($xml->xpath('//*[local-name()="manifest"]/*[local-name()="item"]') ?: [] as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            $href = trim((string) ($item['href'] ?? ''));
            if ($id === '' || $href === '') {
                continue;
            }

            $path = $this->resolvePath($opfDir, $href, $root);
            if (!$path) {
                continue;
            }

            $manifest[$id] = [
                'href' => $href,
                'path' => $path,
                'media_type' => strtolower(trim((string) ($item['media-type'] ?? ''))),
                'properties' => strtolower(trim((string) ($item['properties'] ?? ''))),
            ];
        }

        $spine = [];
        foreach ($xml->xpath('//*[local-name()="spine"]/*[local-name()="itemref"]') ?: [] as $itemref) {
            $idref = trim((string) ($itemref['idref'] ?? ''));
            if ($idref !== '') {
                $spine[] = $idref;
            }
        }

        return [$manifest, $spine, $coverId];
    }

    private function resolveCoverPath(array $manifest, ?string $coverId): ?string
    {
        if ($coverId && isset($manifest[$coverId]) && $this->isImageItem($manifest[$coverId])) {
            return (string) $manifest[$coverId]['path'];
        }

        foreach ($manifest as $item) {
            if ($this->isImageItem($item) && str_contains(' '.$item['properties'].' ', ' cover-image ')) {
                return (string) $item['path'];
            }
        }

        foreach ($manifest as $item) {
            $hint = strtolower((string) ($item['href'] ?? ''));
            if ($this->isImageItem($item) && str_contains($hint, 'cover')) {
                return (string) $item['path'];
            }
        }

        foreach ($manifest as $item) {
            if ($this->isImageItem($item)) {
                return (string) $item['path'];
            }
        }

        return null;
    }

    private function isImageItem(array $item): bool
    {
        return in_array((string) ($item['media_type'] ?? ''), self::IMAGE_MEDIA_TYPES, true)
            && is_file((string) ($item['path'] ?? ''));
    }

    private function buildReaderPages(string $documentPath, string $root): array
    {
        $raw = @file_get_contents($documentPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = @$doc->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$loaded) {
            $loaded = @$doc->loadHTML('<?xml encoding="UTF-8">'.$raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $this->removeUnsafeNodes($xpath);
        $this->removeUnsafeAttributes($xpath);
        $css = $this->extractCss($doc, $xpath, dirname($documentPath), $root);
        $this->embedImageReferences($xpath, dirname($documentPath), $root);

        $body = $this->firstElementByLocalName($doc, 'body') ?: $doc->documentElement;
        if (!$body) {
            return [];
        }

        if ($this->shouldKeepAsSingleReaderPage($xpath, $body)) {
            $inner = trim($this->innerHtml($doc, $body));

            return $inner !== ''
                ? [$this->standalonePage($inner, $css)]
                : [];
        }

        $blocks = $this->readingBlocks($body, $doc);
        if ($blocks === []) {
            $inner = trim($this->innerHtml($doc, $body));
            if ($inner !== '' && $this->htmlHasVisibleContent($inner)) {
                $blocks[] = $inner;
            }
        }

        $pages = [];
        foreach ($this->chunkBlocks($blocks) as $chunk) {
            $pages[] = $this->standalonePage($chunk, $css);
        }

        return $pages;
    }

    private function removeUnsafeNodes(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*[local-name()="script" or local-name()="iframe" or local-name()="object" or local-name()="embed" or local-name()="form" or local-name()="input" or local-name()="button"]');
        if (!$nodes) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function removeUnsafeAttributes(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*');
        if (!$nodes) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement || !$node->hasAttributes()) {
                continue;
            }

            $remove = [];
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on') || $name === 'srcdoc') {
                    $remove[] = $attribute->name;
                }
            }

            foreach ($remove as $name) {
                $node->removeAttribute($name);
            }
        }
    }

    private function extractCss(DOMDocument $doc, DOMXPath $xpath, string $baseDir, string $root): string
    {
        $css = '';

        foreach ($xpath->query('//*[local-name()="style"]') ?: [] as $style) {
            $css .= "\n".$style->textContent;
        }

        foreach ($xpath->query('//*[local-name()="link"]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $rel = strtolower((string) $link->getAttribute('rel'));
            $href = trim((string) $link->getAttribute('href'));
            if (!str_contains($rel, 'stylesheet') || $href === '') {
                continue;
            }

            $cssPath = $this->resolvePath($baseDir, $href, $root);
            if ($cssPath && is_file($cssPath)) {
                $css .= "\n".$this->cssWithEmbeddedUrls($cssPath, $root);
            }
        }

        return str_replace('</style', '<\/style', $css);
    }

    private function embedImageReferences(DOMXPath $xpath, string $baseDir, string $root): void
    {
        foreach ($xpath->query('//*[local-name()="img"]') ?: [] as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }

            $this->embedElementUrlAttribute($img, 'src', $baseDir, $root);
        }

        foreach ($xpath->query('//*[local-name()="image"]') ?: [] as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }

            $this->embedElementUrlAttribute($image, 'href', $baseDir, $root);
            $this->embedElementUrlAttribute($image, 'xlink:href', $baseDir, $root);
        }
    }

    private function embedElementUrlAttribute(DOMElement $element, string $attribute, string $baseDir, string $root): void
    {
        $value = trim((string) $element->getAttribute($attribute));
        if ($value === '' || preg_match('#^(?:data:|https?:|mailto:)#i', $value)) {
            return;
        }

        $path = $this->resolvePath($baseDir, $value, $root);
        if (!$path || !is_file($path)) {
            return;
        }

        $dataUri = $this->dataUri($path);
        if ($dataUri) {
            $element->setAttribute($attribute, $dataUri);
        }
    }

    private function cssWithEmbeddedUrls(string $cssPath, string $root): string
    {
        $css = @file_get_contents($cssPath);
        if ($css === false || $css === '') {
            return '';
        }

        return (string) preg_replace_callback('/url\((["\']?)(.*?)\1\)/i', function (array $matches) use ($cssPath, $root) {
            $url = trim((string) ($matches[2] ?? ''));
            if ($url === '' || preg_match('#^(?:data:|https?:|mailto:)#i', $url)) {
                return $matches[0];
            }

            $path = $this->resolvePath(dirname($cssPath), $url, $root);
            if (!$path || !is_file($path)) {
                return $matches[0];
            }

            $dataUri = $this->dataUri($path);

            return $dataUri ? 'url("'.$dataUri.'")' : $matches[0];
        }, $css);
    }

    private function dataUri(string $path): ?string
    {
        $mime = File::mimeType($path) ?: $this->mimeFromExtension($path);
        if (!$mime || (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'font/'))) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    private function mimeFromExtension(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => null,
        };
    }

    private function readingBlocks(DOMNode $root, DOMDocument $doc): array
    {
        $blocks = [];

        foreach ($root->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = trim((string) $child->textContent);
                if ($text !== '') {
                    $blocks[] = '<p>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
                }
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->localName);
            if (in_array($tag, ['section', 'article', 'main', 'div'], true) && $this->hasBlockChildren($child)) {
                $blocks = array_merge($blocks, $this->readingBlocks($child, $doc));
                continue;
            }

            $html = trim((string) $doc->saveHTML($child));
            if ($html !== '' && $this->htmlHasVisibleContent($html)) {
                $blocks[] = $html;
            }
        }

        return $blocks;
    }

    private function htmlHasVisibleContent(string $html): bool
    {
        if (preg_match('/<(?:img|svg|image)\b/i', $html) === 1) {
            return true;
        }

        return $this->htmlVisibleText($html) !== '';
    }

    private function shouldKeepAsSingleReaderPage(DOMXPath $xpath, DOMNode $body): bool
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $body->textContent ?? ''));
        $lowerText = mb_strtolower($text);
        $links = $xpath->query('.//*[local-name()="a"]', $body);
        $navs = $xpath->query(
            './/*[local-name()="nav" and (contains(@*[local-name()="type"], "toc") or contains(@role, "doc-toc"))]',
            $body
        );
        $chapterMatches = preg_match_all('/\bchapter\s+\d+/i', $text);

        if ($navs && $navs->length > 0) {
            return true;
        }

        return (
            str_contains($lowerText, 'table of contents')
            || preg_match('/\bcontents\b/i', $text) === 1
        ) && (($links?->length ?? 0) >= 4 || $chapterMatches >= 4);
    }

    private function hasBlockChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->localName), [
                'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'blockquote',
                'ul', 'ol', 'table', 'section', 'article', 'div', 'main',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function chunkBlocks(array $blocks): array
    {
        $chunks = [];
        $current = '';
        $currentWeight = 0;
        $targetWeight = 2300;

        foreach ($blocks as $block) {
            $weight = $this->blockWeight($block);
            if ($current !== '' && $currentWeight + $weight > $targetWeight) {
                $chunks[] = $current;
                $current = '';
                $currentWeight = 0;
            }

            $current .= $block."\n";
            $currentWeight += $weight;
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function blockWeight(string $html): int
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $imageWeight = substr_count(strtolower($html), '<img') * 1800
            + substr_count(strtolower($html), '<svg') * 1200;

        return max(110, mb_strlen($text) + $imageWeight);
    }

    private function standalonePage(string $bodyHtml, string $bookCss): string
    {
        $isImageOnlyPage = $this->htmlLooksImageOnly($bodyHtml);
        $css = <<<'CSS'
html,
body {
    margin: 0;
    min-height: 100%;
    background: #ffffff;
    color: #000000;
    overflow: hidden;
    scrollbar-width: none;
}
html::-webkit-scrollbar,
body::-webkit-scrollbar {
    display: none;
}
body {
    box-sizing: border-box;
    width: min(720px, calc(100% - 120px));
    min-width: 0;
    max-width: 720px;
    margin: 82px auto 0;
    padding: 0 0 30px;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 18px;
    line-height: 1.36;
    text-align: left;
    overflow-wrap: break-word;
}
body.reader-image-page {
    min-height: 100vh;
    margin: 0 auto;
    padding: 84px 0 0;
    display: flex;
    align-items: flex-start;
    justify-content: center;
}
img,
svg {
    display: block;
    max-width: min(76%, 640px);
    max-height: 78vh;
    width: auto;
    height: auto;
    margin: 0 auto;
    object-fit: contain;
}
a {
    color: #0000ee;
    text-decoration: underline;
}
p {
    margin: 0 0 0.74em;
    text-align: left;
}
h1,
h2,
h3,
h4,
h5,
h6 {
    line-height: 1.25;
    margin: 0 0 2rem;
    text-align: center;
}
CSS;

        $bodyClass = $isImageOnlyPage ? ' class="reader-image-page"' : '';

        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'
            .$bookCss."\n".$css
            .'</style></head><body'.$bodyClass.'>'.$bodyHtml.'</body></html>';
    }

    private function htmlLooksImageOnly(string $html): bool
    {
        $mediaCount = preg_match_all('/<(?:img|svg|image)\b/i', $html);
        if ($mediaCount < 1) {
            return false;
        }

        return mb_strlen($this->htmlVisibleText($html)) <= 120;
    }

    private function htmlVisibleText(string $html): string
    {
        $withoutScriptAndStyle = preg_replace('/<(?:script|style)\b[^>]*>.*?<\/(?:script|style)>/is', '', $html) ?? $html;

        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($withoutScriptAndStyle), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
    }

    private function innerHtml(DOMDocument $doc, DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return $html;
    }

    private function firstElementByLocalName(DOMDocument $doc, string $localName): ?DOMElement
    {
        foreach ($doc->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && strtolower($element->localName) === strtolower($localName)) {
                return $element;
            }
        }

        return null;
    }

    private function resolvePath(string $baseDir, string $href, string $root): ?string
    {
        $href = trim(preg_replace('/[#?].*$/', '', $href) ?? '');
        if ($href === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;
        }

        $href = rawurldecode(str_replace('\\', '/', $href));
        $rootReal = realpath($root) ?: $root;
        $baseReal = realpath($baseDir) ?: $baseDir;
        $candidate = str_starts_with($href, '/')
            ? rtrim($rootReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($href, '/')
            : rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$href;

        $real = realpath($candidate);

        if (!$real) {
            return null;
        }

        $realComparable = $this->comparablePath($real);
        $rootComparable = $this->comparablePath($rootReal);
        $rootPrefix = rtrim($rootComparable, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($realComparable !== $rootComparable && !str_starts_with($realComparable, $rootPrefix)) {
            return null;
        }

        return $real;
    }

    private function comparablePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
    }

    private function parseVolumeNumber(string $name): ?float
    {
        $normalized = mb_strtolower($name);

        if (preg_match('/\b(?:volume|vol|v)[\s\._-]*([0-9]+(?:[\._][0-9]+)?)/i', $normalized, $matches)) {
            return (float) strtr($matches[1], ['_' => '.', ',' => '.']);
        }

        if (preg_match('/\b([0-9]+(?:[\._][0-9]+)?)\b/', $normalized, $matches)) {
            return (float) strtr($matches[1], ['_' => '.', ',' => '.']);
        }

        return null;
    }
}
