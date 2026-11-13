/* ==========================================================================
   Diarify — vanilla JS single-page player
   No framework, no build step. Hash routing + HTML5 audio.
   ========================================================================== */
(function () {
    'use strict';

    var DIAR = window.DIAR || { base: '', csrf: '', user: null };
    var $app = document.getElementById('app');

    /* ------------------------------------------------------------------ *
     * Utilities
     * ------------------------------------------------------------------ */

    function h(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icon(name, cls) {
        return '<svg class="' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"></use></svg>';
    }

    function fmtClock(sec) {
        if (!isFinite(sec) || sec < 0) sec = 0;
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function fmtTotal(ms) {
        var sec = Math.round((ms || 0) / 1000);
        var hrs = Math.floor(sec / 3600);
        var min = Math.floor((sec % 3600) / 60);
        if (hrs > 0) return hrs + ' jam ' + min + ' mnt';
        if (min > 0) return min + ' mnt';
        return sec + ' dtk';
    }

    function fmtLong(ms) {
        var sec = Math.round((ms || 0) / 1000);
        var hrs = Math.floor(sec / 3600);
        var min = Math.floor((sec % 3600) / 60);
        var out = [];
        if (hrs) out.push(hrs + ' jam');
        if (min) out.push(min + ' menit');
        return out.join(' ') || '0 menit';
    }

    function hashStr(s) {
        var n = 0;
        s = String(s || '');
        for (var i = 0; i < s.length; i++) n = (n * 31 + s.charCodeAt(i)) >>> 0;
        return n;
    }

    function initial(name) {
        name = String(name || '?').trim();
        return h(name.charAt(0).toUpperCase() || '?');
    }

    function hueOf(seed) {
        return hashStr(seed) % 360;
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function coverHTML(url, name, seed, cls) {
        cls = cls || 'card-cover';
        if (url) {
            return '<img class="' + cls + '" src="' + h(url) + '" alt="" loading="lazy" draggable="false">';
        }
        return '<div class="cover-ph ' + cls + '" style="--hue:' + hueOf(seed || name) + '"><span>' + initial(name) + '</span></div>';
    }

    function greeting() {
        var d = new Date();
        var hr = d.getHours();
        if (hr < 5) return 'Malam yang tenang';
        if (hr < 11) return 'Selamat pagi';
        if (hr < 15) return 'Selamat siang';
        if (hr < 19) return 'Selamat sore';
        return 'Selamat malam';
    }

    function apiBase() {
        return DIAR.base + '/api';
    }

    async function api(path, opts) {
        opts = opts || {};
        var init = {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        };
        if (opts.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        if (init.method !== 'GET') {
            init.headers['X-CSRF-Token'] = DIAR.csrf;
        }

        var res;
        try {
            res = await fetch(apiBase() + path, init);
        } catch (e) {
            throw new Error('Tidak bisa terhubung ke server.');
        }

        var data = null;
        try { data = await res.json(); } catch (e) { data = null; }

        if (res.status === 401) {
            renderLogin();
            throw new Error((data && data.error) || 'Silakan masuk.');
        }
        if (!res.ok || (data && data.ok === false)) {
            throw new Error((data && data.error) || 'Terjadi kesalahan (' + res.status + ').');
        }
        return data;
    }

    function toast(message, kind) {
        var root = document.querySelector('.toast-root');
        if (!root) {
            root = document.createElement('div');
            root.className = 'toast-root';
            document.body.appendChild(root);
        }
        var el = document.createElement('div');
        el.className = 'toast' + (kind === 'error' ? ' error' : '');
        el.textContent = message;
        root.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 320);
        }, 2600);
    }

    /* ------------------------------------------------------------------ *
     * State
     * ------------------------------------------------------------------ */

    var state = {
        user: DIAR.user,
        playlists: [],
        contexts: {},
        ctxSeq: 0,
        view: null,
        sidebarOpen: false,
        queueOpen: false,
        navStack: [],
        navPos: -1,
        scanning: false
    };

    function ctx(songs) {
        var id = 'c' + (++state.ctxSeq);
        state.contexts[id] = songs;
        return id;
    }

    /* ------------------------------------------------------------------ *
     * Player
     * ------------------------------------------------------------------ */

    var audio = new Audio();
    audio.preload = 'metadata';

    var player = {
        queue: [],
        index: -1,
        shuffle: false,
        repeat: 'off', // off | all | one
        originalQueue: null,
        volume: 1,
        reportedId: null,
        dragging: false
    };

    var STORE_KEY = 'diartify.player.v1';

    function savePlayer() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify({
                queue: player.queue.slice(0, 200),
                index: player.index,
                shuffle: player.shuffle,
                repeat: player.repeat,
                volume: player.volume,
                position: audio.currentTime || 0,
                originalQueue: player.originalQueue ? player.originalQueue.slice(0, 200) : null
            }));
        } catch (e) { /* quota */ }
    }

    function restorePlayer() {
        try {
            var raw = localStorage.getItem(STORE_KEY);
            if (!raw) return;
            var data = JSON.parse(raw);
            if (data && Array.isArray(data.queue) && data.queue.length) {
                player.queue = data.queue;
                player.index = Math.min(data.index || 0, data.queue.length - 1);
                player.shuffle = !!data.shuffle;
                player.repeat = data.repeat === 'all' || data.repeat === 'one' ? data.repeat : 'off';
                player.originalQueue = Array.isArray(data.originalQueue) ? data.originalQueue : null;
                player.volume = typeof data.volume === 'number' ? data.volume : 1;
                audio.volume = player.volume;
                var song = player.queue[player.index];
                if (song) {
                    audio.src = song.streamUrl;
                    var pos = data.position || 0;
                    audio.addEventListener('loadedmetadata', function once() {
                        audio.removeEventListener('loadedmetadata', once);
                        if (pos > 1 && pos < (audio.duration || Infinity) - 3) {
                            try { audio.currentTime = pos; } catch (e) {}
                        }
                        renderPlayerBar();
                    });
                }
            }
        } catch (e) { /* corrupted */ }
    }

    function currentSong() {
        return player.index >= 0 ? player.queue[player.index] : null;
    }

    function playAt(index, autoplay) {
        if (index < 0 || index >= player.queue.length) return;
        player.index = index;
        var song = player.queue[index];
        if (!song) return;

        if (audio.src !== absUrl(song.streamUrl)) {
            audio.src = song.streamUrl;
        }
        player.reportedId = null;

        if (autoplay !== false) {
            var p = audio.play();
            if (p && p.catch) p.catch(function () { /* autoplay blocked */ });
        }

        renderPlayerBar();
        renderQueuePanel();
        markPlayingRows();
        updateMediaSession();
        savePlayer();
    }

    function absUrl(path) {
        try { return new URL(path, location.href).href; } catch (e) { return path; }
    }

    function setQueue(songs, startIndex, autoplay) {
        if (!songs || !songs.length) return;
        if (player.shuffle) {
            var current = songs[startIndex || 0];
            var rest = songs.slice();
            rest.splice(startIndex || 0, 1);
            shuffleArray(rest);
            rest.unshift(current);
            player.queue = rest;
            player.originalQueue = songs.slice();
            player.index = 0;
        } else {
            player.queue = songs.slice();
            player.originalQueue = null;
            player.index = startIndex || 0;
        }
        playAt(player.index, autoplay);
    }

    function shuffleArray(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
        }
        return arr;
    }

    function togglePlay() {
        if (!currentSong()) {
            if (state.view && state.view.songs && state.view.songs.length) {
                setQueue(state.view.songs, 0, true);
            }
            return;
        }
        if (audio.paused) {
            var p = audio.play();
            if (p && p.catch) p.catch(function () {});
        } else {
            audio.pause();
        }
    }

    function nextTrack(auto) {
        if (!player.queue.length) return;
        if (player.repeat === 'one' && auto) {
            audio.currentTime = 0;
            audio.play();
            return;
        }
        var next = player.index + 1;
        if (next >= player.queue.length) {
            if (player.repeat === 'all') next = 0;
            else {
                audio.pause();
                audio.currentTime = 0;
                renderPlayerBar();
                return;
            }
        }
        playAt(next, true);
    }

    function prevTrack() {
        if (!player.queue.length) return;
        if (audio.currentTime > 3) {
            audio.currentTime = 0;
            return;
        }
        var prev = player.index - 1;
        if (prev < 0) prev = player.repeat === 'all' ? player.queue.length - 1 : 0;
        playAt(prev, true);
    }

    function toggleShuffle() {
        player.shuffle = !player.shuffle;
        if (player.shuffle) {
            player.originalQueue = player.queue.slice();
            var cur = player.queue[player.index];
            var rest = player.queue.slice();
            rest.splice(player.index, 1);
            shuffleArray(rest);
            if (cur) rest.unshift(cur);
            player.queue = rest;
            player.index = cur ? 0 : -1;
        } else if (player.originalQueue) {
            var song = currentSong();
            player.queue = player.originalQueue;
            player.originalQueue = null;
            if (song) {
                var idx = player.queue.findIndex(function (s) { return s.id === song.id; });
                player.index = idx >= 0 ? idx : 0;
            }
        }
        renderPlayerBar();
        renderQueuePanel();
        savePlayer();
    }

    function cycleRepeat() {
        player.repeat = player.repeat === 'off' ? 'all' : (player.repeat === 'all' ? 'one' : 'off');
        renderPlayerBar();
        savePlayer();
    }

    function playNext(song) {
        if (!song) return;
        if (!player.queue.length) {
            setQueue([song], 0, true);
            return;
        }
        player.queue.splice(player.index + 1, 0, song);
        if (player.index < 0) playAt(0, true);
        renderQueuePanel();
        savePlayer();
        toast('Diputar berikutnya: ' + song.title);
    }

    function addToQueue(song) {
        if (!song) return;
        if (!player.queue.length) {
            setQueue([song], 0, true);
            return;
        }
        player.queue.push(song);
        renderQueuePanel();
        savePlayer();
        toast('Ditambahkan ke antrean: ' + song.title);
    }

    function reportPlayed(song) {
        if (!song) return;
        api('/songs/' + song.id + '/played', { method: 'POST', body: {} }).catch(function () {});
    }

    audio.addEventListener('play', function () {
        var song = currentSong();
        if (song && player.reportedId !== song.id) {
            player.reportedId = song.id;
            reportPlayed(song);
        }
        renderPlayerBar();
        markPlayingRows();
    });

    audio.addEventListener('pause', function () {
        renderPlayerBar();
        markPlayingRows();
    });

    audio.addEventListener('ended', function () { nextTrack(true); });

    audio.addEventListener('timeupdate', function () {
        if (player.dragging) return;
        var pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
        var bar = document.getElementById('progress');
        if (bar) {
            bar.style.setProperty('--pct', pct.toFixed(2) + '%');
            bar.value = pct;
        }
        var cur = document.getElementById('time-cur');
        if (cur) cur.textContent = fmtClock(audio.currentTime);
        if ('mediaSession' in navigator && navigator.mediaSession.setPositionState && audio.duration) {
            try {
                navigator.mediaSession.setPositionState({
                    duration: audio.duration,
                    position: audio.currentTime,
                    playbackRate: audio.playbackRate || 1
                });
            } catch (e) {}
        }
    });

    audio.addEventListener('loadedmetadata', function () {
        var dur = document.getElementById('time-dur');
        if (dur) dur.textContent = fmtClock(audio.duration || 0);
    });

    audio.addEventListener('error', function () {
        if (!audio.src) return;
        if (state.user) toast('Gagal memuat audio. File mungkin tidak tersedia.', 'error');
    });

    setInterval(function () {
        if (!audio.paused) savePlayer();
    }, 5000);

    function updateMediaSession() {
        if (!('mediaSession' in navigator)) return;
        var song = currentSong();
        if (!song) return;
        var art = song.cover
            ? [{ src: absUrl(song.cover), sizes: '500x500', type: 'image/webp' }]
            : [];
        try {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: song.title,
                artist: song.artist.name,
                album: song.album.title,
                artwork: art
            });
            navigator.mediaSession.setActionHandler('play', function () { audio.play(); });
            navigator.mediaSession.setActionHandler('pause', function () { audio.pause(); });
            navigator.mediaSession.setActionHandler('previoustrack', prevTrack);
            navigator.mediaSession.setActionHandler('nexttrack', function () { nextTrack(false); });
        } catch (e) {}
    }

    /* ------------------------------------------------------------------ *
     * Shared view fragments
     * ------------------------------------------------------------------ */

    function songRow(song, opts) {
        opts = opts || {};
        var idx = opts.index;
        var ctxId = opts.ctxId;
        var cls = 'track-row';
        var playing = currentSong() && currentSong().id === song.id && !audio.paused;
        if (currentSong() && currentSong().id === song.id) cls += ' current-song';
        if (playing) cls += ' playing';

        var numCell = opts.showIndex === false ? '' :
            '<div class="idx">' +
                '<span class="idx-number">' + (idx != null ? idx + 1 : '') + '</span>' +
                '<button class="row-play" data-act="row-play" data-ctx="' + h(ctxId) + '" data-i="' + idx + '" aria-label="Putar ' + h(song.title) + '">' +
                    icon(playing ? 'pause' : 'play') +
                '</button>' +
                '<div class="eq" aria-hidden="true"><span></span><span></span><span></span><span></span></div>' +
            '</div>';

        var thumb = opts.thumb
            ? '<div class="track-thumb-slot">' + (song.cover
                ? '<img class="track-thumb" src="' + h(song.cover) + '" alt="" loading="lazy">'
                : '') + '</div>'
            : '';

        var albumCell = '<a class="track-album" data-act="go-album" data-id="' + (song.album.id || '') + '">' + h(song.album.title) + '</a>';

        return '<div class="' + cls + '" data-song-id="' + song.id + '" data-ctx="' + h(ctxId) + '" data-i="' + idx + '">' +
            numCell +
            '<div class="track-title-cell">' +
                (opts.thumb ? '<div class="track-thumb-wrap">' + (song.cover
                    ? '<img class="track-thumb" src="' + h(song.cover) + '" alt="" loading="lazy">'
                    : '<div class="cover-ph track-thumb" style="--hue:' + hueOf(song.title) + '"><span>' + initial(song.title) + '</span></div>') + '</div>' : '') +
                '<div class="track-text">' +
                    '<div class="track-title">' + h(song.title) + '</div>' +
                    '<a class="track-artist" data-act="go-artist" data-id="' + (song.artist.id || '') + '">' + h(song.artist.name) + '</a>' +
                '</div>' +
            '</div>' +
            albumCell +
            '<div class="like-cell"><button class="icon-btn' + (song.liked ? ' liked' : '') + '" data-act="toggle-like" data-id="' + song.id + '" aria-label="Suka">' +
                icon(song.liked ? 'heart-filled' : 'heart') + '</button></div>' +
            '<div class="track-dur">' +
                '<span class="dur-text">' + fmtClock(song.durationMs / 1000) + '</span>' +
            '</div>' +
            '<div class="more-cell"><button class="icon-btn row-more" data-act="song-menu" data-id="' + song.id + '" aria-label="Menu">' + icon('more') + '</button></div>' +
        '</div>';
    }

    function trackTable(songs, opts) {
        opts = opts || {};
        if (!songs.length) {
            return '<div class="empty-state">' + icon('music') +
                '<h3>Belum ada lagu</h3><p>' + h(opts.emptyText || 'Pustaka masih kosong. Jalankan pemindaian untuk mulai mengisi.') + '</p></div>';
        }
        var ctxId = opts.ctxId || ctx(songs);
        var html = '<div class="track-table">' +
            '<div class="track-head">' +
                '<div class="num">#</div>' +
                '<div>Judul</div>' +
                '<div class="album-col">Album</div>' +
                '<div class="like-head"></div>' +
                '<div class="dur">' + icon('clock') + '</div>' +
                '<div class="more-col"></div>' +
            '</div>';
        songs.forEach(function (s, i) {
            html += songRow(s, { index: i, ctxId: ctxId, thumb: !!opts.thumb });
        });
        html += '</div>';
        return html;
    }

    function albumCard(album) {
        return '<div class="card" data-act="go-album" data-id="' + album.id + '" tabindex="0">' +
            '<div class="card-cover-wrap">' +
                coverHTML(album.cover, album.title, 'album' + album.id) +
                '<button class="card-play" data-act="play-album" data-id="' + album.id + '" aria-label="Putar album">' + icon('play') + '</button>' +
            '</div>' +
            '<p class="card-title">' + h(album.title) + '</p>' +
            '<p class="card-sub">' + h(album.year ? album.year + ' • ' + album.artist.name : album.artist.name) + '</p>' +
        '</div>';
    }

    function artistCard(artist) {
        return '<div class="card round" data-act="go-artist" data-id="' + artist.id + '" tabindex="0">' +
            '<div class="card-cover-wrap">' +
                coverHTML(artist.cover, artist.name, 'artist' + artist.id) +
            '</div>' +
            '<p class="card-title">' + h(artist.name) + '</p>' +
            '<p class="card-sub">Artis</p>' +
        '</div>';
    }

    function playlistCard(pl) {
        return '<div class="card" data-act="go-playlist" data-id="' + pl.id + '" tabindex="0">' +
            '<div class="card-cover-wrap">' +
                coverHTML(pl.cover, pl.name, 'pl' + pl.id) +
                '<button class="card-play" data-act="play-playlist" data-id="' + pl.id + '" aria-label="Putar playlist">' + icon('play') + '</button>' +
            '</div>' +
            '<p class="card-title">' + h(pl.name) + '</p>' +
            '<p class="card-sub">Playlist • ' + (pl.songCount || 0) + ' lagu</p>' +
        '</div>';
    }

    function sectionHead(title, moreAct, moreLabel) {
        return '<div class="section-head"><h2>' + h(title) + '</h2>' +
            (moreAct ? '<button class="link-btn" data-act="' + h(moreAct) + '">' + h(moreLabel || 'Tampilkan semua') + '</button>' : '') +
            '</div>';
    }

    function emptyState(iconName, title, text, actionHTML) {
        return '<div class="empty-state">' + icon(iconName) +
            '<h3>' + h(title) + '</h3><p>' + h(text) + '</p>' + (actionHTML || '') + '</div>';
    }

    /* ------------------------------------------------------------------ *
     * Login
     * ------------------------------------------------------------------ */

    function renderLogin(mode) {
        state.user = null;
        var isRegister = mode === 'register';
        $app.innerHTML =
            '<div class="login-screen">' +
                '<form class="login-card" id="login-form" novalidate>' +
                    '<div class="login-brand"><img src="' + h(DIAR.base) + '/assets/logo.svg" alt="">Diarify</div>' +
                    '<p class="login-tagline">Musik milikmu sendiri, tampilan yang kamu suka.</p>' +
                    '<h1>' + (isRegister ? 'Daftar' : 'Masuk') + '</h1>' +
                    '<div id="login-error" class="login-error" hidden></div>' +
                    (isRegister
                        ? '<div class="field"><label for="login-name">Nama</label>' +
                            '<input id="login-name" name="name" type="text" autocomplete="name" required maxlength="80" placeholder="Nama kamu"></div>'
                        : '') +
                    '<div class="field"><label for="login-email">Email</label>' +
                        '<input id="login-email" name="email" type="email" autocomplete="username" required placeholder="kamu@contoh.id"></div>' +
                    '<div class="field"><label for="login-pass">Password</label>' +
                        '<input id="login-pass" name="password" type="password" required placeholder="••••••••" ' +
                            'autocomplete="' + (isRegister ? 'new-password' : 'current-password') + '"></div>' +
                    (isRegister
                        ? '<div class="field"><label for="login-pass2">Ulangi password</label>' +
                            '<input id="login-pass2" name="password2" type="password" required placeholder="••••••••" autocomplete="new-password"></div>'
                        : '') +
                    '<button class="btn btn-green login-submit" type="submit">' + (isRegister ? 'Daftar' : 'Masuk') + '</button>' +
                    '<p class="login-note">' + (isRegister
                        ? 'Tanpa email verifikasi, tanpa kode OTP — langsung bisa dipakai. ' +
                            'Setiap akun punya lagu disukai, playlist, dan statistik sendiri.'
                        : 'Cukup email dan password — tidak ada kode OTP, tidak ada langganan. ' +
                            'Sesi kamu bertahan lama selama masih dipakai mendengarkan musik.') + '</p>' +
                    '<p class="login-switch">' +
                        '<a href="#" data-login-mode="' + (isRegister ? 'login' : 'register') + '">' +
                            (isRegister ? 'Sudah punya akun? Masuk' : 'Belum punya akun? Daftar') +
                        '</a></p>' +
                '</form>' +
            '</div>';

        var form = document.getElementById('login-form');
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var errBox = document.getElementById('login-error');
            errBox.hidden = true;
            var btn = form.querySelector('button[type=submit]');
            btn.disabled = true;
            btn.textContent = isRegister ? 'Mendaftar…' : 'Memastikan…';
            try {
                var body = isRegister
                    ? { name: form.name.value.trim(), email: form.email.value.trim(), password: form.password.value }
                    : { email: form.email.value.trim(), password: form.password.value };
                if (isRegister && form.password.value !== form.password2.value) {
                    throw new Error('Password tidak sama.');
                }
                var data = await api(isRegister ? '/auth/register' : '/auth/login', {
                    method: 'POST',
                    body: body
                });
                if (data && data.ok) location.reload();
            } catch (err) {
                errBox.hidden = false;
                errBox.textContent = err.message;
                btn.disabled = false;
                btn.textContent = isRegister ? 'Daftar' : 'Masuk';
            }
        });

        var switcher = $app.querySelector('[data-login-mode]');
        if (switcher) {
            switcher.addEventListener('click', function (e) {
                e.preventDefault();
                renderLogin(switcher.getAttribute('data-login-mode'));
            });
        }
    }

    /* ------------------------------------------------------------------ *
     * Shell (app frame)
     * ------------------------------------------------------------------ */

    function initials(name) {
        name = String(name || '').trim();
        var parts = name.split(/\s+/);
        var s = parts[0] ? parts[0].charAt(0).toUpperCase() : '?';
        if (parts.length > 1) s += parts[parts.length - 1].charAt(0).toUpperCase();
        return s;
    }

    function renderShell() {
        var user = state.user;
        var isAdmin = !!(user && user.is_admin);

        $app.innerHTML =
        '<div class="app">' +
            '<div class="app-grid">' +
                '<aside class="sidebar" id="sidebar">' +
                    '<div class="sidebar-brand"><img src="' + h(DIAR.base) + '/assets/logo.svg" alt="">Diarify</div>' +
                    '<nav class="side-nav">' +
                        '<a class="side-link" href="#/" data-nav="home">' + icon('home') + 'Beranda</a>' +
                        '<a class="side-link" href="#/search" data-nav="search">' + icon('search') + 'Cari</a>' +
                    '</nav>' +
                    '<div class="library-head">' +
                        '<a class="side-link" href="#/playlists" data-nav="library">' + icon('library') + 'Koleksi</a>' +
                        '<div class="library-head-actions">' +
                            '<button class="icon-btn" data-act="new-playlist" title="Buat playlist" aria-label="Buat playlist">' + icon('plus') + '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="side-chips" id="side-chips">' +
                        '<a class="chip" href="#/playlists" data-chip="playlists">Playlist</a>' +
                        '<a class="chip" href="#/songs" data-chip="songs">Lagu</a>' +
                        '<a class="chip" href="#/albums" data-chip="albums">Album</a>' +
                        '<a class="chip" href="#/artists" data-chip="artists">Artis</a>' +
                        '<a class="chip" href="#/liked" data-chip="liked">Disukai</a>' +
                    '</div>' +
                    '<ul class="side-list" id="side-list"></ul>' +
                    '<div class="sidebar-foot">' +
                        (isAdmin
                            ? '<button class="scan-btn" id="scan-btn" data-act="scan">' + icon('refresh') + '<span>Pindai ulang pustaka</span></button>' +
                              '<div class="scan-status" id="scan-status"></div>'
                            : '') +
                    '</div>' +
                '</aside>' +

                '<main class="main">' +
                    '<header class="topbar" id="topbar">' +
                        '<div class="topbar-nav">' +
                            '<button class="circle-btn menu-btn" data-act="toggle-sidebar" aria-label="Menu">' + icon('library') + '</button>' +
                            '<button class="circle-btn" id="nav-back" data-act="nav-back" aria-label="Kembali">' + icon('chevron-left') + '</button>' +
                            '<button class="circle-btn" id="nav-fwd" data-act="nav-fwd" aria-label="Maju">' + icon('chevron-right') + '</button>' +
                        '</div>' +
                        '<div class="topbar-search">' + icon('search') +
                            '<input id="search-input" type="search" placeholder="Lagu, album, artis, playlist…" autocomplete="off" aria-label="Cari">' +
                        '</div>' +
                        '<div class="topbar-spacer"></div>' +
                        '<button class="user-btn" data-act="user-menu" aria-label="Menu pengguna">' +
                            '<span class="avatar">' + h(initials(user.name)) + '</span><span>' + h(user.name) + '</span>' +
                        '</button>' +
                        '<div class="user-menu" id="user-menu">' +
                            '<div class="user-menu-head"><b>' + h(user.name) + '</b><span>' + h(user.email) + '</span></div>' +
                            '<button data-act="logout">' + icon('logout') + 'Keluar</button>' +
                        '</div>' +
                    '</header>' +
                    '<div class="content" id="content"></div>' +
                '</main>' +
            '</div>' +

            '<footer class="player" id="player-bar"></footer>' +
        '</div>' +

        '<aside class="queue-panel" id="queue-panel" aria-label="Antrean"></aside>' +
        '<div class="modal-root"></div>';

        renderPlayerBar();
        renderQueuePanel();
        renderSideList();
        bindShellEvents();
        updateScanStatus();
    }

    function bindShellEvents() {
        var content = document.getElementById('content');
        var topbar = document.getElementById('topbar');

        content.addEventListener('scroll', function () {
            topbar.classList.toggle('scrolled', content.scrollTop > 8);
        });

        var searchInput = document.getElementById('search-input');
        var goSearch = debounce(function () {
            var q = searchInput.value.trim();
            var target = '#/search' + (q ? '?q=' + encodeURIComponent(q) : '');
            if (location.hash !== target) location.hash = target;
        }, 260);
        searchInput.addEventListener('input', goSearch);
        searchInput.addEventListener('focus', function () {
            if (location.hash.indexOf('#/search') !== 0) {
                location.hash = '#/search';
            }
        });
    }

    function renderSideList() {
        var list = document.getElementById('side-list');
        if (!list) return;

        var html = '';
        html += '<li><a class="side-item" href="#/liked">' +
            '<span class="cover-ph" style="--hue:270;width:36px;height:36px;border-radius:4px;display:grid;place-items:center;font-size:15px">' +
            icon('heart-filled') + '</span>' +
            '<span class="side-item-text"><b>Lagu Disukai</b><span>Playlist</span></span></a></li>';

        if (!state.playlists.length) {
            html += '<li class="side-empty">Belum ada playlist. Klik + untuk membuat.</li>';
        } else {
            state.playlists.forEach(function (pl) {
                html += '<li><a class="side-item" href="#/playlist/' + pl.id + '">' +
                    (pl.cover
                        ? '<img class="cover-mini" src="' + h(pl.cover) + '" alt="" loading="lazy">'
                        : '<span class="cover-ph" style="--hue:' + hueOf(pl.name) + ';width:36px;height:36px;border-radius:4px;font-size:15px"><span>' + initial(pl.name) + '</span></span>') +
                    '<span class="side-item-text"><b>' + h(pl.name) + '</b><span>Playlist • ' + (pl.songCount || 0) + '</span></span>' +
                '</a></li>';
            });
        }
        list.innerHTML = html;
        markActiveNav();
    }

    async function loadPlaylists() {
        try {
            var data = await api('/playlists');
            state.playlists = data.items || [];
            renderSideList();
        } catch (e) { /* not fatal */ }
    }

    function markActiveNav() {
        var hash = location.hash || '#/';
        document.querySelectorAll('[data-nav]').forEach(function (el) {
            var key = el.getAttribute('data-nav');
            var active =
                (key === 'home' && (hash === '#/' || hash === '')) ||
                (key === 'search' && hash.indexOf('#/search') === 0) ||
                (key === 'library' && hash.indexOf('#/') === 0 && ['#/playlists', '#/songs', '#/albums', '#/artists', '#/liked', '#/playlist/', '#/album/', '#/artist/'].some(function (p) {
                    return hash.indexOf(p) === 0;
                }));
            el.classList.toggle('active', active);
        });
        document.querySelectorAll('[data-chip]').forEach(function (el) {
            var key = el.getAttribute('data-chip');
            var active =
                (key === 'playlists' && hash.indexOf('#/playlists') === 0) ||
                (key === 'songs' && hash.indexOf('#/songs') === 0) ||
                (key === 'albums' && (hash.indexOf('#/albums') === 0 || hash.indexOf('#/album/') === 0)) ||
                (key === 'artists' && (hash.indexOf('#/artists') === 0 || hash.indexOf('#/artist/') === 0)) ||
                (key === 'liked' && hash.indexOf('#/liked') === 0);
            el.classList.toggle('active', active);
        });
        var back = document.getElementById('nav-back');
        var fwd = document.getElementById('nav-fwd');
        if (back) back.disabled = state.navPos <= 0;
        if (fwd) fwd.disabled = state.navPos >= state.navStack.length - 1;
    }

    /* ------------------------------------------------------------------ *
     * Player bar rendering
     * ------------------------------------------------------------------ */

    function renderPlayerBar() {
        var bar = document.getElementById('player-bar');
        if (!bar) return;
        var song = currentSong();

        if (!song) {
            bar.innerHTML =
                '<div class="player-info">' +
                    '<div class="cover-ph" style="--hue:0;width:56px;height:56px;border-radius:4px;font-size:22px"><span>♪</span></div>' +
                    '<div class="player-text"><div class="player-title" style="color:var(--sub)">Belum ada yang diputar</div>' +
                    '<div class="player-artist">Pilih lagu untuk mulai</div></div>' +
                '</div>' +
                '<div class="player-controls"><div class="control-row">' +
                    '<button class="ctrl-btn hide-xs" disabled>' + icon('shuffle') + '</button>' +
                    '<button class="ctrl-btn hide-mobile" disabled>' + icon('prev') + '</button>' +
                    '<button class="play-btn" disabled>' + icon('play') + '</button>' +
                    '<button class="ctrl-btn hide-mobile" disabled>' + icon('next') + '</button>' +
                    '<button class="ctrl-btn hide-xs" disabled>' + icon('repeat') + '</button>' +
                '</div></div>' +
                '<div class="player-extras"></div>';
            return;
        }

        var playing = !audio.paused;
        var pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
        var volPct = Math.round(player.volume * 100);

        bar.innerHTML =
            '<div class="player-info">' +
                (song.cover
                    ? '<img class="player-cover" src="' + h(song.cover) + '" alt="">'
                    : '<div class="player-cover"><div class="cover-ph" style="--hue:' + hueOf(song.title) + '"><span>' + initial(song.title) + '</span></div></div>') +
                '<div class="player-text">' +
                    '<div class="player-title" data-act="go-album" data-id="' + (song.album.id || '') + '" title="' + h(song.title) + '">' + h(song.title) + '</div>' +
                    '<div class="player-artist" data-act="go-artist" data-id="' + (song.artist.id || '') + '" title="' + h(song.artist.name) + '">' + h(song.artist.name) + '</div>' +
                '</div>' +
                '<button class="icon-btn player-like' + (song.liked ? ' liked' : '') + '" data-act="toggle-like-bar" aria-label="Suka">' +
                    icon(song.liked ? 'heart-filled' : 'heart') + '</button>' +
            '</div>' +

            '<div class="player-controls">' +
                '<div class="control-row">' +
                    '<button class="ctrl-btn hide-xs' + (player.shuffle ? ' on' : '') + '" data-act="shuffle" aria-label="Acak">' + icon('shuffle') + '</button>' +
                    '<button class="ctrl-btn hide-mobile" data-act="prev" aria-label="Sebelumnya">' + icon('prev') + '</button>' +
                    '<button class="play-btn" data-act="toggle-play" aria-label="' + (playing ? 'Jeda' : 'Putar') + '">' + icon(playing ? 'pause' : 'play') + '</button>' +
                    '<button class="ctrl-btn hide-mobile" data-act="next" aria-label="Berikutnya">' + icon('next') + '</button>' +
                    '<button class="ctrl-btn hide-xs' + (player.repeat !== 'off' ? ' on' : '') + '" data-act="repeat" aria-label="Ulangi">' +
                        icon(player.repeat === 'one' ? 'repeat-one' : 'repeat') + '</button>' +
                '</div>' +
                '<div class="progress-row">' +
                    '<span class="time" id="time-cur">' + fmtClock(audio.currentTime) + '</span>' +
                    '<input class="slider" id="progress" type="range" min="0" max="1000" value="' + (audio.duration ? Math.round(pct * 10) : 0) + '" style="--pct:' + pct.toFixed(2) + '%" aria-label="Posisi lagu">' +
                    '<span class="time right" id="time-dur">' + fmtClock(audio.duration || song.durationMs / 1000) + '</span>' +
                '</div>' +
            '</div>' +

            '<div class="player-extras">' +
                '<button class="ctrl-btn' + (state.queueOpen ? ' on' : '') + '" data-act="toggle-queue" aria-label="Antrean">' + icon('queue') + '</button>' +
                '<div class="volume-wrap">' +
                    '<button class="ctrl-btn" data-act="mute" aria-label="Suara">' + icon(player.volume === 0 ? 'volume-mute' : (player.volume < 0.5 ? 'volume-low' : 'volume')) + '</button>' +
                    '<input class="slider" id="volume" type="range" min="0" max="100" value="' + volPct + '" style="--pct:' + volPct + '%" aria-label="Volume">' +
                '</div>' +
            '</div>';

        bindPlayerBar();
    }

    function bindPlayerBar() {
        var progress = document.getElementById('progress');
        if (progress) {
            progress.addEventListener('input', function () {
                player.dragging = true;
                var pct = progress.value / 1000;
                progress.style.setProperty('--pct', (pct * 100).toFixed(2) + '%');
                var cur = document.getElementById('time-cur');
                if (cur && audio.duration) cur.textContent = fmtClock(pct * audio.duration);
            });
            progress.addEventListener('change', function () {
                if (audio.duration) {
                    audio.currentTime = (progress.value / 1000) * audio.duration;
                }
                player.dragging = false;
            });
        }

        var volume = document.getElementById('volume');
        if (volume) {
            volume.addEventListener('input', function () {
                player.volume = volume.value / 100;
                audio.volume = player.volume;
                volume.style.setProperty('--pct', volume.value + '%');
            });
            volume.addEventListener('change', savePlayer);
        }
    }

    function markPlayingRows() {
        var song = currentSong();
        var playing = song && !audio.paused;
        document.querySelectorAll('.track-row').forEach(function (row) {
            var id = parseInt(row.getAttribute('data-song-id'), 10);
            var isCurrent = song && song.id === id;
            row.classList.toggle('current-song', !!isCurrent);
            row.classList.toggle('playing', !!isCurrent && !!playing);
            var btn = row.querySelector('.row-play use');
            if (btn) btn.setAttribute('href', isCurrent && playing ? '#i-pause' : '#i-play');
        });
    }

    /* ------------------------------------------------------------------ *
     * Queue panel
     * ------------------------------------------------------------------ */

    function renderQueuePanel() {
        var panel = document.getElementById('queue-panel');
        if (!panel) return;
        panel.classList.toggle('open', state.queueOpen);

        var html =
            '<div class="queue-head"><h3>Antrean</h3>' +
                '<div>' +
                    (player.queue.length ? '<button class="queue-clear" data-act="clear-queue">Bersihkan</button>' : '') +
                    '<button class="icon-btn" data-act="toggle-queue" aria-label="Tutup">' + icon('x') + '</button>' +
                '</div>' +
            '</div>';

        if (!player.queue.length) {
            html += '<div class="queue-empty">Antrean kosong. Putar lagu atau tambahkan ke antrean.</div>';
        } else {
            html += '<div class="queue-now-label">Sedang diputar</div>';
            player.queue.forEach(function (s, i) {
                if (i === player.index) {
                    html += queueItem(s, i, true);
                }
            });
            var upcoming = player.queue.filter(function (_, i) { return i > player.index; });
            if (upcoming.length) {
                html += '<div class="queue-now-label">Berikutnya</div>';
                player.queue.forEach(function (s, i) {
                    if (i > player.index) html += queueItem(s, i, false);
                });
            }
        }
        panel.innerHTML = html;
    }

    function queueItem(song, i, current) {
        return '<div class="queue-item' + (current ? ' current' : '') + '" data-act="queue-jump" data-i="' + i + '">' +
            (song.cover
                ? '<img src="' + h(song.cover) + '" alt="">'
                : '<div class="cover-ph" style="--hue:' + hueOf(song.title) + '"><span>' + initial(song.title) + '</span></div>') +
            '<div class="queue-text">' +
                '<div class="queue-title">' + h(song.title) + '</div>' +
                '<div class="queue-artist">' + h(song.artist.name) + '</div>' +
            '</div>' +
        '</div>';
    }

    /* ------------------------------------------------------------------ *
     * Router
     * ------------------------------------------------------------------ */

    function parseRoute() {
        var raw = (location.hash || '#/').slice(1);
        var qIndex = raw.indexOf('?');
        var path = qIndex >= 0 ? raw.slice(0, qIndex) : raw;
        var query = {};
        if (qIndex >= 0) {
            raw.slice(qIndex + 1).split('&').forEach(function (pair) {
                if (!pair) return;
                var kv = pair.split('=');
                query[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
            });
        }
        path = '/' + path.replace(/^\/+/, '');
        if (path.length > 1) path = path.replace(/\/+$/, '');
        return { path: path, query: query };
    }

    async function route(force) {
        if (!state.user) { renderLogin(); return; }

        var routeKey = location.hash || '#/';
        if (!force && state.lastRouteKey === routeKey) return;
        state.lastRouteKey = routeKey;

        var route = parseRoute();
        state.view = { route: route };
        state.ctxSeq = 0;
        state.contexts = {};

        var content = document.getElementById('content');
        if (!content) { renderShell(); content = document.getElementById('content'); }

        content.innerHTML = '<div class="page"><div class="empty-state">' + icon('music') + '<p>Memuat…</p></div></div>';

        try {
            var html = '';
            var p = route.path;

            if (p === '/' || p === '') html = await viewHome();
            else if (p === '/search') html = await viewSearch(route.query.q || '');
            else if (p === '/albums') html = await viewAlbums(route.query);
            else if (p.indexOf('/album/') === 0) html = await viewAlbum(parseInt(p.slice(7), 10));
            else if (p === '/artists') html = await viewArtists();
            else if (p.indexOf('/artist/') === 0) html = await viewArtist(parseInt(p.slice(8), 10));
            else if (p === '/songs') html = await viewSongs(route.query);
            else if (p === '/liked') html = await viewLiked(route.query);
            else if (p === '/playlists') html = await viewPlaylists();
            else if (p.indexOf('/playlist/') === 0) html = await viewPlaylist(parseInt(p.slice(10), 10));
            else html = emptyState('search', 'Halaman tidak ditemukan', 'Coba menuju beranda.', '<a class="btn btn-green" href="#/">Ke beranda</a>');

            content.innerHTML = html;
            content.scrollTop = 0;
            afterRender(route);
        } catch (err) {
            content.innerHTML = '<div class="page">' +
                emptyState('info', 'Ups, ada masalah', err.message, '<button class="btn btn-outline" data-act="retry">Coba lagi</button>') +
                '</div>';
        }

        markActiveNav();
        markPlayingRows();
    }

    function afterRender(route) {
        var input = document.getElementById('search-input');
        if (input && route.path === '/search') {
            input.value = route.query.q || '';
            if (route.query.q) input.focus();
        }
        var page = document.querySelector('.page');
        if (page && !page.classList.contains('no-topbar-pad')) page.classList.add('has-topbar');
    }

    window.addEventListener('hashchange', function () {
        var currentKey = location.hash || '#/';
        if (state.lastRouteKey === currentKey) {
            markActiveNav();
            return;
        }
        if (state.navGuard) {
            state.navGuard = false;
            markActiveNav();
            route(true);
            return;
        }
        if (state.navStack[state.navPos] !== location.hash) {
            state.navStack = state.navStack.slice(0, state.navPos + 1);
            state.navStack.push(location.hash);
            state.navPos = state.navStack.length - 1;
        }
        route(true);
        closeSidebar();
    });

    /* ------------------------------------------------------------------ *
     * Views
     * ------------------------------------------------------------------ */

    async function viewHome() {
        var data = await api('/home');
        state.view.songs = [];

        var html = '<div class="page">';

        html += '<h1 class="page-title">' + greeting() + '</h1>';
        html += '<p class="page-sub">' +
            h(data.totals.songs + ' lagu • ' + data.totals.albums + ' album • ' + data.totals.artists + ' artis') +
            (data.totals.durationMs ? ' • ' + h(fmtLong(data.totals.durationMs)) : '') +
        '</p>';

        var hasAny = data.recentlyPlayed.length || data.recentAlbums.length || data.topSongs.length || data.topArtists.length;

        if (!hasAny) {
            html += emptyState('music',
                data.totals.songs === 0 ? 'Pustaka masih kosong' : 'Belum ada riwayat dengar',
                data.totals.songs === 0
                    ? 'Folder musik belum dipindai. Jalankan pemindaian untuk mulai.'
                    : 'Putar beberapa lagu dan riwayatmu akan muncul di sini.',
                state.user.is_admin
                    ? '<button class="btn btn-green" data-act="scan">Pindai pustaka</button>'
                    : '');
        } else {
            if (data.recentlyPlayed.length) {
                var recentCtx = ctx(data.recentlyPlayed);
                state.view.songs = data.recentlyPlayed;
                html += '<div class="section">' + sectionHead('Lanjutkan mendengarkan') +
                    trackTable(data.recentlyPlayed.slice(0, 6), { ctxId: recentCtx, thumb: true }) +
                    '</div>';
            }

            if (data.recentAlbums.length) {
                html += '<div class="section">' + sectionHead('Baru ditambahkan', 'goto-albums') +
                    '<div class="card-grid">' + data.recentAlbums.map(albumCard).join('') + '</div></div>';
            }

            if (data.topArtists.length) {
                html += '<div class="section">' + sectionHead('Artis teratasmu', 'goto-artists') +
                    '<div class="card-grid">' + data.topArtists.map(artistCard).join('') + '</div></div>';
            }

            if (data.topSongs.length) {
                var topCtx = ctx(data.topSongs);
                html += '<div class="section">' + sectionHead('Paling sering diputar') +
                    trackTable(data.topSongs, { ctxId: topCtx, thumb: true }) +
                    '</div>';
            }
        }

        html += '</div>';
        return html;
    }

    async function viewAlbums(query) {
        var sort = query.sort || 'recent';
        var data = await api('/albums?sort=' + encodeURIComponent(sort) + '&limit=60');
        state.view.songs = [];

        var html = '<div class="page">' +
            '<h1 class="page-title">Album</h1>' +
            '<p class="page-sub">' + data.total + ' album di pustaka</p>' +
            '<div class="section-head" style="margin-bottom:18px">' +
                '<div class="side-chips" style="padding:0">' +
                    chipLink('#/albums?sort=recent', 'Terbaru', sort === 'recent') +
                    chipLink('#/albums?sort=title', 'Judul', sort === 'title') +
                    chipLink('#/albums?sort=artist', 'Artis', sort === 'artist') +
                    chipLink('#/albums?sort=year', 'Tahun', sort === 'year') +
                '</div>' +
            '</div>';

        if (!data.items.length) {
            html += emptyState('disc', 'Belum ada album', 'Pindai folder musikmu untuk mulai.');
        } else {
            html += '<div class="card-grid">' + data.items.map(albumCard).join('') + '</div>';
        }
        return html + '</div>';
    }

    function chipLink(href, label, active) {
        return '<a class="chip' + (active ? ' active' : '') + '" href="' + h(href) + '">' + h(label) + '</a>';
    }

    async function viewAlbum(id) {
        var data = await api('/album/' + id);
        var album = data.album;
        var songs = data.songs;
        state.view.songs = songs;
        var ctxId = ctx(songs);

        var totalSec = songs.reduce(function (n, s) { return n + s.durationMs; }, 0);

        var html =
            '<section class="hero">' +
                (album.cover ? '<img class="hero-blur" src="' + h(album.cover) + '" alt="">' : '') +
                (album.cover
                    ? '<img class="hero-cover" src="' + h(album.cover) + '" alt="">'
                    : '<div class="hero-cover cover-ph" style="--hue:' + hueOf(album.title) + '"><span>' + initial(album.title) + '</span></div>') +
                '<div class="hero-meta">' +
                    '<p class="hero-label">Album</p>' +
                    '<h1 class="hero-title">' + h(album.title) + '</h1>' +
                    '<div class="hero-info">' +
                        '<a data-act="go-artist" data-id="' + album.artist.id + '"><b>' + h(album.artist.name) + '</b></a>' +
                        (album.year ? '<span class="dot">' + album.year + '</span>' : '') +
                        '<span class="dot">' + album.songCount + ' lagu</span>' +
                        '<span class="dot">' + fmtTotal(album.durationMs || totalSec) + '</span>' +
                    '</div>' +
                '</div>' +
            '</section>' +
            '<div class="hero-actions">' +
                '<button class="play-fab" data-act="play-context" data-ctx="' + h(ctxId) + '" aria-label="Putar album">' + icon('play') + '</button>' +
                '<button class="btn btn-outline" data-act="shuffle-context" data-ctx="' + h(ctxId) + '">' + icon('shuffle') + ' Acak</button>' +
                '<button class="icon-btn big" data-act="album-menu" data-id="' + album.id + '" aria-label="Menu album">' + icon('more') + '</button>' +
            '</div>' +
            '<div class="page no-topbar-pad" style="padding-top:14px">' +
                trackTable(songs, { ctxId: ctxId }) +
            '</div>';

        return html;
    }

    async function viewArtists() {
        var data = await api('/artists?limit=60');
        state.view.songs = [];

        var html = '<div class="page">' +
            '<h1 class="page-title">Artis</h1>' +
            '<p class="page-sub">' + data.total + ' artis di pustaka</p>';

        if (!data.items.length) {
            html += emptyState('mic', 'Belum ada artis', 'Pindai folder musikmu untuk mulai.');
        } else {
            html += '<div class="card-grid">' + data.items.map(artistCard).join('') + '</div>';
        }
        return html + '</div>';
    }

    async function viewArtist(id) {
        var data = await api('/artist/' + id);
        var artist = data.artist;
        var albums = data.albums;
        var topSongs = data.topSongs;
        state.view.songs = topSongs;
        var ctxId = ctx(topSongs);

        var html =
            '<section class="hero round">' +
                (artist.cover ? '<img class="hero-blur" src="' + h(artist.cover) + '" alt="">' : '') +
                (artist.cover
                    ? '<img class="hero-cover" src="' + h(artist.cover) + '" alt="">'
                    : '<div class="hero-cover cover-ph" style="--hue:' + hueOf(artist.name) + '"><span>' + initial(artist.name) + '</span></div>') +
                '<div class="hero-meta">' +
                    '<p class="hero-label">Artis</p>' +
                    '<h1 class="hero-title">' + h(artist.name) + '</h1>' +
                    '<div class="hero-info">' +
                        '<span>' + artist.albumCount + ' album</span>' +
                        '<span class="dot">' + artist.songCount + ' lagu</span>' +
                    '</div>' +
                '</div>' +
            '</section>' +
            '<div class="hero-actions">' +
                (topSongs.length
                    ? '<button class="play-fab" data-act="play-context" data-ctx="' + h(ctxId) + '" aria-label="Putar">' + icon('play') + '</button>' +
                      '<button class="btn btn-outline" data-act="shuffle-context" data-ctx="' + h(ctxId) + '">' + icon('shuffle') + ' Acak</button>'
                    : '') +
            '</div>' +
            '<div class="page no-topbar-pad" style="padding-top:14px">';

        if (topSongs.length) {
            html += '<div class="section">' + sectionHead('Populer') +
                trackTable(topSongs, { ctxId: ctxId, thumb: true }) + '</div>';
        }

        if (albums.length) {
            html += '<div class="section">' + sectionHead('Diskografi') +
                '<div class="card-grid">' + albums.map(albumCard).join('') + '</div></div>';
        }

        if (!albums.length && !topSongs.length) {
            html += emptyState('mic', 'Belum ada lagu', 'Artis ini belum punya lagu di pustaka.');
        }

        return html + '</div>';
    }

    async function viewSongs(query) {
        var sort = query.sort || 'recent';
        var data = await api('/songs?sort=' + encodeURIComponent(sort) + '&limit=100');
        state.view.songs = data.items.slice();
        var ctxId = ctx(data.items);

        var html = '<div class="page">' +
            '<h1 class="page-title">Semua Lagu</h1>' +
            '<p class="page-sub">' + data.total + ' lagu • klik lagu untuk memutar</p>' +
            '<div class="side-chips" style="padding:0 0 14px">' +
                chipLink('#/songs?sort=recent', 'Terbaru', sort === 'recent') +
                chipLink('#/songs?sort=title', 'Judul', sort === 'title') +
                chipLink('#/songs?sort=artist', 'Artis', sort === 'artist') +
                chipLink('#/songs?sort=album', 'Album', sort === 'album') +
                chipLink('#/songs?sort=plays', 'Paling diputar', sort === 'plays') +
                chipLink('#/songs?sort=duration', 'Terpanjang', sort === 'duration') +
            '</div>' +
            trackTable(data.items, { ctxId: ctxId, thumb: true }) +
        '</div>';

        return html;
    }

    async function viewLiked(query) {
        var data = await api('/liked?limit=100');
        state.view.songs = data.items.slice();
        var ctxId = ctx(data.items);

        var html = '<section class="hero">' +
            '<div class="hero-cover cover-ph" style="--hue:270"><span>' + '♥' + '</span></div>' +
            '<div class="hero-meta">' +
                '<p class="hero-label">Playlist</p>' +
                '<h1 class="hero-title">Lagu Disukai</h1>' +
                '<div class="hero-info"><span>' + data.total + ' lagu</span></div>' +
            '</div>' +
        '</section>';

        if (data.items.length) {
            html += '<div class="hero-actions">' +
                '<button class="play-fab" data-act="play-context" data-ctx="' + h(ctxId) + '">' + icon('play') + '</button>' +
                '<button class="btn btn-outline" data-act="shuffle-context" data-ctx="' + h(ctxId) + '">' + icon('shuffle') + ' Acak</button>' +
            '</div>' +
            '<div class="page no-topbar-pad" style="padding-top:14px">' +
                trackTable(data.items, { ctxId: ctxId, thumb: true }) +
            '</div>';
        } else {
            html += '<div class="page no-topbar-pad">' +
                emptyState('heart', 'Belum ada lagu disukai', 'Tekan hati di lagu mana pun untuk menyimpannya di sini.') +
            '</div>';
        }
        return html;
    }

    async function viewPlaylists() {
        var data = await api('/playlists');
        state.playlists = data.items || [];
        state.view.songs = [];
        renderSideList();

        var html = '<div class="page">' +
            '<div class="section-head" style="margin-top:10px">' +
                '<h1 class="page-title" style="margin:0">Playlist</h1>' +
                '<button class="btn btn-green" data-act="new-playlist">' + icon('plus') + ' Playlist baru</button>' +
            '</div>' +
            '<p class="page-sub">' + data.items.length + ' playlist milikmu</p>';

        if (!data.items.length) {
            html += emptyState('list', 'Belum ada playlist', 'Buat playlist pertamamu untuk mengatur lagu-lagu favorit.',
                '<button class="btn btn-green" data-act="new-playlist">Buat playlist</button>');
        } else {
            html += '<div class="card-grid">' + data.items.map(playlistCard).join('') + '</div>';
        }
        return html + '</div>';
    }

    async function viewPlaylist(id) {
        var data = await api('/playlist/' + id);
        var pl = data.playlist;
        var songs = data.songs;
        state.view.songs = songs;
        state.view.playlist = pl;
        var ctxId = ctx(songs);

        var html =
            '<section class="hero">' +
                (pl.cover ? '<img class="hero-blur" src="' + h(pl.cover) + '" alt="">' : '') +
                (pl.cover
                    ? '<img class="hero-cover" src="' + h(pl.cover) + '" alt="">'
                    : '<div class="hero-cover cover-ph" style="--hue:' + hueOf(pl.name) + '"><span>' + initial(pl.name) + '</span></div>') +
                '<div class="hero-meta">' +
                    '<p class="hero-label">Playlist</p>' +
                    '<h1 class="hero-title">' + h(pl.name) + '</h1>' +
                    (pl.description ? '<p class="hero-info" style="display:block;max-width:560px">' + h(pl.description) + '</p>' : '') +
                    '<div class="hero-info"><span>' + (pl.songCount || 0) + ' lagu</span>' +
                        (pl.durationMs ? '<span class="dot">' + fmtTotal(pl.durationMs) + '</span>' : '') +
                    '</div>' +
                '</div>' +
            '</section>' +
            '<div class="hero-actions">' +
                (songs.length
                    ? '<button class="play-fab" data-act="play-context" data-ctx="' + h(ctxId) + '">' + icon('play') + '</button>' +
                      '<button class="btn btn-outline" data-act="shuffle-context" data-ctx="' + h(ctxId) + '">' + icon('shuffle') + ' Acak</button>'
                    : '') +
                '<button class="icon-btn big" data-act="playlist-menu" data-id="' + pl.id + '" aria-label="Menu playlist">' + icon('more') + '</button>' +
            '</div>' +
            '<div class="page no-topbar-pad" style="padding-top:14px">' +
                (songs.length
                    ? playlistTrackTable(songs, ctxId, pl.id)
                    : emptyState('music', 'Playlist masih kosong', 'Buka lagu mana pun dan pilih “Tambahkan ke playlist”.')) +
            '</div>';

        return html;
    }

    function playlistTrackTable(songs, ctxId, playlistId) {
        var html = '<div class="track-table">' +
            '<div class="track-head">' +
                '<div class="num">#</div><div>Judul</div>' +
                '<div class="album-col">Album</div><div class="like-head"></div>' +
                '<div class="dur">' + icon('clock') + '</div><div class="more-col"></div>' +
            '</div>';
        songs.forEach(function (s, i) {
            html += songRow(s, { index: i, ctxId: ctxId, thumb: true })
                .replace('data-act="song-menu" data-id="' + s.id + '"',
                    'data-act="playlist-song-menu" data-id="' + s.id + '" data-entry="' + (s.entryId || '') + '" data-playlist="' + playlistId + '" data-i="' + i + '"');
        });
        return html + '</div>';
    }

    async function viewSearch(q) {
        if (!q) {
            state.view.songs = [];
            return '<div class="page">' +
                '<h1 class="page-title">Cari</h1>' +
                '<p class="page-sub">Ketik judul lagu, nama album, artis, atau playlist di kolom pencarian.</p>' +
                emptyState('search', 'Mulai mengetik', 'Hasil akan muncul langsung saat kamu mengetik.') +
            '</div>';
        }

        var data = await api('/search?q=' + encodeURIComponent(q));
        state.view.songs = data.songs || [];
        var ctxId = ctx(data.songs || []);

        var html = '<div class="page">' +
            '<h1 class="page-title">Hasil untuk “' + h(q) + '”</h1>';

        var total = (data.songs.length + data.albums.length + data.artists.length + data.playlists.length);
        if (!total) {
            html += emptyState('search', 'Tidak ada hasil', 'Coba kata kunci lain, misalnya nama penyanyi atau judul album.');
            return html + '</div>';
        }

        if (data.songs.length) {
            html += '<div class="section">' + sectionHead('Lagu') +
                trackTable(data.songs, { ctxId: ctxId, thumb: true }) + '</div>';
        }
        if (data.albums.length) {
            html += '<div class="section">' + sectionHead('Album') +
                '<div class="card-grid">' + data.albums.map(albumCard).join('') + '</div></div>';
        }
        if (data.artists.length) {
            html += '<div class="section">' + sectionHead('Artis') +
                '<div class="card-grid">' + data.artists.map(artistCard).join('') + '</div></div>';
        }
        if (data.playlists.length) {
            html += '<div class="section">' + sectionHead('Playlist') +
                '<div class="card-grid">' + data.playlists.map(playlistCard).join('') + '</div></div>';
        }

        return html + '</div>';
    }

    /* ------------------------------------------------------------------ *
     * Modals & menus
     * ------------------------------------------------------------------ */

    function openModal(opts) {
        var root = document.querySelector('.modal-root');
        root.innerHTML =
            '<div class="modal-overlay">' +
                '<div class="modal" role="dialog" aria-modal="true">' +
                    '<div class="modal-head"><h3>' + h(opts.title) + '</h3>' +
                        '<button class="icon-btn" data-act="modal-close" aria-label="Tutup">' + icon('x') + '</button></div>' +
                    '<div class="modal-body">' + opts.body + '</div>' +
                    (opts.foot ? '<div class="modal-foot">' + opts.foot + '</div>' : '') +
                '</div>' +
            '</div>';

        var overlay = root.querySelector('.modal-overlay');
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
        if (opts.onMount) opts.onMount(root.querySelector('.modal'));
        var focusable = root.querySelector('input, textarea, button.btn-green');
        if (focusable) focusable.focus();
    }

    function closeModal() {
        var root = document.querySelector('.modal-root');
        if (root) root.innerHTML = '';
    }

    function closeMenus() {
        document.querySelectorAll('.floating-menu').forEach(function (m) { m.remove(); });
    }

    function openMenu(items, x, y) {
        closeMenus();
        var menu = document.createElement('div');
        menu.className = 'floating-menu user-menu open';
        menu.style.position = 'fixed';
        menu.style.display = 'block';
        menu.style.zIndex = '95';
        menu.innerHTML = items.map(function (it, i) {
            if (it === '-') return '<div style="height:1px;background:rgba(255,255,255,.12);margin:4px 8px"></div>';
            return '<button data-menu-i="' + i + '"' + (it.danger ? ' style="color:#ff8a8a"' : '') + '>' +
                (it.icon ? icon(it.icon) : '') + h(it.label) + '</button>';
        }).join('');

        document.body.appendChild(menu);

        var rect = menu.getBoundingClientRect();
        var px = Math.min(x, window.innerWidth - rect.width - 10);
        var py = Math.min(y, window.innerHeight - rect.height - 10);
        menu.style.right = 'auto';
        menu.style.left = Math.max(8, px) + 'px';
        menu.style.top = Math.max(8, py) + 'px';

        menu.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-menu-i]');
            if (!btn) return;
            var it = items[parseInt(btn.getAttribute('data-menu-i'), 10)];
            closeMenus();
            if (it && it.run) it.run();
        });

        setTimeout(function () {
            document.addEventListener('click', onDoc, { once: true });
            function onDoc(ev) {
                if (!menu.contains(ev.target)) closeMenus();
            }
        }, 0);
    }

    function songMenuItems(song, opts) {
        opts = opts || {};
        var items = [
            { label: 'Putar berikutnya', icon: 'play', run: function () { playNext(song); } },
            { label: 'Tambahkan ke antrean', icon: 'queue', run: function () { addToQueue(song); } },
            { label: song.liked ? 'Hapus dari Lagu Disukai' : 'Suka', icon: song.liked ? 'heart-filled' : 'heart', run: function () { toggleLike(song); } },
            { label: 'Tambahkan ke playlist', icon: 'plus-circle', run: function () { openAddToPlaylist(song); } },
            '-'
        ];
        if (song.album.id) items.push({ label: 'Lihat album', icon: 'disc', run: function () { go('#/album/' + song.album.id); } });
        if (song.artist.id) items.push({ label: 'Lihat artis', icon: 'mic', run: function () { go('#/artist/' + song.artist.id); } });
        if (song.lyrics) items.push({ label: 'Lihat lirik', icon: 'list', run: function () { openLyrics(song); } });

        if (opts.playlistId) {
            items.push('-');
            items.push({ label: 'Naik', icon: 'chevron-down', run: function () { movePlaylistSong(opts.playlistId, opts.index, -1); } });
            items.push({ label: 'Turun', icon: 'chevron-down', run: function () { movePlaylistSong(opts.playlistId, opts.index, 1); } });
            items.push({ label: 'Hapus dari playlist', icon: 'trash', danger: true, run: function () { removePlaylistSong(opts.playlistId, opts.entryId); } });
        }
        return items;
    }

    function openLyrics(song) {
        openModal({
            title: 'Lirik — ' + song.title,
            body: '<div class="lyrics-body">' + h(song.lyrics || 'Lirik tidak tersedia.') + '</div>',
            foot: '<button class="btn btn-outline" data-act="modal-close">Tutup</button>'
        });
    }

    function openAddToPlaylist(song) {
        api('/playlists').then(function (data) {
            state.playlists = data.items || [];
            renderSideList();

            var body = '';
            if (!state.playlists.length) {
                body += '<p style="color:var(--sub);margin:4px 0">Belum ada playlist. Buat yang baru dulu:</p>';
            } else {
                body += state.playlists.map(function (pl) {
                    return '<button class="playlist-pick" data-act="add-to-playlist" data-pl="' + pl.id + '" data-song="' + song.id + '">' +
                        (pl.cover
                            ? '<img src="' + h(pl.cover) + '" alt="">'
                            : '<div class="cover-ph" style="--hue:' + hueOf(pl.name) + '"><span>' + initial(pl.name) + '</span></div>') +
                        '<span><b>' + h(pl.name) + '</b><span>' + (pl.songCount || 0) + ' lagu</span></span>' +
                    '</button>';
                }).join('');
            }

            body += '<div class="field" style="margin-top:6px"><label>Playlist baru</label>' +
                '<input id="new-pl-name" placeholder="Nama playlist…" maxlength="190"></div>';

            openModal({
                title: 'Tambahkan ke playlist',
                body: body,
                foot: '<button class="btn btn-outline" data-act="modal-close">Batal</button>' +
                      '<button class="btn btn-green" data-act="create-and-add" data-song="' + song.id + '">Buat &amp; tambahkan</button>'
            });
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function openCreatePlaylist(afterCreate) {
        openModal({
            title: 'Playlist baru',
            body:
                '<div class="field"><label for="pl-name">Nama</label>' +
                '<input id="pl-name" maxlength="190" placeholder="Misal: Lagu fokus" required></div>' +
                '<div class="field"><label for="pl-desc">Deskripsi (opsional)</label>' +
                '<textarea id="pl-desc" maxlength="1000" placeholder="Tentang playlist ini…"></textarea></div>',
            foot: '<button class="btn btn-outline" data-act="modal-close">Batal</button>' +
                  '<button class="btn btn-green" data-act="do-create-playlist">Buat</button>',
            onMount: function (modal) {
                modal.querySelector('#pl-name').addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        createPlaylist(afterCreate);
                    }
                });
            }
        });
    }

    async function createPlaylist(afterCreate, nameOverride, descOverride, songId) {
        var name = nameOverride || (document.getElementById('pl-name') || {}).value || '';
        var desc = descOverride != null ? descOverride : ((document.getElementById('pl-desc') || {}).value || '');
        name = name.trim();
        if (!name) {
            toast('Nama playlist wajib diisi.', 'error');
            return;
        }
        try {
            var data = await api('/playlists', { method: 'POST', body: { name: name, description: desc.trim() } });
            if (songId) {
                await api('/playlist/' + data.id + '/songs', { method: 'POST', body: { songId: parseInt(songId, 10) } });
            }
            closeModal();
            await loadPlaylists();
            toast('Playlist “' + name + '” dibuat.');
            if (afterCreate === 'go') go('#/playlist/' + data.id);
            else if (typeof afterCreate === 'function') afterCreate(data.id);
            else route(true);
            return data.id;
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function removePlaylistSong(playlistId, entryId) {
        try {
            await api('/playlist/' + playlistId + '/songs/' + entryId, { method: 'DELETE' });
            toast('Dihapus dari playlist.');
            route(true);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function movePlaylistSong(playlistId, index, delta) {
        var songs = (state.view && state.view.songs) || [];
        var target = index + delta;
        if (target < 0 || target >= songs.length) return;
        var order = songs.map(function (s) { return s.entryId; });
        var tmp = order[index];
        order[index] = order[target];
        order[target] = tmp;
        try {
            await api('/playlist/' + playlistId + '/reorder', { method: 'POST', body: { songIds: order } });
            route(true);
        } catch (err) { toast(err.message, 'error'); }
    }

    async function toggleLike(song) {
        if (!song) return;
        var wasLiked = !!song.liked;
        song.liked = !wasLiked;

        // Reflect everywhere this song object is referenced.
        Object.keys(state.contexts).forEach(function (k) {
            state.contexts[k].forEach(function (s) {
                if (s.id === song.id) s.liked = song.liked;
            });
        });
        refreshLikeUI(song);

        try {
            await api('/songs/' + song.id + '/like', {
                method: wasLiked ? 'DELETE' : 'POST',
                body: {}
            });
            if (location.hash.indexOf('#/liked') === 0) route(true);
        } catch (err) {
            song.liked = wasLiked;
            refreshLikeUI(song);
            toast(err.message, 'error');
        }
    }

    function refreshLikeUI(song) {
        document.querySelectorAll('[data-act="toggle-like"][data-id="' + song.id + '"]').forEach(function (btn) {
            btn.classList.toggle('liked', song.liked);
            var use = btn.querySelector('use');
            if (use) use.setAttribute('href', song.liked ? '#i-heart-filled' : '#i-heart');
        });
        var barBtn = document.querySelector('[data-act="toggle-like-bar"]');
        if (barBtn && currentSong() && currentSong().id === song.id) {
            barBtn.classList.toggle('liked', song.liked);
            var u = barBtn.querySelector('use');
            if (u) u.setAttribute('href', song.liked ? '#i-heart-filled' : '#i-heart');
        }
    }

    /* ------------------------------------------------------------------ *
     * Scan
     * ------------------------------------------------------------------ */

    async function runScan() {
        if (state.scanning) return;
        var ok = confirm('Pindai ulang folder musik? File baru akan ditambahkan, tag yang berubah akan diperbarui.');
        if (!ok) return;

        state.scanning = true;
        var btn = document.getElementById('scan-btn');
        var status = document.getElementById('scan-status');
        if (btn) {
            btn.classList.add('running');
            btn.disabled = true;
            btn.querySelector('span').textContent = 'Memindai…';
        }
        if (status) status.textContent = 'Berjalan — jangan matikan server.';

        try {
            var data = await api('/scan', { method: 'POST', body: {} });
            var s = data.stats || {};
            toast('Selesai: ' + (s.added || 0) + ' baru, ' + (s.updated || 0) + ' diperbarui, ' + (s.removed || 0) + ' dihapus.');
            if (status) status.textContent = 'Terakhir: ' + (s.files || 0) + ' file dalam ' + (s.elapsed || '?') + ' dtk.';
            await loadPlaylists();
            route(true);
        } catch (err) {
            toast(err.message, 'error');
            if (status) status.textContent = 'Gagal: ' + err.message;
        } finally {
            state.scanning = false;
            if (btn) {
                btn.classList.remove('running');
                btn.disabled = false;
                btn.querySelector('span').textContent = 'Pindai ulang pustaka';
            }
        }
    }

    async function updateScanStatus() {
        if (!state.user || !state.user.is_admin) return;
        try {
            var data = await api('/scan/status');
            var status = document.getElementById('scan-status');
            if (status && data.scan) {
                if (data.scan.status === 'running') status.textContent = 'Pemindaian berjalan…';
                else status.textContent = (data.scan.finished_at || '').slice(0, 16).replace('T', ' ') +
                    ' • ' + data.scan.songs_added + ' baru';
            }
        } catch (e) { /* ignore */ }
    }

    /* ------------------------------------------------------------------ *
     * Navigation helpers
     * ------------------------------------------------------------------ */

    function go(hash) {
        if (location.hash === hash) route(true);
        else location.hash = hash;
    }

    function closeSidebar() {
        state.sidebarOpen = false;
        var sb = document.getElementById('sidebar');
        if (sb) sb.classList.remove('open');
    }

    /* ------------------------------------------------------------------ *
     * Global event delegation
     * ------------------------------------------------------------------ */

    document.addEventListener('click', async function (e) {
        var menuBtn = e.target.closest('[data-act]');
        if (!menuBtn) {
            var userMenu = document.getElementById('user-menu');
            if (userMenu && !userMenu.contains(e.target)) userMenu.classList.remove('open');
            return;
        }

        var act = menuBtn.getAttribute('data-act');
        var id = menuBtn.getAttribute('data-id');

        switch (act) {
            case 'toggle-sidebar': {
                state.sidebarOpen = !state.sidebarOpen;
                var sb = document.getElementById('sidebar');
                if (sb) sb.classList.toggle('open', state.sidebarOpen);
                break;
            }
            case 'nav-back':
                if (state.navPos > 0) {
                    state.navPos--;
                    var targetBack = state.navStack[state.navPos];
                    if (location.hash !== targetBack) {
                        state.navGuard = true;
                        location.hash = targetBack;
                    }
                    markActiveNav();
                }
                break;
            case 'nav-fwd':
                if (state.navPos < state.navStack.length - 1) {
                    state.navPos++;
                    var targetFwd = state.navStack[state.navPos];
                    if (location.hash !== targetFwd) {
                        state.navGuard = true;
                        location.hash = targetFwd;
                    }
                    markActiveNav();
                }
                break;
            case 'user-menu': {
                e.stopPropagation();
                var um = document.getElementById('user-menu');
                if (um) um.classList.toggle('open');
                break;
            }
            case 'logout':
                try { await api('/auth/logout', { method: 'POST', body: {} }); } catch (err) {}
                location.reload();
                break;
            case 'retry':
                route(true);
                break;

            /* player */
            case 'toggle-play': togglePlay(); break;
            case 'next': nextTrack(false); break;
            case 'prev': prevTrack(); break;
            case 'shuffle': toggleShuffle(); break;
            case 'repeat': cycleRepeat(); break;
            case 'mute':
                if (player.volume > 0) {
                    player._lastVol = player.volume;
                    player.volume = 0;
                } else {
                    player.volume = player._lastVol || 0.7;
                }
                audio.volume = player.volume;
                renderPlayerBar();
                savePlayer();
                break;
            case 'toggle-queue':
                state.queueOpen = !state.queueOpen;
                renderQueuePanel();
                renderPlayerBar();
                break;
            case 'clear-queue':
                player.queue = player.queue.slice(0, player.index + 1);
                player.originalQueue = null;
                renderQueuePanel();
                savePlayer();
                break;
            case 'queue-jump':
                playAt(parseInt(menuBtn.getAttribute('data-i'), 10), true);
                break;

            /* rows & cards */
            case 'row-play': {
                e.stopPropagation();
                var ctxId = menuBtn.getAttribute('data-ctx');
                var i = parseInt(menuBtn.getAttribute('data-i'), 10);
                var songs = state.contexts[ctxId] || [];
                var row = menuBtn.closest('.track-row');
                var songId = row ? parseInt(row.getAttribute('data-song-id'), 10) : null;
                if (currentSong() && songId === currentSong().id) {
                    togglePlay();
                } else if (songs[i]) {
                    setQueue(songs, i, true);
                }
                break;
            }
            case 'play-context': {
                var songs2 = state.contexts[menuBtn.getAttribute('data-ctx')] || [];
                if (songs2.length) setQueue(songs2, 0, true);
                break;
            }
            case 'shuffle-context': {
                var songs3 = state.contexts[menuBtn.getAttribute('data-ctx')] || [];
                if (songs3.length) {
                    if (!player.shuffle) toggleShuffle();
                    setQueue(songs3, Math.floor(Math.random() * songs3.length), true);
                }
                break;
            }
            case 'play-album': {
                e.stopPropagation();
                try {
                    var ad = await api('/album/' + id);
                    if (ad.songs.length) setQueue(ad.songs, 0, true);
                } catch (err) { toast(err.message, 'error'); }
                break;
            }
            case 'play-playlist': {
                e.stopPropagation();
                try {
                    var pd = await api('/playlist/' + id);
                    if (pd.songs.length) setQueue(pd.songs, 0, true);
                } catch (err) { toast(err.message, 'error'); }
                break;
            }
            case 'go-album':
                if (id && id !== 'null' && id !== 'undefined') go('#/album/' + id);
                break;
            case 'go-artist':
                if (id && id !== 'null' && id !== 'undefined') go('#/artist/' + id);
                break;
            case 'go-playlist':
                go('#/playlist/' + id);
                break;
            case 'goto-albums': go('#/albums'); break;
            case 'goto-artists': go('#/artists'); break;

            case 'toggle-like': {
                e.stopPropagation();
                var rowEl = menuBtn.closest('.track-row');
                var ctxKey = rowEl && rowEl.getAttribute('data-ctx');
                var rowIndex = rowEl ? parseInt(rowEl.getAttribute('data-i'), 10) : -1;
                var arr = state.contexts[ctxKey] || [];
                var s = arr[rowIndex];
                if (s) toggleLike(s);
                break;
            }
            case 'toggle-like-bar': {
                var cur = currentSong();
                if (cur) toggleLike(cur);
                break;
            }

            /* menus */
            case 'song-menu': {
                e.stopPropagation();
                var r1 = menuBtn.closest('.track-row');
                var c1 = r1 && r1.getAttribute('data-ctx');
                var i1 = r1 ? parseInt(r1.getAttribute('data-i'), 10) : -1;
                var song1 = (state.contexts[c1] || [])[i1];
                if (song1) {
                    var rect1 = menuBtn.getBoundingClientRect();
                    openMenu(songMenuItems(song1), rect1.left - 180, rect1.bottom + 6);
                }
                break;
            }
            case 'playlist-song-menu': {
                e.stopPropagation();
                var r2 = menuBtn.closest('.track-row');
                var c2 = r2 && r2.getAttribute('data-ctx');
                var i2 = r2 ? parseInt(r2.getAttribute('data-i'), 10) : -1;
                var song2 = (state.contexts[c2] || [])[i2];
                if (song2) {
                    var rect2 = menuBtn.getBoundingClientRect();
                    openMenu(songMenuItems(song2, {
                        playlistId: parseInt(menuBtn.getAttribute('data-playlist'), 10),
                        entryId: parseInt(menuBtn.getAttribute('data-entry'), 10),
                        index: i2
                    }), rect2.left - 180, rect2.bottom + 6);
                }
                break;
            }
            case 'album-menu': {
                var rect3 = menuBtn.getBoundingClientRect();
                openMenu([
                    { label: 'Lihat artis album', icon: 'mic', run: function () {
                        api('/album/' + id).then(function (d) { go('#/artist/' + d.album.artist.id); });
                    } },
                    { label: 'Buka di antrean', icon: 'queue', run: function () {
                        api('/album/' + id).then(function (d) {
                            d.songs.forEach(addToQueue);
                        });
                    } }
                ], rect3.left, rect3.bottom + 6);
                break;
            }
            case 'playlist-menu': {
                var rect4 = menuBtn.getBoundingClientRect();
                openMenu([
                    { label: 'Ubah nama & deskripsi', icon: 'edit', run: function () { openEditPlaylist(parseInt(id, 10)); } },
                    { label: 'Hapus playlist', icon: 'trash', danger: true, run: function () { confirmDeletePlaylist(parseInt(id, 10)); } }
                ], rect4.left, rect4.bottom + 6);
                break;
            }

            /* playlists */
            case 'new-playlist':
                openCreatePlaylist('go');
                break;
            case 'do-create-playlist':
                createPlaylist('go');
                break;
            case 'create-and-add':
                createPlaylist(null, null, null, menuBtn.getAttribute('data-song'));
                break;
            case 'add-to-playlist': {
                var plId = parseInt(menuBtn.getAttribute('data-pl'), 10);
                var songId2 = parseInt(menuBtn.getAttribute('data-song'), 10);
                try {
                    await api('/playlist/' + plId + '/songs', { method: 'POST', body: { songId: songId2 } });
                    closeModal();
                    await loadPlaylists();
                    toast('Ditambahkan ke playlist.');
                    if (location.hash.indexOf('#/playlist/') === 0) route(true);
                } catch (err) { toast(err.message, 'error'); }
                break;
            }

            /* modal */
            case 'modal-close':
                closeModal();
                break;

            /* scan */
            case 'scan':
                runScan();
                break;
        }
    });

    function openEditPlaylist(playlistId) {
        var pl = state.playlists.find(function (p) { return p.id === playlistId; }) || state.view.playlist;
        openModal({
            title: 'Ubah playlist',
            body:
                '<div class="field"><label for="pl-name">Nama</label>' +
                '<input id="pl-name" maxlength="190" value="' + h(pl ? pl.name : '') + '"></div>' +
                '<div class="field"><label for="pl-desc">Deskripsi</label>' +
                '<textarea id="pl-desc" maxlength="1000">' + h(pl ? pl.description : '') + '</textarea></div>',
            foot: '<button class="btn btn-outline" data-act="modal-close">Batal</button>' +
                  '<button class="btn btn-green" id="save-pl">Simpan</button>',
            onMount: function (modal) {
                modal.querySelector('#save-pl').addEventListener('click', async function () {
                    var name = modal.querySelector('#pl-name').value.trim();
                    if (!name) { toast('Nama wajib diisi.', 'error'); return; }
                    try {
                        await api('/playlist/' + playlistId, {
                            method: 'PATCH',
                            body: { name: name, description: modal.querySelector('#pl-desc').value.trim() }
                        });
                        closeModal();
                        await loadPlaylists();
                        toast('Playlist diperbarui.');
                        route(true);
                    } catch (err) { toast(err.message, 'error'); }
                });
            }
        });
    }

    function confirmDeletePlaylist(playlistId) {
        var pl = state.playlists.find(function (p) { return p.id === playlistId; }) || state.view.playlist;
        openModal({
            title: 'Hapus playlist?',
            body: '<p style="margin:4px 0;color:var(--sub)">Playlist <b style="color:#fff">' +
                h(pl ? pl.name : '') + '</b> akan dihapus permanen. Lagunya tetap ada di pustaka.</p>',
            foot: '<button class="btn btn-outline" data-act="modal-close">Batal</button>' +
                  '<button class="btn btn-danger" id="del-pl">Hapus</button>',
            onMount: function (modal) {
                modal.querySelector('#del-pl').addEventListener('click', async function () {
                    try {
                        await api('/playlist/' + playlistId, { method: 'DELETE' });
                        closeModal();
                        await loadPlaylists();
                        toast('Playlist dihapus.');
                        go('#/playlists');
                    } catch (err) { toast(err.message, 'error'); }
                });
            }
        });
    }

    /* Keyboard shortcuts ------------------------------------------------- */
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable) return;

        if (e.code === 'Space') {
            e.preventDefault();
            togglePlay();
        } else if (e.key === 'ArrowRight' && e.shiftKey) {
            nextTrack(false);
        } else if (e.key === 'ArrowLeft' && e.shiftKey) {
            prevTrack();
        } else if (e.key === 'Escape') {
            closeModal();
            closeMenus();
            if (state.queueOpen) { state.queueOpen = false; renderQueuePanel(); }
            closeSidebar();
        }
    });

    /* Row double-click ---------------------------------------------------- */
    document.addEventListener('dblclick', function (e) {
        var row = e.target.closest('.track-row');
        if (!row) return;
        var ctxId = row.getAttribute('data-ctx');
        var i = parseInt(row.getAttribute('data-i'), 10);
        var songs = state.contexts[ctxId] || [];
        if (songs[i]) setQueue(songs, i, true);
    });

    /* Enter on cards ------------------------------------------------------ */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var card = e.target.closest && e.target.closest('.card[data-act]');
        if (card) card.click();
    });

    /* ------------------------------------------------------------------ *
     * Boot
     * ------------------------------------------------------------------ */

    function boot() {
        if (!state.user) {
            renderLogin();
            return;
        }

        restorePlayer();
        renderShell();
        loadPlaylists();

        if (!location.hash) location.hash = '#/';
        state.navStack = [location.hash];
        state.navPos = 0;
        route(true);

        setInterval(updateScanStatus, 60000);
    }

    boot();
})();
