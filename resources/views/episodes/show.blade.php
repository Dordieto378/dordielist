{{-- resources/views/episodes/show.blade.php --}}
@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;

        $title = $item['title']['english'] ?? $item['title']['romaji'] ?? $item['title']['native'] ?? 'No Title';
        $episodeNumber = $episode->episode_number;
        $mediaUrl = route('media.show', $item['id']);

        $src = asset('storage/'.$episode->file_path);
        $releaseDate = !empty($item['releaseDate'])
            ? \Carbon\Carbon::parse($item['releaseDate'])->format('M j, Y')
            : (!empty($item['startDate']['year']) ? (string) $item['startDate']['year'] : null);
        $description = (string) ($item['description'] ?? '');
        $visibleEpisodes = ($episodes ?? collect())->values();
        $currentEpisodeIndex = $visibleEpisodes->search(
            fn ($ep) => (int) $ep->episode_number === (int) $episodeNumber
        );
        $nextEpisode = $currentEpisodeIndex !== false
            ? $visibleEpisodes->get($currentEpisodeIndex + 1)
            : null;
        $previousEpisode = $currentEpisodeIndex !== false && $currentEpisodeIndex > 0
            ? $visibleEpisodes->get($currentEpisodeIndex - 1)
            : null;
        $subtitlePath = preg_replace('/\.[^.\/\\\\]+\z/', '.vtt', (string) $episode->file_path);
        $subtitleExists = $subtitlePath && file_exists(public_path('storage/'.$subtitlePath));
        $topSubtitlePath = preg_replace('/\.[^.\/\\\\]+\z/', '.top.vtt', (string) $episode->file_path);
        $topSubtitleExists = $topSubtitlePath && file_exists(public_path('storage/'.$topSubtitlePath));
    @endphp

    <div class="w-full bg-black flex justify-center min-h-[70vh] relative">
        <a href="{{ url()->previous() }}"
           class="absolute top-4 right-4 text-white text-3xl hover:text-gray-400 z-20">&times;</a>

        <div class="episode-player-frame w-[1710px] max-w-full mx-auto mt-8 px-4">
            <video id="player"
                   class="w-full h-auto aspect-video object-contain bg-black"
                   playsinline controls preload="metadata" disablepictureinpicture
                   @if($subtitleExists) data-subtitles-src="{{ asset('storage/'.$subtitlePath) }}" @endif
                   @if($topSubtitleExists) data-top-subtitles-src="{{ asset('storage/'.$topSubtitlePath) }}" @endif>
                <source src="{{ $src }}">
                Your browser doesn’t support HTML5 video.
            </video>
        </div>
    </div>

    <div class="w-full flex justify-center mt-6 mb-6 px-4">
        <div style="max-width:1280px;width:100%;margin:0 auto;display:flex;align-items:flex-start;gap:20px;">
            <div style="flex:1 1 auto;min-width:0;" class="rounded-lg overflow-hidden">
                <div class="px-1 py-2 text-gray-900 font-medium">
                    <h1 class="text-xl font-semibold text-red-600 leading-tight">
                        <a class="hover:underline" href="{{ $mediaUrl }}">{{ $title }}</a>
                    </h1>
                    <div class="mt-1 text-lg font-semibold text-black">
                        Episode {{ $episodeNumber }}
                    </div>
                    @if($releaseDate)
                        <div class="mt-1 text-sm font-normal text-black">
                            Released on {{ $releaseDate }}
                        </div>
                    @endif
                    @if($description !== '')
                        <p class="text-sm mb-2 mt-2">
                            {!! nl2br(e($description)) !!}
                        </p>
                    @endif
                </div>
            </div>

            @if($nextEpisode || $previousEpisode)
                <aside style="width:320px;flex:0 0 320px;">
                    <div class="space-y-5 pr-1">
                        @if($nextEpisode)
                            @php
                                $nextThumbUrl = !empty($nextEpisode->thumbnail_path)
                                    ? Storage::url($nextEpisode->thumbnail_path)
                                    : asset('images/no-image.jpg');
                                $nextPreviewUrl = !empty($nextEpisode->file_path)
                                    ? asset('storage/'.$nextEpisode->file_path)
                                    : null;
                            @endphp
                            <div>
                                <h2 class="text-lg font-bold text-black mb-2">NEXT EPISODE</h2>
                                <a href="{{ route('episodes.show', ['media' => $item['id'], 'episode' => $nextEpisode->episode_number]) }}"
                                   class="episode-preview-card rounded-sm hover:opacity-90 transition"
                                   aria-label="Next episode, Episode {{ $nextEpisode->episode_number }}">
                                    <span class="episode-preview-media">
                                        <img
                                            src="{{ $nextThumbUrl }}"
                                            alt="Episode {{ $nextEpisode->episode_number }} thumbnail"
                                            loading="lazy"
                                        >
                                        @if($nextPreviewUrl)
                                            <video
                                                muted
                                                playsinline
                                                preload="metadata"
                                                src="{{ $nextPreviewUrl }}"
                                                data-preview-start="30"
                                                data-preview-duration="8"
                                            ></video>
                                        @endif
                                    </span>
                                    <span class="episode-preview-title">Episode {{ $nextEpisode->episode_number }}</span>
                                </a>
                            </div>
                        @endif

                        @if($previousEpisode)
                            @php
                                $previousThumbUrl = !empty($previousEpisode->thumbnail_path)
                                    ? Storage::url($previousEpisode->thumbnail_path)
                                    : asset('images/no-image.jpg');
                                $previousPreviewUrl = !empty($previousEpisode->file_path)
                                    ? asset('storage/'.$previousEpisode->file_path)
                                    : null;
                            @endphp
                            <div>
                                <h2 class="text-lg font-bold text-black mb-2">PREVIOUS EPISODE</h2>
                                <a href="{{ route('episodes.show', ['media' => $item['id'], 'episode' => $previousEpisode->episode_number]) }}"
                                   class="episode-preview-card rounded-sm hover:opacity-90 transition"
                                   aria-label="Previous episode, Episode {{ $previousEpisode->episode_number }}">
                                    <span class="episode-preview-media">
                                        <img
                                            src="{{ $previousThumbUrl }}"
                                            alt="Episode {{ $previousEpisode->episode_number }} thumbnail"
                                            loading="lazy"
                                        >
                                        @if($previousPreviewUrl)
                                            <video
                                                muted
                                                playsinline
                                                preload="metadata"
                                                src="{{ $previousPreviewUrl }}"
                                                data-preview-start="30"
                                                data-preview-duration="8"
                                            ></video>
                                        @endif
                                    </span>
                                    <span class="episode-preview-title">Episode {{ $previousEpisode->episode_number }}</span>
                                </a>
                            </div>
                        @endif
                    </div>
                </aside>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const [player] = Plyr.setup('#player', {
                controls: ['play-large','play','progress','current-time','duration','mute','volume','settings','fullscreen'],
                disableContextMenu: true,
                captions: { active: false, update: true },
                invertTime: false
            });

            const video = document.getElementById('player');
            const subtitleSource = video?.dataset.subtitlesSrc;
            const topSubtitleSource = video?.dataset.topSubtitlesSrc;
            const skipSettingKey = 'dordielist.player.arrowSkipSeconds';
            const subtitleSettingKey = 'dordielist.player.subtitlesEnabled';
            let arrowSkipSeconds = localStorage.getItem(skipSettingKey) === '10' ? 10 : 5;
            let subtitlesEnabled = localStorage.getItem(subtitleSettingKey) !== '0';
            let renderSubtitleCue = () => {};

            const isEditableTarget = (target) => {
                if (!(target instanceof HTMLElement)) {
                    return false;
                }

                return Boolean(target.closest('input, textarea, select, [contenteditable="true"]'));
            };

            const seekBy = (seconds) => {
                if (!video) {
                    return;
                }

                const duration = Number.isFinite(video.duration) ? video.duration : null;
                const nextTime = duration === null
                    ? Math.max(0, video.currentTime + seconds)
                    : Math.min(duration, Math.max(0, video.currentTime + seconds));

                video.currentTime = nextTime;
            };

            document.addEventListener('keydown', (event) => {
                if (!video || isEditableTarget(event.target) || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
                    return;
                }

                const key = event.key.toLowerCase();

                if (!['arrowleft', 'arrowright', ' ', 'spacebar', 'f'].includes(key)) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();

                if (key === 'arrowleft' || key === 'arrowright') {
                    seekBy(key === 'arrowright' ? arrowSkipSeconds : -arrowSkipSeconds);
                    return;
                }

                if (key === ' ' || key === 'spacebar') {
                    video.paused ? video.play() : video.pause();
                    return;
                }

                player?.fullscreen?.toggle();
            }, true);

            const updateSkipButtons = () => {
                player?.elements?.container
                    ?.querySelectorAll('[data-arrow-skip-value]')
                    .forEach((button) => {
                        const isActive = Number(button.dataset.arrowSkipValue) === arrowSkipSeconds;
                        button.classList.toggle('is-active', isActive);
                        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                        button.setAttribute('aria-checked', isActive ? 'true' : 'false');
                    });

                const value = player?.elements?.container?.querySelector('[data-arrow-skip-current]');
                if (value) {
                    value.textContent = `${arrowSkipSeconds}s`;
                }
            };

            const updateSubtitleButtons = () => {
                player?.elements?.container
                    ?.querySelectorAll('[data-subtitles-enabled]')
                    .forEach((button) => {
                        const isActive = (button.dataset.subtitlesEnabled === '1') === subtitlesEnabled;
                        button.classList.toggle('is-active', isActive);
                        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                        button.setAttribute('aria-checked', isActive ? 'true' : 'false');
                    });

                const value = player?.elements?.container?.querySelector('[data-subtitles-current]');
                if (value) {
                    value.textContent = subtitlesEnabled ? 'On' : 'Off';
                }

                renderSubtitleCue();
            };

            const addArrowSkipSetting = () => {
                const menuContainer = player?.elements?.container?.querySelector('.plyr__menu__container');
                const panelWrapper = menuContainer?.firstElementChild;
                const homePanel = panelWrapper?.querySelector('[id$="-home"]') || panelWrapper?.firstElementChild;
                const homeMenu = homePanel?.querySelector('[role="menu"]');

                if (!menuContainer || !panelWrapper || !homePanel || !homeMenu || homeMenu.querySelector('.plyr-arrow-skip-setting__home')) {
                    return;
                }

                const panelId = `${menuContainer.id || 'plyr-settings'}-arrow-skip`;
                const homeButton = document.createElement('button');
                homeButton.type = 'button';
                homeButton.className = 'plyr__control plyr__control--forward plyr-arrow-skip-setting__home';
                homeButton.setAttribute('role', 'menuitem');
                homeButton.setAttribute('aria-haspopup', 'true');
                homeButton.innerHTML = `
                    <span>Skip</span>
                    <span class="plyr__menu__value" data-arrow-skip-current>${arrowSkipSeconds}s</span>
                `;

                const panel = document.createElement('div');
                panel.id = panelId;
                panel.className = 'plyr-arrow-skip-setting';
                panel.hidden = true;
                panel.innerHTML = `
                    <button type="button" class="plyr__control plyr__control--back plyr-arrow-skip-setting__back">
                        <span>Skip</span>
                    </button>
                    <div role="menu">
                        <button type="button" class="plyr__control plyr-arrow-skip-setting__button" role="menuitemradio" data-arrow-skip-value="5" aria-checked="false">
                            <span>5 seconds</span>
                        </button>
                        <button type="button" class="plyr__control plyr-arrow-skip-setting__button" role="menuitemradio" data-arrow-skip-value="10" aria-checked="false">
                            <span>10 seconds</span>
                        </button>
                    </div>
                `;

                const showPanel = (panelElement) => {
                    menuContainer.style.height = '';

                    Array.from(panelWrapper.children).forEach((child) => {
                        child.hidden = child !== panelElement;
                    });
                };

                homeButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    showPanel(panel);
                    panel.querySelector('.plyr-arrow-skip-setting__back')?.focus();
                });

                panel.querySelector('.plyr-arrow-skip-setting__back')?.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    showPanel(homePanel);
                    homeButton.focus();
                });

                panel.querySelectorAll('[data-arrow-skip-value]').forEach((button) => {
                    button.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();

                        arrowSkipSeconds = Number(button.dataset.arrowSkipValue) === 10 ? 10 : 5;
                        localStorage.setItem(skipSettingKey, String(arrowSkipSeconds));
                        updateSkipButtons();
                        showPanel(homePanel);
                        homeButton.focus();
                    });
                });

                homeMenu.appendChild(homeButton);
                panelWrapper.appendChild(panel);

                if (subtitleSource || topSubtitleSource) {
                    const subtitlesPanelId = `${menuContainer.id || 'plyr-settings'}-subtitles`;
                    const subtitlesHomeButton = document.createElement('button');
                    subtitlesHomeButton.type = 'button';
                    subtitlesHomeButton.className = 'plyr__control plyr__control--forward plyr-subtitles-setting__home';
                    subtitlesHomeButton.setAttribute('role', 'menuitem');
                    subtitlesHomeButton.setAttribute('aria-haspopup', 'true');
                    subtitlesHomeButton.innerHTML = `
                        <span>Subtitles</span>
                        <span class="plyr__menu__value" data-subtitles-current>${subtitlesEnabled ? 'On' : 'Off'}</span>
                    `;

                    const subtitlesPanel = document.createElement('div');
                    subtitlesPanel.id = subtitlesPanelId;
                    subtitlesPanel.className = 'plyr-arrow-skip-setting plyr-subtitles-setting';
                    subtitlesPanel.hidden = true;
                    subtitlesPanel.innerHTML = `
                        <button type="button" class="plyr__control plyr__control--back plyr-subtitles-setting__back">
                            <span>Subtitles</span>
                        </button>
                        <div role="menu">
                            <button type="button" class="plyr__control plyr-subtitles-setting__button" role="menuitemradio" data-subtitles-enabled="1" aria-checked="false">
                                <span>On</span>
                            </button>
                            <button type="button" class="plyr__control plyr-subtitles-setting__button" role="menuitemradio" data-subtitles-enabled="0" aria-checked="false">
                                <span>Off</span>
                            </button>
                        </div>
                    `;

                    subtitlesHomeButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        showPanel(subtitlesPanel);
                        subtitlesPanel.querySelector('.plyr-subtitles-setting__back')?.focus();
                    });

                    subtitlesPanel.querySelector('.plyr-subtitles-setting__back')?.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        showPanel(homePanel);
                        subtitlesHomeButton.focus();
                    });

                    subtitlesPanel.querySelectorAll('[data-subtitles-enabled]').forEach((button) => {
                        button.addEventListener('click', (event) => {
                            event.preventDefault();
                            event.stopPropagation();

                            subtitlesEnabled = button.dataset.subtitlesEnabled === '1';
                            localStorage.setItem(subtitleSettingKey, subtitlesEnabled ? '1' : '0');
                            updateSubtitleButtons();
                            showPanel(homePanel);
                            subtitlesHomeButton.focus();
                        });
                    });

                    homeMenu.appendChild(subtitlesHomeButton);
                    panelWrapper.appendChild(subtitlesPanel);
                    updateSubtitleButtons();
                }

                updateSkipButtons();
            };

            addArrowSkipSetting();
            player?.elements?.container
                ?.querySelector('[data-plyr="settings"]')
                ?.addEventListener('click', () => setTimeout(addArrowSkipSetting, 0));

            if (video && (subtitleSource || topSubtitleSource) && player?.elements?.container) {
                const subtitleRenderers = [];
                const videoWrapper = player.elements.container.querySelector('.plyr__video-wrapper');
                const subtitleLayer = videoWrapper || player.elements.container;
                const parseTime = (value) => {
                    const parts = value.trim().split(':');
                    const seconds = parts.pop();
                    const minutes = parts.pop() || '0';
                    const hours = parts.pop() || '0';

                    return (Number(hours) * 3600)
                        + (Number(minutes) * 60)
                        + Number(seconds.replace(',', '.'));
                };

                const formatCueText = (value) => {
                    const template = document.createElement('template');
                    template.innerHTML = value
                        .replace(/&(?!amp;|lt;|gt;|quot;|#39;|nbsp;)/g, '&amp;')
                        .replace(/\n/g, '<br>');

                    const allowedTags = new Set(['I', 'B', 'U', 'BR']);
                    template.content.querySelectorAll('*').forEach((element) => {
                        if (!allowedTags.has(element.tagName)) {
                            element.replaceWith(document.createTextNode(element.textContent || ''));
                            return;
                        }

                        Array.from(element.attributes).forEach((attribute) => element.removeAttribute(attribute.name));
                    });

                    return template.innerHTML;
                };

                const parseVtt = (text) => text
                    .replace(/^\uFEFF/, '')
                    .replace(/\r/g, '')
                    .split(/\n{2,}/)
                    .map((block) => block.trim().split('\n'))
                    .map((lines) => {
                        const timingIndex = lines.findIndex((line) => line.includes('-->'));

                        if (timingIndex === -1) {
                            return null;
                        }

                        const [start, end] = lines[timingIndex].split('-->').map((part) => part.trim().split(/\s+/)[0]);
                        const textLines = lines.slice(timingIndex + 1).join('\n').trim();

                        if (!start || !end || textLines === '') {
                            return null;
                        }

                        return {
                            start: parseTime(start),
                            end: parseTime(end),
                            text: formatCueText(textLines),
                        };
                    })
                    .filter(Boolean);

                const loadSubtitleOverlay = (source, className = '') => {
                    if (!source) {
                        return;
                    }

                    const overlay = document.createElement('div');
                    overlay.className = ['streaming-subtitle-overlay', className].filter(Boolean).join(' ');
                    overlay.setAttribute('aria-hidden', 'true');
                    subtitleLayer.appendChild(overlay);

                    fetch(source, { cache: 'no-store' })
                    .then((response) => response.ok ? response.text() : Promise.reject(response))
                    .then((text) => {
                        const cues = parseVtt(text);
                        let activeCueIndex = 0;
                        let currentText = '';

                        const renderCue = () => {
                            const currentTime = video.currentTime;

                            while (activeCueIndex > 0 && currentTime < cues[activeCueIndex].start) {
                                activeCueIndex--;
                            }

                            while (activeCueIndex < cues.length - 1 && currentTime > cues[activeCueIndex].end) {
                                activeCueIndex++;
                            }

                            const candidate = cues[activeCueIndex];
                            const nextText = subtitlesEnabled && candidate && currentTime >= candidate.start && currentTime <= candidate.end
                                ? candidate.text
                                : '';

                            if (nextText !== currentText) {
                                currentText = nextText;
                                overlay.innerHTML = currentText;
                                overlay.classList.toggle('is-visible', currentText !== '');
                            }
                        };

                        video.addEventListener('timeupdate', renderCue);
                        video.addEventListener('seeked', renderCue);
                        video.addEventListener('loadedmetadata', renderCue);
                        subtitleRenderers.push(renderCue);
                        renderSubtitleCue = () => subtitleRenderers.forEach((renderer) => renderer());
                        updateSubtitleButtons();
                        renderCue();
                    })
                    .catch(() => {
                        overlay.innerHTML = '';
                        overlay.classList.remove('is-visible');
                    });
                };

                loadSubtitleOverlay(subtitleSource);
                loadSubtitleOverlay(topSubtitleSource, 'is-top');
            }

            document.querySelectorAll('.episode-preview-card video').forEach((previewVideo) => {
                const card = previewVideo.closest('.episode-preview-card');
                const previewStart = Number(previewVideo.dataset.previewStart || 30);
                const previewDuration = Number(previewVideo.dataset.previewDuration || 8);
                let previewEnd = previewStart + previewDuration;

                const startPreview = () => {
                    previewVideo.muted = true;
                    previewVideo.currentTime = Math.min(
                        previewStart,
                        Number.isFinite(previewVideo.duration) && previewVideo.duration > 1
                            ? Math.max(0, previewVideo.duration - 1)
                            : previewStart
                    );
                    previewEnd = previewVideo.currentTime + previewDuration;
                    previewVideo.play().then(() => {
                        card?.classList.add('is-previewing');
                    }).catch(() => {});
                };

                const stopPreview = () => {
                    previewVideo.pause();
                    card?.classList.remove('is-previewing');
                };

                previewVideo.addEventListener('timeupdate', () => {
                    if (previewVideo.currentTime >= previewEnd) {
                        previewVideo.currentTime = Math.max(0, previewEnd - previewDuration);
                    }
                });

                card?.addEventListener('mouseenter', startPreview);
                card?.addEventListener('mouseleave', stopPreview);
                card?.addEventListener('focusin', startPreview);
                card?.addEventListener('focusout', stopPreview);
            });

        });
    </script>
@endsection
