@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\URL;

        if (!function_exists('shortTitle')) {
            function shortTitle($title, $maxLen = 25) {
                if (strlen($title) <= $maxLen) {
                    return $title;
                }
                return substr($title, 0, $maxLen - 1) . '…';
            }
        }
        $allowedExts = ['jpg','jpeg','png','gif','webp'];
        $htmlExts = ['html','htm','xhtml'];

        // --- FORCE VIEW FOR MANHWA ---
        $view = $readerView ?? request('view', 'one');
        $isManhwa = $isManhwa ?? false; // passed from controller
        $isLightNovel = $isLightNovel ?? false;
        $readerUnitLabel = $readerUnitLabel ?? 'Chapter';
        $isCurrentPageBookmarked = $isCurrentPageBookmarked ?? false;
        if ($isManhwa) {
            $view = 'scroll';
        }
        if ($isLightNovel) {
            $view = 'double';
        }
        $isFixedView = in_array($view, ['one', 'double'], true) && !$isManhwa;

        // Header link points back to page 1 of the same chapter + current view
        $headerLink = route('chapters.page', [
            'media'   => $chapter->item_id,
            'chapter' => $chapter->chapter_number,
            'page'    => 1,
            'view'    => $view,
        ]);

        // View switcher base params
        $baseParams = ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number];
        $bookmarkRoute = route('chapters.bookmark', [
            'media' => $chapter->media_fk ?: $chapter->item_id,
            'chapter' => $chapter->id,
        ]);

        // Build spreads for double-page mode.
        $pageObjectsByNumber = $chapter->pages->sortBy('page_number')->keyBy('page_number');
        $pageNums = $pageObjectsByNumber->keys()->values();
        $nums = $pageNums->all();

        $getStoredPageAbsolutePath = static function (?string $filePath): ?string {
            if (!$filePath) {
                return null;
            }

            $absolutePath = public_path('storage/' . ltrim($filePath, '/'));
            if (!is_file($absolutePath)) {
                return null;
            }

            return $absolutePath;
        };

        $getChapterPageDimensions = static function (?string $filePath) use ($getStoredPageAbsolutePath): ?array {
            $absolutePath = $getStoredPageAbsolutePath($filePath);
            if (!$absolutePath) {
                return null;
            }

            $size = @getimagesize($absolutePath);
            if ($size === false || empty($size[0]) || empty($size[1])) {
                return null;
            }

            return [(int) $size[0], (int) $size[1]];
        };

        $getLightNovelPageKind = static function (?string $filePath) use ($allowedExts, $htmlExts, $getStoredPageAbsolutePath): string {
            $ext = strtolower(pathinfo((string) $filePath, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true)) {
                return 'image';
            }

            if (!in_array($ext, $htmlExts, true)) {
                return 'text';
            }

            $absolutePath = $getStoredPageAbsolutePath($filePath);
            if (!$absolutePath) {
                return 'text';
            }

            $html = @file_get_contents($absolutePath, false, null, 0, 524288);
            if ($html === false || trim($html) === '') {
                return 'text';
            }

            $mediaCount = preg_match_all('/<(?:img|svg|image)\b/i', $html);
            $withoutScriptAndStyle = preg_replace('/<(?:script|style)\b[^>]*>.*?<\/(?:script|style)>/is', '', $html) ?? $html;
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($withoutScriptAndStyle), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($mediaCount < 1 && $text === '') {
                return 'blank';
            }

            return $mediaCount > 0 && mb_strlen($text) <= 120 ? 'image' : 'text';
        };

        $spreads = [];
        if ($isLightNovel) {
            $pageKinds = [];
            foreach ($pageObjectsByNumber as $number => $page) {
                $pageKinds[$number] = $getLightNovelPageKind($page->file_path ?? null);
            }
            $nums = array_values(array_filter($nums, fn ($number) => ($pageKinds[$number] ?? 'text') !== 'blank'));

            if ($nums !== [] && !in_array($pageNumber, $nums, true)) {
                $after = collect($nums)->first(fn ($number) => $number > $pageNumber);
                $pageNumber = $after ?? collect($nums)->last();
            }

            for ($i = 0; $i < count($nums);) {
                $currentNum = $nums[$i] ?? null;
                if ($currentNum === null) {
                    break;
                }

                $nextNum = $nums[$i + 1] ?? null;
                if ($nextNum !== null && ($pageKinds[$currentNum] ?? 'text') === ($pageKinds[$nextNum] ?? 'text')) {
                    $spreads[] = ['pages' => [$currentNum, $nextNum]];
                    $i += 2;
                    continue;
                }

                $spreads[] = ['pages' => [$currentNum]];
                $i++;
            }
        } else {
            $pageIsLandscape = [];
            foreach ($pageObjectsByNumber as $number => $page) {
                $dimensions = $getChapterPageDimensions($page->file_path ?? null);
                $pageIsLandscape[$number] = $dimensions ? ($dimensions[0] > $dimensions[1]) : false;
            }

            for ($i = 0; $i < count($nums);) {
                $currentNum = $nums[$i] ?? null;
                if ($currentNum === null) {
                    break;
                }

                if ($pageIsLandscape[$currentNum] ?? false) {
                    $spreads[] = ['pages' => [$currentNum]];
                    $i++;
                    continue;
                }

                $nextNum = $nums[$i + 1] ?? null;
                if ($nextNum !== null && !($pageIsLandscape[$nextNum] ?? false)) {
                    $spreads[] = ['pages' => [$currentNum, $nextNum]];
                    $i += 2;
                    continue;
                }

                $spreads[] = ['pages' => [$currentNum]];
                $i++;
            }
        }

        $currentSpreadIndex = 0;
        foreach ($spreads as $index => $spread) {
            if (in_array($pageNumber, $spread['pages'], true)) {
                $currentSpreadIndex = $index;
                break;
            }
        }

        $currentSpreadPages = $spreads[$currentSpreadIndex]['pages'] ?? [$pageNumber];
        $spreadA = $currentSpreadPages[0] ?? null;
        $spreadB = $currentSpreadPages[1] ?? null;

        // Manga stays right-to-left; light novels read left-to-right.
        if (!$isLightNovel && $spreadA !== null && $spreadB !== null && $spreadA < $spreadB) {
            $leftNum = $spreadB;
            $rightNum = $spreadA;
        } else {
            $leftNum = $spreadA;
            $rightNum = $spreadB;
        }

        $prevSpread = $currentSpreadIndex > 0 ? ($spreads[$currentSpreadIndex - 1] ?? null) : null;
        $nextSpread = ($currentSpreadIndex + 1) < count($spreads) ? ($spreads[$currentSpreadIndex + 1] ?? null) : null;

        $prevPairPage = $prevSpread['pages'][0] ?? null;
        $nextPairPage = $nextSpread['pages'][0] ?? null;

        // Pair links (fallback to controller links if needed)
        $prevPairLink = $prevPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $prevPairPage, 'view' => 'double'])
            : ($prevChapterLink ?? null);

        $nextPairLink = $nextPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $nextPairPage, 'view' => 'double'])
            : ($nextChapterLink ?? null);

        $chapterTitleText = trim((string) ($chapter->chapter_title ?? ''));
        $isVolumeReader = strcasecmp($readerUnitLabel, 'Volume') === 0 || strcasecmp($readerUnitLabel, 'Vol.') === 0;
        $usesTitleChapterDisplay = preg_match('/^\d+(?:\.\d+)?\s*&\s*\d+(?:\.\d+)?$/', $chapterTitleText) === 1
            || strcasecmp($chapterTitleText, 'Extra '.$readerUnitLabel) === 0
            || ($isVolumeReader && strcasecmp($chapterTitleText, 'Extra Volume') === 0)
            || ($readerUnitLabel !== 'Chapter' && strcasecmp($chapterTitleText, 'Extra Chapter') === 0);
        $chapterDisplay = $usesTitleChapterDisplay
            ? $chapterTitleText
            : rtrim(rtrim((string)$chapter->chapter_number, '0'), '.');
        if ($readerUnitLabel !== 'Chapter' && strcasecmp($chapterDisplay, 'Extra Chapter') === 0) {
            $chapterDisplay = 'Extra Volume';
        }
        if ($isVolumeReader && preg_match('/^Vol\.?\s*([0-9]+(?:\.\d+)?)$/i', $chapterDisplay, $matches)) {
            $chapterDisplay = $matches[1];
        }
        $chapterDisplayAlreadyLabeled = preg_match('/^'.preg_quote($readerUnitLabel, '/').'\b/i', $chapterDisplay) === 1;
        $readerUnitSeparator = str_ends_with($readerUnitLabel, '.') ? '' : ' ';
        $readerUnitHeading = $chapterDisplayAlreadyLabeled
            || preg_match('/^Extra\b/i', $chapterDisplay) === 1
            ? $chapterDisplay
            : $readerUnitLabel.$readerUnitSeparator.$chapterDisplay;
        $readerTitleWithChapter = $itemTitle.' - '.$readerUnitHeading;
        $readerImageExpiresAt = now()->addHours($view === 'scroll' ? 8 : 2);
    @endphp

    <style>
        body.reader-mode {
            background: #ffffff;
            color: #111827;
        }
        body.reader-mode.reader-fixed {
            overflow: hidden;
        }
        body.reader-mode nav,
        body.reader-mode footer {
            display: none !important;
        }
        body.reader-mode,
        body.reader-mode .reader-shell,
        body.reader-mode .reader-content,
        body.reader-mode .reader-page,
        body.reader-mode .reader-img {
            -webkit-user-select: none;
            -moz-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        body.reader-mode .reader-img {
            -webkit-user-drag: none;
            user-drag: none;
        }
        body.reader-mode.reader-cursor-hidden,
        body.reader-mode.reader-cursor-hidden * {
            cursor: none !important;
        }
        .reader-hover-zone {
            position: fixed;
            inset: 0 0 auto 0;
            height: 220px; /* even larger trigger so it shows when you're near the top */
            z-index: 55;
            pointer-events: auto;
        }
        .reader-bottom-hover {
            position: fixed;
            inset: auto 0 0 0;
            height: 160px;
            z-index: 55;
            pointer-events: auto;
        }
        .reader-float-nav {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 60;
            pointer-events: none;
        }
        .reader-nav-panel {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 0;
            background: #ab2328; /* tailwind flatRed */
            box-shadow: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.18s ease, transform 0.18s ease;
            pointer-events: none;
        }
        .reader-nav-inner {
            width: 100%;
            margin: 0 auto;
            padding: 1rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reader-hover-zone:hover + .reader-float-nav .reader-nav-panel,
        .reader-float-nav:hover .reader-nav-panel {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .reader-bottom-hover:hover + .reader-bottom-float .reader-bottom-panel,
        .reader-bottom-float:hover .reader-bottom-panel {
            opacity: 1;
            transform: translate(-50%, 0);
            pointer-events: auto;
        }
        body.reader-bars-visible .reader-nav-panel {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        body.reader-bars-visible .reader-bottom-panel {
            opacity: 1;
            transform: translate(-50%, 0);
            pointer-events: auto;
        }
        .reader-logo {
            font-family: 'Fruity Kiwi', Roboto, Arial, sans-serif;
            font-weight: 400;
            letter-spacing: 0;
            text-transform: none;
            color: #ffffff;
            font-size: 1.875rem;
            line-height: 1;
            padding-left: 0;
            padding-right: 0;
        }
        .reader-title-group,
        .reader-title-copy {
            display: flex;
            align-items: center;
        }
        .reader-title-copy {
            min-height: 1.875rem;
            transform: translateY(-3px);
        }
        .reader-title {
            font-size: 1.125rem;
            font-weight: 700;
            line-height: 1;
            color: #ffffff;
            text-decoration: none;
        }
        .reader-title:hover {
            text-decoration: underline;
        }
        .reader-title-chapter {
            font-size: 1rem;
            font-weight: 400;
            line-height: 1;
            color: rgba(255, 255, 255, 0.68);
            margin-left: 0.6rem;
        }
        .reader-shell {
            min-height: 100vh;
            padding: 0;
        }
        .reader-shell-fixed {
            min-height: 100dvh;
            height: 100dvh;
            overflow: hidden;
        }
        .reader-content {
            width: min(1200px, calc(100vw - 24px));
            margin: 0 auto;
        }
        .reader-content-fixed {
            width: 100vw;
            max-width: none;
            height: 100dvh;
            margin: 0;
        }
        .reader-content-fixed.space-y-6 > :not([hidden]) ~ :not([hidden]) {
            margin-top: 0;
        }
        .reader-scroll-stack {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .reader-lazy-page {
            min-height: min(100vh, 1200px);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: #f8fafc;
        }
        .reader-lazy-page::before {
            content: "";
            width: 28px;
            height: 28px;
            border: 3px solid #e5e7eb;
            border-top-color: #ab2328;
            border-radius: 9999px;
            animation: reader-lazy-spin 0.9s linear infinite;
        }
        .reader-lazy-page.reader-lazy-pending > img {
            display: none;
        }
        .reader-lazy-page.reader-lazy-error::before {
            content: "";
            width: 64px;
            height: 48px;
            border: 2px solid #94a3b8;
            border-top-color: #94a3b8;
            border-radius: 6px;
            animation: none;
            background:
                radial-gradient(circle at 74% 26%, #94a3b8 0 4px, transparent 5px),
                linear-gradient(135deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) left 9px bottom 10px / 30px 22px no-repeat,
                linear-gradient(45deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) right 10px bottom 10px / 30px 20px no-repeat;
        }
        .reader-lazy-page.reader-lazy-error::after {
            content: "Image could not be loaded";
            position: absolute;
            left: 50%;
            top: calc(50% + 48px);
            color: #64748b;
            font-size: 0.95rem;
            font-weight: 500;
            transform: translateX(-50%);
            white-space: nowrap;
        }
        .reader-lazy-page:not(.reader-lazy-pending) {
            min-height: 0;
            background: transparent;
        }
        .reader-lazy-page:not(.reader-lazy-pending)::before {
            display: none;
        }
        .reader-image-error {
            min-height: min(100vh, 1200px);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: #f8fafc;
        }
        .reader-image-error > img,
        .reader-image-error .dual-page {
            display: none;
        }
        .reader-image-error::before {
            content: "";
            width: 64px;
            height: 48px;
            border: 2px solid #94a3b8;
            border-radius: 6px;
            background:
                radial-gradient(circle at 74% 26%, #94a3b8 0 4px, transparent 5px),
                linear-gradient(135deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) left 9px bottom 10px / 30px 22px no-repeat,
                linear-gradient(45deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) right 10px bottom 10px / 30px 20px no-repeat;
        }
        .reader-image-error::after {
            content: "Image could not be loaded";
            position: absolute;
            left: 50%;
            top: calc(50% + 48px);
            color: #64748b;
            font-size: 0.95rem;
            font-weight: 500;
            transform: translateX(-50%);
            white-space: nowrap;
        }
        @keyframes reader-lazy-spin {
            to {
                transform: rotate(360deg);
            }
        }
        .reader-controls {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem; /* keep control groups near each other */
            margin-bottom: 1rem;
        }
        .control-group {
            display: flex;
            align-items: center;
            gap: 0.3rem; /* tighter button spacing */
        }
        .control-btn {
            padding: 0.35rem;
            border-radius: 9999px;
            border: none;
            background: transparent;
            color: #ffffff;
            transition: opacity 0.12s ease; /* remove hover lift animation */
            opacity: 0.75;
            line-height: 0; /* prevent icon height from affecting navbar */
        }
        .control-btn svg {
            width: 22px;  /* small box */
            height: 22px;
            stroke-width: 2.2; /* slightly slimmer lines */
            transform: scale(1.8); /* visually larger without changing layout height */
            transform-origin: center;
        }
        .control-btn:hover {
            transform: none;
            opacity: 1;
        }
        .control-btn.active {
            color: #ffffff;
            opacity: 1;
        }
        .reader-fullscreen-btn {
            margin-right: 0;
        }
        .reader-bookmark-form {
            display: flex;
            margin: 0;
        }
        .reader-nav-inner .reader-bookmark-form {
            margin-left: 1.35rem !important;
        }
        .reader-bookmark-btn.reader-bookmark-saved {
            color: #ffffff;
            opacity: 1;
        }
        .reader-fullscreen-btn [data-fullscreen-exit] {
            display: none;
        }
        .reader-fullscreen-btn.is-fullscreen [data-fullscreen-enter] {
            display: none;
        }
        .reader-fullscreen-btn.is-fullscreen [data-fullscreen-exit] {
            display: block;
        }
        .reader-page {
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        .reader-full {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .reader-content-fixed .reader-full {
            min-height: 100dvh;
            height: 100dvh;
            width: 100vw;
        }
        .reader-img {
            width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            box-shadow: none;
            border-radius: 0;
        }
        .reader-full .reader-img {
            height: 100vh;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }
        .reader-content-fixed .reader-full .reader-img {
            height: 100dvh;
            max-height: 100dvh;
            max-width: 100vw;
        }
        .dual-page {
            display: flex;
            justify-content: center;
            gap: 0;
            align-items: center;
            flex-wrap: nowrap;
            width: 100%;
            max-width: 100%;
        }
        .dual-full {
            height: 100vh;
            align-items: center;
            width: 100vw;
            max-width: 100vw;
        }
        .reader-content-fixed .dual-full {
            height: 100dvh;
            width: 100vw;
            max-width: 100vw;
        }
        .dual-page > div {
            flex: 0 0 50%;
            width: 50%;
            max-width: 50%;
        }
        .reader-content-fixed .dual-page > div {
            flex-basis: 50vw;
            width: 50vw;
            max-width: 50vw;
            height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .dual-page img {
            width: 100%;
            height: auto;
        }
        .dual-full img {
            height: 100vh;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }
        .reader-content-fixed .dual-full img {
            height: 100dvh;
            max-height: 100dvh;
            max-width: 50vw;
        }
        .reader-epub-frame {
            display: block;
            width: 100%;
            height: 100vh;
            min-height: 100vh;
            border: 0;
            background: #ffffff;
            overflow: hidden;
        }
        .reader-content-fixed .reader-epub-frame {
            height: 100dvh;
            min-height: 100dvh;
        }
        .reader-epub-pane-wrap {
            background: #ffffff;
            box-shadow: none;
        }
        .reader-content-fixed .dual-page > div.reader-epub-pane-wrap {
            align-items: stretch;
        }
        .reader-epub-debug-badge {
            position: absolute;
            top: 0.5rem;
            left: 0.5rem;
            z-index: 40;
            max-width: min(26rem, calc(100% - 1rem));
            padding: 0.35rem 0.55rem;
            border-radius: 4px;
            background: rgba(18, 120, 84, 0.92);
            color: #ffffff;
            font-size: 0.75rem;
            line-height: 1.25;
            pointer-events: none;
        }
        .reader-epub-debug-badge.is-warning {
            background: rgba(178, 65, 65, 0.94);
        }
        .reader-bottom-panel {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translate(-50%, 24px);
            padding: 0;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            opacity: 0;
            transition: opacity 0.18s ease, transform 0.18s ease;
            pointer-events: none;
            z-index: 60;
        }
        .reader-bottom-inner {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
        }
        .reader-bottom-dock {
            display: flex;
            align-items: center;
            justify-content: center;
            filter: drop-shadow(0 12px 24px rgba(0, 0, 0, 0.25));
        }
        .reader-arrow-square {
            width: 58px;
            height: 60px;
            background: #ab2328;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.55rem;
            font-weight: 400;
            line-height: 1;
            transition: none;
            border: none;
            position: relative;
            z-index: 1;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }
        .reader-arrow-icon {
            width: 33px;
            height: 33px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .reader-arrow-icon.is-right {
            transform: scaleX(-1);
            transform-origin: center;
        }
        .reader-arrow-square.left {
            border-radius: 10px 0 0 10px;
            border-right: none;
        }
        .reader-arrow-square.right {
            border-radius: 0 10px 10px 0;
            border-left: none;
        }
        .reader-arrow-square:not(.disabled):hover,
        .reader-arrow-square:not(.disabled):active,
        .reader-arrow-square:not(.disabled):focus,
        .reader-arrow-square:not(.disabled):focus-visible {
            background: #ab2328;
            color: #ffffff;
            text-decoration: none;
            box-shadow: none;
            outline: none;
        }
        .reader-arrow-square.disabled {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .reader-count-square {
            min-width: 138px;
            width: max-content;
            height: 88px;
            background: #ab2328;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.85rem 0.25rem;
            line-height: 1;
            flex: 0 0 auto;
            /* Outside side shading to visually separate center block from arrow blocks */
            box-shadow: -8px 0 10px -8px rgba(0, 0, 0, 0.38),
                        8px 0 10px -8px rgba(0, 0, 0, 0.38);
            position: relative;
            z-index: 2;
        }
        .reader-count-label {
            font-size: 0.64rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.9;
            margin-bottom: 0.1rem;
            transform: translateY(-0.42rem);
        }
        .reader-count-value {
            font-size: 2.35rem;
            font-weight: 400;
            letter-spacing: -0.02em;
            white-space: nowrap;
        }
        .reader-count-pair {
            display: inline-flex;
            align-items: baseline;
            gap: 0.92rem;
            font-size: 2.35rem;
            font-weight: 400;
            letter-spacing: -0.02em;
            white-space: nowrap;
        }
        .reader-count-sep {
            display: inline-block;
            transform: translateY(-0.14em);
            font-size: 1em;
            line-height: 1;
        }
        .reader-count-sub {
            margin-top: 0.2rem;
            font-size: 0.56rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.9;
        }
        .reader-info-icon {
            width: 44px;
            height: 44px;
            color: #ffffff;
            stroke-width: 2.35;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.body.classList.add('reader-mode');
            if (@json($isFixedView)) {
                document.body.classList.add('reader-fixed');
            }
        });
    </script>

    <div class="reader-hover-zone"></div>
    <div class="reader-float-nav">
        <div class="reader-nav-panel">
            <div class="reader-nav-inner">
                <div class="reader-title-group space-x-2">
                    <a href="{{ route('home') }}" class="reader-logo navbar-logo">DORDIELIST!</a>
                    <div class="reader-title-copy">
                        <a href="{{ $itemUrl }}" class="reader-title" title="{{ $itemTitle }}">
                            {{ shortTitle($itemTitle, 40) }}
                        </a>
                        <span class="reader-title-chapter">{{ $readerUnitHeading }}</span>
                    </div>
                </div>
                <div class="flex items-center space-x-2 text-white text-sm">
                    @if(!$isManhwa && !$isLightNovel)
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'scroll'])) }}"
                           class="control-btn {{ $view === 'scroll' ? 'active' : '' }}"
                           aria-label="Scroll view">
                            <span class="sr-only">Scroll</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="butt" stroke-linejoin="miter">
                                <path d="M7 4v5M7 9h10M17 4v5M7 20v-5M7 15h10M17 20v-5" />
                            </svg>
                        </a>
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'one'])) }}"
                           class="control-btn {{ $view === 'one' ? 'active' : '' }}"
                           aria-label="Single page view">
                            <span class="sr-only">One</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="6" y="4" width="12" height="16" rx="2" />
                            </svg>
                        </a>
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'double'])) }}"
                           class="control-btn {{ $view === 'double' ? 'active' : '' }}"
                           aria-label="Double page view">
                            <span class="sr-only">Double</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 6v12" />
                                <path d="M12 7c-2-1.4-4.5-2-7-2v12c2.5 0 5 0.6 7 2" />
                                <path d="M12 7c2-1.4 4.5-2 7-2v12c-2.5 0-5 0.6-7 2" />
                            </svg>
                        </a>
                    @endif
                    @if(!$isManhwa)
                        <form
                            method="POST"
                            action="{{ $bookmarkRoute }}"
                            class="reader-bookmark-form"
                            data-reader-bookmark-form
                        >
                            @csrf
                            <input type="hidden" name="page_number" value="{{ $pageNumber }}">
                            <input type="hidden" name="view" value="{{ $view }}">
                            <button
                                type="submit"
                                class="control-btn reader-bookmark-btn {{ $isCurrentPageBookmarked ? 'active reader-bookmark-saved' : '' }}"
                                data-reader-bookmark-button
                                aria-label="{{ $isCurrentPageBookmarked ? 'Remove bookmark' : 'Bookmark this page' }}"
                                title="{{ $isCurrentPageBookmarked ? 'Remove bookmark' : 'Bookmark this page' }}"
                            >
                                <span class="sr-only">{{ $isCurrentPageBookmarked ? 'Remove bookmark' : 'Bookmark this page' }}</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="{{ $isCurrentPageBookmarked ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M6 4.75A1.75 1.75 0 0 1 7.75 3h8.5A1.75 1.75 0 0 1 18 4.75V21l-6-3.5L6 21V4.75Z" />
                                </svg>
                            </button>
                        </form>
                    @endif
                    <button type="button"
                            class="control-btn reader-fullscreen-btn"
                            data-fullscreen-toggle
                            aria-label="Enter fullscreen"
                            title="Fullscreen">
                        <span class="sr-only">Fullscreen</span>
                        <svg data-fullscreen-enter xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 3H3v5" />
                            <path d="M16 3h5v5" />
                            <path d="M21 16v5h-5" />
                            <path d="M3 16v5h5" />
                        </svg>
                        <svg data-fullscreen-exit xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 3v6H3" />
                            <path d="M15 3v6h6" />
                            <path d="M15 21v-6h6" />
                            <path d="M9 21v-6H3" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        data-reader-root
        data-reader-kind="chapter"
        data-reader-fixed="{{ $isFixedView ? '1' : '0' }}"
        data-reader-view="{{ $view }}"
        data-reader-is-manhwa="{{ $isManhwa ? '1' : '0' }}"
        data-reader-is-light-novel="{{ $isLightNovel ? '1' : '0' }}"
        data-reader-next-page="{{ $nextLink ?? '' }}"
        data-reader-prev-page="{{ $prevLink ?? '' }}"
        data-reader-next-pair="{{ $nextPairLink ?? '' }}"
        data-reader-prev-pair="{{ $prevPairLink ?? '' }}"
        class="reader-shell {{ $isFixedView ? 'reader-shell-fixed' : '' }}"
    >
        <div class="reader-content space-y-6 {{ $isFixedView ? 'reader-content-fixed' : '' }}">
            {{-- ============== SCROLL MODE ============== --}}
            @if($view === 'scroll')
                <div class="reader-scroll-stack">
                    @foreach($chapter->pages as $p)
                        @php
                            $ext = strtolower(pathinfo($p->file_path, PATHINFO_EXTENSION) ?? '');
                            $isImage = in_array($ext, $allowedExts, true);
                            $url = $isImage
                                ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $p->id])
                                : null;
                        @endphp

                        @if($isImage)
                            <div class="reader-page reader-lazy-page reader-lazy-pending" data-reader-lazy-frame>
                                <img
                                    data-reader-lazy
                                    data-src="{{ $url }}"
                                    alt="Page {{ $p->page_number }}"
                                    class="zoomable reader-img"
                                    loading="lazy"
                                    decoding="async"
                                    draggable="false"
                                >
                                <noscript>
                                    <img
                                        src="{{ $url }}"
                                        alt="Page {{ $p->page_number }}"
                                        class="reader-img"
                                        draggable="false"
                                    >
                                </noscript>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- ============== ONE PAGE MODE ============== --}}
            @elseif($view === 'one' && !$isManhwa)
                @php
                    $current   = $chapter->pages->firstWhere('page_number', $pageNumber);
                    $extOne    = strtolower(pathinfo(optional($current)->file_path ?? '', PATHINFO_EXTENSION));
                    $isImage   = in_array($extOne, $allowedExts, true);
                    $isHtml    = in_array($extOne, $htmlExts, true);
                    $singleUrl = ($isImage || $isHtml) ? $pageUrl : null; // from controller
                @endphp

                @if($isImage || $isHtml)
                    <div class="reader-page reader-full">
                        @if($isHtml)
                            <iframe
                                src="{{ $singleUrl }}"
                                title="Page {{ $pageNumber }}"
                                class="reader-epub-frame"
                                sandbox="allow-same-origin"
                                scrolling="no"
                            ></iframe>
                        @else
                            <img
                                src="{{ $singleUrl }}"
                                alt="Page {{ $pageNumber }}"
                                class="zoomable reader-img z-10"
                                draggable="false"
                            >
                        @endif

                        {{-- Half-screen click zones: LEFT = NEXT, RIGHT = PREVIOUS --}}
                        @if($nextLink)
                            <button
                                type="button"
                                class="absolute inset-y-0 left-0 w-1/2 z-20 border-0 bg-transparent p-0"
                                style="cursor:pointer;"
                                data-reader-target="{{ $nextLink }}"
                                aria-label="Next page"
                            ></button>
                        @endif
                        @if($prevLink)
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 w-1/2 z-20 border-0 bg-transparent p-0"
                                style="cursor:pointer;"
                                data-reader-target="{{ $prevLink }}"
                                aria-label="Previous page"
                            ></button>
                        @endif
                    </div>
                @endif

                {{-- ============== DOUBLE PAGE MODE ============== --}}
            @elseif($view === 'double' && !$isManhwa)
                @php
                    $leftObj   = $leftNum  !== null ? $chapter->pages->firstWhere('page_number', $leftNum)  : null;
                    $rightObj  = $rightNum !== null ? $chapter->pages->firstWhere('page_number', $rightNum) : null;

                    $extLeft   = strtolower(pathinfo(optional($leftObj)->file_path ?? '', PATHINFO_EXTENSION));
                    $extRight  = strtolower(pathinfo(optional($rightObj)->file_path ?? '', PATHINFO_EXTENSION));

                    $isLeftImg  = in_array($extLeft,  $allowedExts, true);
                    $isRightImg = in_array($extRight, $allowedExts, true);
                    $isLeftHtml = in_array($extLeft, $htmlExts, true);
                    $isRightHtml = in_array($extRight, $htmlExts, true);
                    $isLeftRenderable = $leftObj && ($isLeftImg || $isLeftHtml);
                    $isRightRenderable = $rightObj && ($isRightImg || $isRightHtml);

                    $leftUrl = $isLeftRenderable
                        ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $leftObj->id])
                        : null;
                    $rightUrl = $isRightRenderable
                        ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $rightObj->id])
                        : null;
                    $hasSingleSpreadPage = ($isLeftRenderable xor $isRightRenderable);
                    $singleSpreadUrl = $isLeftRenderable ? $leftUrl : $rightUrl;
                    $singleSpreadNum = $isLeftRenderable ? $leftNum : $rightNum;
                    $singleSpreadIsHtml = $isLeftRenderable ? $isLeftHtml : $isRightHtml;

                    // Pair-aware targets for this view
                    $doubleNext = $nextPairLink ?? null;
                    $doublePrev = $prevPairLink ?? null;
                    $leftZoneTarget = $isLightNovel ? $doublePrev : $doubleNext;
                    $rightZoneTarget = $isLightNovel ? $doubleNext : $doublePrev;
                    $leftZoneAction = $isLightNovel ? 'Previous pages' : 'Next pages';
                    $rightZoneAction = $isLightNovel ? 'Next pages' : 'Previous pages';

                    $hasDoubleNext = !empty($doubleNext);
                    $hasDoublePrev = !empty($doublePrev);
                    $doubleJustify = $hasDoubleNext && $hasDoublePrev ? 'between' : ($hasDoubleNext ? 'start' : 'end');
                @endphp

                <div class="reader-page reader-full">
                    @if($hasSingleSpreadPage)
                        @if($singleSpreadIsHtml)
                            <iframe
                                src="{{ $singleSpreadUrl }}"
                                title="Page {{ $singleSpreadNum }}"
                                class="reader-epub-frame"
                                sandbox="allow-same-origin"
                                scrolling="no"
                            ></iframe>
                        @else
                            <img
                                src="{{ $singleSpreadUrl }}"
                                alt="Page {{ $singleSpreadNum }}"
                                class="zoomable reader-img z-10"
                                draggable="false"
                            >
                        @endif
                    @else
                        <div class="dual-page dual-full">
                            @if($isLeftRenderable)
                                <div class="relative overflow-hidden {{ $isLeftHtml ? 'reader-epub-pane-wrap' : '' }}">
                                    @if($isLeftHtml)
                                        <iframe
                                            src="{{ $leftUrl }}"
                                            title="Page {{ $leftNum }}"
                                            class="reader-epub-frame"
                                            sandbox="allow-same-origin"
                                            scrolling="no"
                                        ></iframe>
                                    @else
                                        <img
                                            src="{{ $leftUrl }}"
                                            alt="Page {{ $leftNum }}"
                                            class="zoomable reader-img"
                                            draggable="false"
                                        >
                                    @endif
                                </div>
                            @endif

                            @if($isRightRenderable)
                                <div class="relative overflow-hidden {{ $isRightHtml ? 'reader-epub-pane-wrap' : '' }}">
                                    @if($isRightHtml)
                                        <iframe
                                            src="{{ $rightUrl }}"
                                            title="Page {{ $rightNum }}"
                                            class="reader-epub-frame"
                                            sandbox="allow-same-origin"
                                            scrolling="no"
                                        ></iframe>
                                    @else
                                        <img
                                            src="{{ $rightUrl }}"
                                            alt="Page {{ $rightNum }}"
                                            class="zoomable reader-img"
                                            draggable="false"
                                        >
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($leftZoneTarget)
                        <button
                            type="button"
                            class="absolute inset-y-0 left-0 w-1/2 z-20 border-0 bg-transparent p-0"
                            style="cursor:pointer;"
                            data-reader-target="{{ $leftZoneTarget }}"
                            aria-label="{{ $leftZoneAction }}"
                        ></button>
                    @endif
                    @if($rightZoneTarget)
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 w-1/2 z-20 border-0 bg-transparent p-0"
                            style="cursor:pointer;"
                            data-reader-target="{{ $rightZoneTarget }}"
                            aria-label="{{ $rightZoneAction }}"
                        ></button>
                    @endif
                </div>

            @endif

        </div>
    </div>

    @php
        $bottomLeftLink = null;
        $bottomRightLink = null;
        $bottomLeftAction = null;
        $bottomRightAction = null;

        if ($view === 'scroll') {
            $leftBottom  = $nextChapterLink;
            $rightBottom = $prevChapterLink;
            if ($isManhwa) {
                $leftBottom  = $prevChapterLink;
                $rightBottom = $nextChapterLink;
            }
            $bottomLeftLink  = $leftBottom;
            $bottomRightLink = $rightBottom;
            $bottomLeftAction = $isManhwa ? 'prev' : 'next';
            $bottomRightAction = $isManhwa ? 'next' : 'prev';
        } elseif ($view === 'one' && !$isManhwa) {
            $bottomLeftLink  = $nextLink;
            $bottomRightLink = $prevLink;
            $bottomLeftAction = 'next';
            $bottomRightAction = 'prev';
        } elseif ($view === 'double' && !$isManhwa) {
            $doubleNext = $nextPairLink ?? null;
            $doublePrev = $prevPairLink ?? null;
            $bottomLeftLink  = $isLightNovel ? $doublePrev : $doubleNext;
            $bottomRightLink = $isLightNovel ? $doubleNext : $doublePrev;
            $bottomLeftAction = $isLightNovel ? 'prev' : 'next';
            $bottomRightAction = $isLightNovel ? 'next' : 'prev';
        }
        $nextArrowClass = ($isManhwa || $isLightNovel) ? 'is-right' : 'is-left';
        $prevArrowClass = ($isManhwa || $isLightNovel) ? 'is-left' : 'is-right';
        $bottomInfoLink = route('media.show', ['id' => $chapter->item_id]);

        if ($view === 'one' && !$isManhwa) {
            $bottomMainLabel = 'Page';
            $bottomMainValue = (string)$pageNumber;
            $bottomSub = null;
        } elseif ($view === 'double' && !$isManhwa) {
            $bottomMainLabel = 'Page';
            $bottomMainValue = $rightNum ? ($leftNum.' | '.$rightNum) : (string)$leftNum;
            $bottomSub = null;
        } else {
            $bottomMainLabel = $readerUnitLabel;
            $bottomMainValue = $chapterDisplay;
            $bottomSub = null;
        }
    @endphp

    <div class="reader-bottom-hover"></div>
    <div class="reader-bottom-float">
        <div class="reader-bottom-panel">
            <div class="reader-bottom-inner">
                <div class="reader-bottom-dock">
                @if($bottomLeftLink)
                    <a href="{{ $bottomLeftLink }}" class="reader-arrow-square left" aria-label="{{ $bottomLeftAction === 'next' ? 'Next' : 'Previous' }}">
                        <svg class="reader-arrow-icon {{ $bottomLeftAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </a>
                @else
                    <span class="reader-arrow-square left disabled" aria-hidden="true">
                        <svg class="reader-arrow-icon {{ $bottomLeftAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </span>
                @endif

                @if($view === 'scroll')
                    <a href="{{ $bottomInfoLink }}" class="reader-count-square" aria-label="View item info" title="View item info">
                        <svg xmlns="http://www.w3.org/2000/svg" class="reader-info-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8h.01M11 12h1v4h1m-1 5a9 9 0 100-18 9 9 0 000 18z" />
                        </svg>
                    </a>
                @else
                    <div class="reader-count-square">
                        <span class="reader-count-label">{{ $bottomMainLabel }}</span>
                        @if($view === 'double' && !$isManhwa && $rightNum)
                            <span class="reader-count-pair">
                                <span>{{ $leftNum }}</span>
                                <span class="reader-count-sep">|</span>
                                <span>{{ $rightNum }}</span>
                            </span>
                        @else
                            <span class="reader-count-value">{{ $bottomMainValue }}</span>
                        @endif
                        @if($bottomSub)
                            <span class="reader-count-sub">{{ $bottomSub }}</span>
                        @endif
                    </div>
                @endif

                @if($bottomRightLink)
                    <a href="{{ $bottomRightLink }}" class="reader-arrow-square right" aria-label="{{ $bottomRightAction === 'next' ? 'Next' : 'Previous' }}">
                        <svg class="reader-arrow-icon {{ $bottomRightAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </a>
                @else
                    <span class="reader-arrow-square right disabled" aria-hidden="true">
                        <svg class="reader-arrow-icon {{ $bottomRightAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </span>
                @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
            const supportsFullscreen = () => document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen || document.documentElement.msRequestFullscreen;
            const readerRoot = () => document.querySelector('[data-reader-root]');
            const isReaderUrl = (url) => url.origin === window.location.origin
                && url.searchParams.has('view')
                && (/\/chapters\//.test(url.pathname) || /\/page\/\d+/.test(url.pathname));

            let hoverCount = 0;
            let navigating = false;
            let lazyReaderObserver = null;
            let cursorIdleTimer = null;
            const cursorIdleDelay = 1400;
            const cursorHiddenStorageKey = 'readerCursorHidden';

            const syncFullscreenButtons = () => {
                const isFullscreen = Boolean(fullscreenElement());
                document.querySelectorAll('[data-fullscreen-toggle]').forEach((button) => {
                    button.hidden = !supportsFullscreen();
                    button.classList.toggle('is-fullscreen', isFullscreen);
                    button.setAttribute('aria-label', isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen');
                    button.setAttribute('title', isFullscreen ? 'Exit fullscreen' : 'Fullscreen');
                });
            };

            const toggleFullscreen = async () => {
                try {
                    if (fullscreenElement()) {
                        const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
                        if (exitFullscreen) {
                            await exitFullscreen.call(document);
                        }
                    } else {
                        const enterFullscreen = document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen || document.documentElement.msRequestFullscreen;
                        if (enterFullscreen) {
                            await enterFullscreen.call(document.documentElement);
                        }
                    }
                } catch (error) {
                    console.warn('Fullscreen toggle failed', error);
                }
            };

            const setReaderMode = () => {
                const root = readerRoot();
                document.body.classList.add('reader-mode');
                document.body.classList.toggle('reader-fixed', root && root.dataset.readerFixed === '1');
            };

            const supportsPointerCursor = () => window.matchMedia
                ? window.matchMedia('(hover: hover) and (pointer: fine)').matches
                : true;

            const stopReaderCursorIdleTimer = () => {
                if (cursorIdleTimer) {
                    clearTimeout(cursorIdleTimer);
                    cursorIdleTimer = null;
                }
            };

            const setStoredReaderCursorHidden = (isHidden) => {
                try {
                    if (isHidden) {
                        sessionStorage.setItem(cursorHiddenStorageKey, '1');
                    } else {
                        sessionStorage.removeItem(cursorHiddenStorageKey);
                    }
                } catch (error) {
                    // Ignore storage failures; cursor behavior still works for the current page.
                }
            };

            const wasReaderCursorHidden = () => {
                try {
                    return sessionStorage.getItem(cursorHiddenStorageKey) === '1';
                } catch (error) {
                    return false;
                }
            };

            const hideReaderCursor = () => {
                if (!readerRoot() || !supportsPointerCursor()) return;

                document.body.classList.add('reader-cursor-hidden');
                setStoredReaderCursorHidden(true);
            };

            const showReaderCursor = () => {
                stopReaderCursorIdleTimer();
                document.body.classList.remove('reader-cursor-hidden');
                setStoredReaderCursorHidden(false);
            };

            const scheduleReaderCursorIdle = () => {
                stopReaderCursorIdleTimer();

                if (!readerRoot() || !supportsPointerCursor()) return;

                cursorIdleTimer = setTimeout(() => {
                    hideReaderCursor();
                }, cursorIdleDelay);
            };

            const bindReaderCursorIdle = () => {
                if (wasReaderCursorHidden() && supportsPointerCursor()) {
                    document.body.classList.add('reader-cursor-hidden');
                } else {
                    document.body.classList.remove('reader-cursor-hidden');
                }

                if (window.__readerCursorIdleBound) {
                    if (!document.body.classList.contains('reader-cursor-hidden')) {
                        scheduleReaderCursorIdle();
                    }
                    return;
                }

                window.__readerCursorIdleBound = true;

                document.addEventListener('mousemove', () => {
                    if (!readerRoot()) {
                        showReaderCursor();
                        return;
                    }

                    showReaderCursor();
                    scheduleReaderCursorIdle();
                }, { passive: true });

                document.addEventListener('mouseleave', stopReaderCursorIdleTimer);
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) {
                        stopReaderCursorIdleTimer();
                    } else if (readerRoot() && !document.body.classList.contains('reader-cursor-hidden')) {
                        scheduleReaderCursorIdle();
                    }
                });

                if (!document.body.classList.contains('reader-cursor-hidden')) {
                    scheduleReaderCursorIdle();
                }
            };

            const bindHoverBars = () => {
                const updateBars = (delta) => {
                    hoverCount = Math.max(0, hoverCount + delta);
                    document.body.classList.toggle('reader-bars-visible', hoverCount > 0);
                };

                [
                    document.querySelector('.reader-hover-zone'),
                    document.querySelector('.reader-float-nav'),
                    document.querySelector('.reader-bottom-hover'),
                    document.querySelector('.reader-bottom-float'),
                ].filter(Boolean).forEach((el) => {
                    if (el.dataset.readerHoverBound === '1') return;
                    el.dataset.readerHoverBound = '1';
                    el.addEventListener('mouseenter', () => updateBars(1));
                    el.addEventListener('mouseleave', () => updateBars(-1));
                });
            };

            const bindProtectedReader = () => {
                const protectedReader = document.querySelector('.reader-shell');
                if (!protectedReader || protectedReader.dataset.readerProtectedBound === '1') return;
                protectedReader.dataset.readerProtectedBound = '1';

                protectedReader.addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                }, { capture: true });

                protectedReader.addEventListener('dragstart', (e) => {
                    if (e.target instanceof Element && e.target.closest('img')) {
                        e.preventDefault();
                    }
                }, { capture: true });
            };

            const markLazyImageFinished = (img, failed = false) => {
                const frame = img.closest('[data-reader-lazy-frame]');
                if (frame) {
                    if (failed) {
                        frame.classList.add('reader-lazy-error', 'reader-image-error');
                        frame.setAttribute('role', 'img');
                        frame.setAttribute('aria-label', `${img.alt || 'Reader page'} failed to load`);
                    } else {
                        frame.classList.remove('reader-lazy-pending', 'reader-lazy-error', 'reader-image-error');
                        frame.removeAttribute('role');
                        frame.removeAttribute('aria-label');
                    }
                }
            };

            const markReaderImageFailed = (img) => {
                const frame = img.closest('.reader-page') || img.parentElement;
                if (!frame) return;

                frame.classList.add('reader-image-error');
                frame.setAttribute('role', 'img');
                frame.setAttribute('aria-label', `${img.alt || 'Reader page'} failed to load`);
            };

            const epubDebugEnabled = new URLSearchParams(window.location.search).get('epubdebug') === '1';
            const setEpubDebug = (frame, message, ok = true) => {
                if (!epubDebugEnabled || !frame) return;

                const host = frame.closest('.reader-epub-pane-wrap') || frame.closest('.reader-page') || frame.parentElement;
                if (!host) return;

                let badge = host.querySelector(':scope > .reader-epub-debug-badge');
                if (!badge) {
                    badge = document.createElement('div');
                    badge.className = 'reader-epub-debug-badge';
                    host.appendChild(badge);
                }

                badge.classList.toggle('is-warning', !ok);
                badge.textContent = message;
            };

            const epubDocumentNode = (doc, localName) => {
                if (!doc) return null;

                const direct = localName === 'head' ? doc.head : (localName === 'body' ? doc.body : null);
                if (direct) return direct;

                try {
                    const queried = doc.querySelector(localName);
                    if (queried) return queried;
                } catch (error) {
                    // XHTML documents can behave like XML documents depending on MIME type.
                }

                const nodes = doc.getElementsByTagName('*');
                for (const node of nodes) {
                    if ((node.localName || '').toLowerCase() === localName) {
                        return node;
                    }
                }

                return null;
            };

            const epubReaderCss = `
html {
    width: 100% !important;
    min-height: 100% !important;
    margin: 0 !important;
    background: #ffffff !important;
    overflow: hidden !important;
    scrollbar-width: none !important;
}
body {
    width: min(720px, calc(100% - 120px)) !important;
    min-width: 0 !important;
    max-width: 720px !important;
    min-height: 100% !important;
    margin: 82px auto 0 !important;
    padding: 0 0 30px !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    color: #000000 !important;
    font-family: Georgia, "Times New Roman", serif !important;
    font-size: 18px !important;
    line-height: 1.36 !important;
    text-align: left !important;
    overflow: hidden !important;
    overflow-wrap: break-word !important;
    scrollbar-width: none !important;
}
body.reader-image-page {
    min-height: 100vh !important;
    margin: 0 auto !important;
    padding: 84px 0 0 !important;
    display: flex !important;
    align-items: flex-start !important;
    justify-content: center !important;
}
html::-webkit-scrollbar,
body::-webkit-scrollbar {
    display: none !important;
}
body * {
    box-sizing: border-box !important;
    max-width: 100% !important;
}
p {
    margin: 0 0 0.74em !important;
    text-align: left !important;
}
h1,
h2,
h3,
h4,
h5,
h6 {
    margin: 0 0 2rem !important;
    text-align: center !important;
    line-height: 1.25 !important;
}
img,
svg {
    display: block !important;
    max-width: min(76%, 640px) !important;
    max-height: 78vh !important;
    width: auto !important;
    height: auto !important;
    margin: 0 auto !important;
    object-fit: contain !important;
}
a {
    color: #0000ee !important;
    text-decoration: underline !important;
}
`;

            const epubBodyLooksImageOnly = (body) => {
                if (!body) return false;

                const mediaCount = body.querySelectorAll?.('img, svg, image').length || 0;
                if (mediaCount < 1) return false;

                const clone = body.cloneNode(true);
                clone.querySelectorAll?.('script, style').forEach((node) => node.remove());
                const text = (clone.textContent || '').replace(/\s+/g, ' ').trim();

                return text.length <= 120;
            };

            const fitEpubFrame = (frame, doc) => {
                const body = epubDocumentNode(doc, 'body');
                const html = doc.documentElement;
                if (!body || !html) return;

                body.classList.toggle('reader-image-page', epubBodyLooksImageOnly(body));

                const availableHeight = frame.clientHeight || html.clientHeight || window.innerHeight;
                if (!availableHeight) return;

                let fontSize = 18;
                let lineHeight = 1.36;
                const applyMetrics = () => {
                    body.style.setProperty('font-size', `${fontSize.toFixed(2)}px`, 'important');
                    body.style.setProperty('line-height', lineHeight.toFixed(2), 'important');
                };
                const pageHeight = () => Math.max(html.scrollHeight, body.scrollHeight);

                applyMetrics();
                for (let i = 0; i < 18 && pageHeight() > availableHeight + 2; i += 1) {
                    if (fontSize > 16.5) {
                        fontSize -= 0.25;
                    } else if (lineHeight > 1.26) {
                        lineHeight -= 0.02;
                    } else {
                        break;
                    }
                    applyMetrics();
                }

                const computed = doc.defaultView?.getComputedStyle(body);
                setEpubDebug(
                    frame,
                    `EPUB CSS applied: width ${Math.round(body.getBoundingClientRect().width)}px, height ${Math.round(pageHeight())}/${Math.round(availableHeight)}, font ${computed?.fontSize || `${fontSize}px`}, line ${computed?.lineHeight || lineHeight}`,
                    pageHeight() <= availableHeight + 2
                );
            };

            const normalizeEpubFrame = (frame) => {
                if (!frame || frame.dataset.readerEpubBound === '1') return;

                frame.dataset.readerEpubBound = '1';
                const apply = () => {
                    try {
                        const doc = frame.contentDocument;
                        const head = epubDocumentNode(doc, 'head') || doc?.documentElement;
                        const body = epubDocumentNode(doc, 'body');
                        if (!doc || !head || !body) {
                            setEpubDebug(frame, 'EPUB debug: iframe document not ready yet.', false);
                            return;
                        }

                        let style = doc.getElementById('dordielist-epub-reader-style');
                        if (!style) {
                            style = doc.createElementNS(head.namespaceURI || 'http://www.w3.org/1999/xhtml', 'style');
                            style.id = 'dordielist-epub-reader-style';
                            head.appendChild(style);
                        }
                        style.textContent = epubReaderCss;
                        fitEpubFrame(frame, doc);
                        requestAnimationFrame(() => fitEpubFrame(frame, doc));
                        setTimeout(() => fitEpubFrame(frame, doc), 150);
                        if (doc.fonts?.ready) {
                            doc.fonts.ready.then(() => fitEpubFrame(frame, doc));
                        }
                        Array.from(doc.querySelectorAll?.('img, image') || []).forEach((img) => {
                            if (!img.complete) {
                                img.addEventListener('load', () => fitEpubFrame(frame, doc), { once: true });
                            }
                        });
                    } catch (error) {
                        setEpubDebug(frame, `EPUB debug: iframe styling blocked (${error.message || 'unknown error'}).`, false);
                        // If a browser refuses iframe access, the generated page CSS still applies.
                    }
                };

                frame.addEventListener('load', apply);
                apply();
            };

            const initEpubFrames = () => {
                document.querySelectorAll('iframe.reader-epub-frame').forEach(normalizeEpubFrame);
            };

            const initReaderImageErrors = () => {
                document.querySelectorAll('img.reader-img:not([data-reader-lazy])').forEach((img) => {
                    if (img.dataset.readerErrorBound === '1') return;

                    img.dataset.readerErrorBound = '1';
                    img.addEventListener('error', () => markReaderImageFailed(img), { once: true });

                    if (img.complete && !img.naturalWidth) {
                        markReaderImageFailed(img);
                    }
                });
            };

            const bindReaderBookmarkForms = () => {
                document.querySelectorAll('[data-reader-bookmark-form]').forEach((form) => {
                    if (form.dataset.readerBookmarkBound === '1') return;
                    form.dataset.readerBookmarkBound = '1';

                    form.addEventListener('submit', async (e) => {
                        e.preventDefault();

                        const button = form.querySelector('[data-reader-bookmark-button]');
                        if (button) button.disabled = true;

                        try {
                            const response = await fetch(form.action, {
                                method: 'POST',
                                body: new FormData(form),
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            if (!response.ok) {
                                throw new Error(`Bookmark failed: ${response.status}`);
                            }

                            const result = await response.json().catch(() => ({ bookmarked: true }));
                            const isBookmarked = result.bookmarked !== false;

                            if (button) {
                                button.classList.toggle('active', isBookmarked);
                                button.classList.toggle('reader-bookmark-saved', isBookmarked);
                                button.setAttribute('aria-label', isBookmarked ? 'Remove bookmark' : 'Bookmark this page');
                                button.setAttribute('title', isBookmarked ? 'Remove bookmark' : 'Bookmark this page');
                                const label = button.querySelector('.sr-only');
                                if (label) label.textContent = isBookmarked ? 'Remove bookmark' : 'Bookmark this page';
                                const icon = button.querySelector('svg');
                                if (icon) icon.setAttribute('fill', isBookmarked ? 'currentColor' : 'none');
                            }
                        } catch (error) {
                            console.warn('Reader bookmark request fell back to a page submit', error);
                            form.submit();
                        } finally {
                            if (button) button.disabled = false;
                        }
                    });
                });
            };

            const loadLazyReaderImage = (img) => {
                if (!img || img.dataset.readerLazyLoaded === '1') return;

                const src = img.dataset.src;
                if (!src) return;

                img.dataset.readerLazyLoaded = '1';
                img.src = src;
                img.removeAttribute('data-src');
                img.loading = 'eager';
            };

            const initLazyReaderImages = () => {
                const lazyImages = Array.from(document.querySelectorAll('img[data-reader-lazy][data-src]'));

                if (lazyReaderObserver) {
                    lazyReaderObserver.disconnect();
                    lazyReaderObserver = null;
                }

                if (!lazyImages.length) return;

                lazyImages.forEach((img) => {
                    if (img.dataset.readerLazyEventsBound === '1') return;

                    img.dataset.readerLazyEventsBound = '1';
                    img.addEventListener('load', () => markLazyImageFinished(img), { once: true });
                    img.addEventListener('error', () => markLazyImageFinished(img, true), { once: true });
                });

                if (!('IntersectionObserver' in window)) {
                    lazyImages.forEach(loadLazyReaderImage);
                    return;
                }

                lazyReaderObserver = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) return;

                        const img = entry.target.matches('img')
                            ? entry.target
                            : entry.target.querySelector('img[data-reader-lazy][data-src]');

                        loadLazyReaderImage(img);
                        lazyReaderObserver.unobserve(entry.target);
                    });
                }, {
                    rootMargin: '1200px 0px',
                    threshold: 0.01,
                });

                lazyImages.forEach((img) => {
                    lazyReaderObserver.observe(img.closest('[data-reader-lazy-frame]') || img);
                });
            };

            const initReaderPage = () => {
                hoverCount = 0;
                setReaderMode();
                bindHoverBars();
                bindReaderCursorIdle();
                bindProtectedReader();
                bindReaderBookmarkForms();
                syncFullscreenButtons();
                initLazyReaderImages();
                initReaderImageErrors();
                initEpubFrames();
            };

            const navigateReader = async (targetUrl) => {
                if (!fullscreenElement()) {
                    window.location.replace(targetUrl);
                    return;
                }

                if (navigating) return;
                navigating = true;

                try {
                    const response = await fetch(targetUrl, {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });

                    if (!response.ok) {
                        throw new Error(`Reader navigation failed: ${response.status}`);
                    }

                    const html = await response.text();
                    const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                    if (!nextDocument.querySelector('[data-reader-root]')) {
                        throw new Error('Response was not a reader page.');
                    }

                    document.title = nextDocument.title;
                    document.body.className = nextDocument.body.className;
                    document.body.innerHTML = nextDocument.body.innerHTML;
                    history.replaceState(null, '', response.url || targetUrl);
                    window.scrollTo(0, 0);
                    initReaderPage();
                } catch (error) {
                    console.warn('Reader fullscreen navigation fell back to a page load', error);
                    window.location.replace(targetUrl);
                } finally {
                    navigating = false;
                }
            };

            const getChapterArrowTarget = (key) => {
                const root = readerRoot();
                if (!root || root.dataset.readerKind !== 'chapter') return null;

                const isDouble = root.dataset.readerView === 'double';
                const isManhwa = root.dataset.readerIsManhwa === '1';
                const isLightNovel = root.dataset.readerIsLightNovel === '1';
                const nextPage = root.dataset.readerNextPage || '';
                const prevPage = root.dataset.readerPrevPage || '';
                const nextPair = root.dataset.readerNextPair || '';
                const prevPair = root.dataset.readerPrevPair || '';

                let leftTarget = isDouble ? (nextPair || nextPage) : nextPage;
                let rightTarget = isDouble ? (prevPair || prevPage) : prevPage;

                if (isManhwa || isLightNovel) {
                    const tmp = leftTarget;
                    leftTarget = rightTarget;
                    rightTarget = tmp;
                }

                if (key === 'ArrowLeft') return leftTarget;
                if (key === 'ArrowRight') return rightTarget;
                return null;
            };

            if (!window.__readerGlobalEventsBound) {
                window.__readerGlobalEventsBound = true;

                document.addEventListener('fullscreenchange', syncFullscreenButtons);
                document.addEventListener('webkitfullscreenchange', syncFullscreenButtons);
                document.addEventListener('MSFullscreenChange', syncFullscreenButtons);

                document.addEventListener('contextmenu', (e) => {
                    if (!readerRoot()) return;

                    e.preventDefault();
                    e.stopPropagation();
                }, { capture: true });

                document.addEventListener('dragstart', (e) => {
                    if (!readerRoot()) return;

                    if (e.target instanceof Element && e.target.closest('img')) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }, { capture: true });

                document.addEventListener('click', (e) => {
                    const fullscreenButton = e.target.closest('[data-fullscreen-toggle]');
                    if (fullscreenButton) {
                        e.preventDefault();
                        toggleFullscreen();
                        return;
                    }

                    const readerTarget = e.target.closest('[data-reader-target]');
                    if (readerTarget) {
                        const targetUrl = readerTarget.dataset.readerTarget;
                        if (!targetUrl) return;

                        e.preventDefault();
                        navigateReader(targetUrl);
                        return;
                    }

                    const link = e.target.closest('a[href]');
                    if (!link) return;
                    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                    if (link.target && link.target !== '_self') return;

                    let url;
                    try {
                        url = new URL(link.href, window.location.origin);
                    } catch (err) {
                        return;
                    }

                    if (!isReaderUrl(url)) return;

                    e.preventDefault();
                    navigateReader(url.toString());
                });

                document.addEventListener('keydown', (e) => {
                    const key = e.key.toLowerCase();
                    const commandKey = e.ctrlKey || e.metaKey;

                    if (
                        key === 'f12'
                        || key === 'contextmenu'
                        || (e.shiftKey && key === 'f10')
                        || (commandKey && ['s', 'u', 'p'].includes(key))
                        || (commandKey && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key))
                    ) {
                        e.preventDefault();
                        e.stopPropagation();
                        return;
                    }

                    const tag = (document.activeElement && document.activeElement.tagName) || '';
                    if (tag === 'INPUT' || tag === 'TEXTAREA') return;

                    const target = getChapterArrowTarget(e.key);
                    if (!target) return;

                    e.preventDefault();
                    navigateReader(target);
                });
            }

            window.readerInitPage = initReaderPage;
            window.readerNavigate = navigateReader;

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initReaderPage, { once: true });
            } else {
                initReaderPage();
            }
        })();
    </script>
@endsection
