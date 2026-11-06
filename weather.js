/* JG-WEATHER-START */
/* Typecho Handsome 右侧栏天气控件（含按天气类型变化的动效层）
   数据源：同源 /weather.php（服务端按访客 IP 定位 + Open-Meteo）
   挂载点：#sidebar 第一个子元素；PJAX 换页后由 MutationObserver 自动重挂 */
(function () {
    var ID = 'jg-weather', KEY = 'jg_weather_v1', TTL = 600000;
    var ENDPOINT = '/weather.php';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function wtype(c, day) {
        if (c === 0 || c === 1 || c === 2) return day ? (c === 0 ? 'sunny' : 'cloudy') : 'night';
        if (c === 3) return 'overcast';
        if (c === 45 || c === 48) return 'fog';
        if ((c >= 51 && c <= 67) || (c >= 80 && c <= 82)) return 'rain';
        if ((c >= 71 && c <= 77) || (c >= 85 && c <= 86)) return 'snow';
        if (c >= 95) return 'thunder';
        return 'cloudy';
    }
    var COLOR = {
        sunny: '#d9a352', cloudy: '#7e9daa', overcast: '#8a9b93', fog: '#98a6ae',
        rain: '#638faa', snow: '#83aec6', thunder: '#8b7ea6', night: '#6b7c9b'
    };
    var CLOUD = 'M6.5 16.5h10a3 3 0 0 0 0-6 4.5 4.5 0 0 0-8.6-1A3.4 3.4 0 0 0 6.5 16.5z';
    function svg(type) {
        var body;
        switch (type) {
            case 'sunny':
                body = '<g class="jg-w-rays"><path d="M12 2.5v2M12 19.5v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2.5 12h2M19.5 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></g>' +
                       '<circle cx="12" cy="12" r="4"/>'; break;
            case 'night':
                body = '<path d="M20 14.6A8.6 8.6 0 0 1 9.4 4a8.6 8.6 0 1 0 10.6 10.6z"/><path d="M17 5.5h.01M19.5 8h.01"/>'; break;
            case 'cloudy':
                body = '<circle cx="8.4" cy="7.6" r="2.4"/><path d="M8.4 3.4v1.2M4.2 7.6h1.2M11.6 7.6h1.2"/><path d="M9 18h8.2a3 3 0 0 0 0-6 4.4 4.4 0 0 0-8.3-1A3.4 3.4 0 0 0 9 18z"/>'; break;
            case 'overcast':
                body = '<path d="M6 15.5h9a3 3 0 0 0 0-6 4.4 4.4 0 0 0-8.3-1A3.4 3.4 0 0 0 6 15.5z"/><path d="M9 19h9a3 3 0 0 0 0-6"/>'; break;
            case 'fog':
                body = '<path d="' + CLOUD + '"/><path d="M5 19.5h14M8 22h9"/>'; break;
            case 'rain':
                body = '<path d="' + CLOUD + '"/><path d="M8.5 19.5l-1 2.2M12.5 19.5l-1 2.2M16.5 19.5l-1 2.2"/>'; break;
            case 'snow':
                body = '<path d="' + CLOUD + '"/><path d="M8 20v2M7 21h2M12 20v2M11 21h2M16 20v2M15 21h2"/>'; break;
            case 'thunder':
                body = '<path d="' + CLOUD + '"/><path d="M13 18.5l-2 3h3l-1 2.2"/>'; break;
            default:
                body = '<path d="' + CLOUD + '"/>';
        }
        return '<svg viewBox="0 0 24 24" fill="none" stroke="' + (COLOR[type] || COLOR.cloudy) +
               '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
    }
    function looksDark() {
        // Handsome 的暗色模式固定给 <html> 加 .theme-dark（dark_setting=auto 时也走这个）
        // 不能用 body 背景色猜：它常是透明 rgba(0,0,0,0)，会被误判成深色
        var h = document.documentElement;
        if (h.classList && h.classList.contains('theme-dark')) return true;
        var cls = (h.className || '') + ' ' + (document.body.className || '');
        if (/\btheme-dark\b|\bdark\b|\bnight\b/i.test(cls)) return true;
        try {
            var v = (getComputedStyle(h).getPropertyValue('--bg-main') || '').trim();
            var m = v.match(/^#([0-9a-f]{6})$/i);
            if (m) {
                var n = parseInt(m[1], 16);
                return ((n >> 16) * .299 + ((n >> 8) & 255) * .587 + (n & 255) * .114) < 128;
            }
        } catch (e) {}
        return false;
    }
    function reducedMotion() {
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) { return false; }
    }

    /* ---------- 动效层：按天气类型生成粒子 ---------- */
    // 确定性伪随机：同一天气每次刷新位置一致，不会跳变，也便于回归测试
    function prnd(k) {
        var x = Math.sin(k * 127.1 + 311.7) * 43758.5453;
        return x - Math.floor(x);
    }
    var FX = {
        sunny:    [4, 'glow'],
        cloudy:   [3, 'cloud'],
        overcast: [3, 'cloud'],
        fog:      [4, 'fog'],
        rain:     [12, 'rain'],
        snow:     [10, 'snow'],
        thunder:  [14, 'rain'],
        night:    [8, 'star']
    };
    var FXSEED = { sunny: 11, cloudy: 23, overcast: 37, fog: 41, rain: 53, snow: 67, thunder: 71, night: 83 };
    function buildFx(box, type) {
        var fx = box.querySelector('.jg-w-fx');
        if (!fx) return;
        var spec = FX[type];
        if (!spec) { fx.innerHTML = ''; return; }
        var n = spec[0], cls = spec[1], seed = FXSEED[type] || 1, h = '';
        for (var i = 0; i < n; i++) {
            var left  = (prnd(seed + i * 4 + 1) * 100).toFixed(1);
            var delay = (prnd(seed + i * 4 + 2) * 4).toFixed(2);
            var dur   = (2.2 + prnd(seed + i * 4 + 3) * 3.2).toFixed(2);
            var size  = (2 + prnd(seed + i * 4 + 4) * 2.6).toFixed(1);
            // 负 delay：页面一打开粒子就已经在动，不必先等几秒空窗
            // 雾带是横贯卡片的整条，用 CSS 里的固定 left，不参与随机分布
            var style = 'animation-delay:-' + delay + 's;' +
                        'animation-duration:' + dur + 's;--p-size:' + size + 'px';
            if (cls !== 'fog') style = 'left:' + left + '%;' + style;
            if (cls === 'star' || cls === 'cloud' || cls === 'fog' || cls === 'glow') {
                style += ';top:' + (10 + prnd(seed + i * 4 + 5) * 72).toFixed(1) + '%';
            }
            h += '<i class="jg-w-p jg-w-p-' + cls + '" style="' + style + '"></i>';
        }
        fx.innerHTML = h;
    }
    // 数据到位后重播分层入场；强制 reflow 让动画能连续触发
    function replay(box) {
        if (reducedMotion()) { box.classList.remove('jg-w-anim'); return; }
        box.classList.remove('jg-w-anim');
        void box.offsetWidth;
        box.classList.add('jg-w-anim');
        // 兜底：入场动画是 backwards 延迟播放，若引擎把动画节流/冻结在首帧，
        // 文字会一直停在 opacity:0。超时后直接摘掉类，回到默认可见样式。
        clearTimeout(box._jgAnimTimer);
        box._jgAnimTimer = setTimeout(function () {
            box.classList.remove('jg-w-anim');
        }, 1400);
    }
    // 温度数字从略低的值滚到实测值（与 temp-pop 一起构成「活」的感觉）
    function countUp(el, target) {
        if (!el || typeof target !== 'number' || !isFinite(target)) return;
        if (reducedMotion() || !window.requestAnimationFrame) return;
        var node = el.firstChild;
        if (!node || node.nodeType !== 3) return;
        var from = Math.round(target * .55), t0 = null, dur = 620;
        function step(ts) {
            if (t0 === null) t0 = ts;
            var p = Math.min(1, (ts - t0) / dur);
            var e = 1 - Math.pow(1 - p, 3);
            node.nodeValue = String(Math.round(from + (target - from) * e));
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function render(box, d) {
        var type = wtype(d.code, d.is_day);
        box.setAttribute('data-wtype', type);
        box.classList.toggle('jg-dark', looksDark());
        var city = box.querySelector('.jg-w-city');
        if (city) city.textContent = d.city || '本地';

        var temp = (d.temp === null || d.temp === undefined) ? '--' : Math.round(d.temp);
        var feels = (d.feels === null || d.feels === undefined) ? '' :
                    '<div class="jg-w-feels">体感 ' + Math.round(d.feels) + '°</div>';
        var hum = (d.humidity === null || d.humidity === undefined) ? '--' : d.humidity + '%';
        var wind = (d.wind === null || d.wind === undefined) ? '--' :
                   esc(d.winddir || '') + ' ' + d.wind + '<small> km/h</small>';
        var range = (d.low === null || d.high === null || d.low === undefined || d.high === undefined) ?
                    '--' : d.low + ' ~ ' + d.high + '°';
        var foot = esc(d.time || '') + ' 更新 · ' + esc(d.source || 'Open-Meteo') +
                   (d.fallback ? ' <span class="jg-w-stale">（未能定位，显示默认城市）</span>' : '') +
                   (d.stale ? ' <span class="jg-w-stale">（缓存数据）</span>' : '');

        box.querySelector('.jg-w-body').innerHTML =
            '<div class="jg-w-main">' +
                '<div class="jg-w-icon">' + svg(type) + '</div>' +
                '<div class="jg-w-temp">' + temp + '<sup>°</sup></div>' +
                '<div class="jg-w-meta"><div class="jg-w-desc">' + esc(d.desc || '') + '</div>' + feels + '</div>' +
            '</div>' +
            '<div class="jg-w-details">' +
                '<span>湿度 <b>' + hum + '</b></span>' +
                '<span><b>' + wind + '</b></span>' +
                '<span>今日 <b>' + range + '</b></span>' +
            '</div>' +
            '<div class="jg-w-foot">' + foot + '</div>';

        buildFx(box, type);
        replay(box);
        if (typeof temp === 'number') countUp(box.querySelector('.jg-w-temp'), temp);
    }
    function showError(box, msg) {
        box.querySelector('.jg-w-body').innerHTML =
            '<div class="jg-w-error"><span>' + esc(msg || '天气获取失败') + '</span>' +
            '<a class="jg-w-retry">点击重试</a></div>';
        var fx = box.querySelector('.jg-w-fx');
        if (fx) fx.innerHTML = '';
    }
    function load(force) {
        var box = document.getElementById(ID);
        if (!box) return;
        var cached = null;
        try { cached = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
        var body = box.querySelector('.jg-w-body');
        if (!force && cached && cached.data && Date.now() - cached.ts < TTL) {
            render(box, cached.data);
            return;
        }
        body.innerHTML = '<div class="jg-w-loading"><i></i><span>正在获取天气…</span></div>';
        var url = ENDPOINT + '?' + (force ? 'force=1&' : '') + 't=' + Date.now();
        fetch(url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) throw new Error((d && d.error) || '天气数据异常');
                try { localStorage.setItem(KEY, JSON.stringify({ ts: Date.now(), data: d })); } catch (e) {}
                render(box, d);
            })
            .catch(function (e) {
                if (cached && cached.data) render(box, cached.data);
                else showError(box, e.message);
            });
    }
    function mount() {
        var sb = document.getElementById('sidebar');
        if (!sb || document.getElementById(ID)) return;
        var sec = document.createElement('section');
        sec.id = ID;
        sec.className = 'widget widget_categories wrapper-md padder-v-none clear box-shadow-wrap-normal';
        sec.innerHTML = '<div class="jg-w-fx" aria-hidden="true"></div>' +
            '<div class="jg-w-sweep" aria-hidden="true"></div>' +
            '<div class="jg-w-bar"></div>' +
            '<h5 class="widget-title m-t-none">天气<span class="jg-w-city">…</span>' +
            '<button class="jg-w-refresh" type="button" title="刷新天气" aria-label="刷新天气">↻</button></h5>' +
            '<div class="jg-w-body"></div>';
        sec.addEventListener('click', function (ev) {
            var t = ev.target;
            var btn = t.closest && (t.closest('.jg-w-refresh') || t.closest('.jg-w-retry'));
            if (!btn) return;
            ev.preventDefault();
            if (btn.classList.contains('jg-w-refresh')) {
                btn.classList.remove('is-spin');
                void btn.offsetWidth;
                btn.classList.add('is-spin');
            }
            try { localStorage.removeItem(KEY); } catch (e) {}
            load(true);
        });
        sb.insertBefore(sec, sb.firstChild);
        load(false);
    }
    function init() {
        mount();
        ['pjax:end', 'pjax:complete'].forEach(function (ev) {
            document.addEventListener(ev, function () { setTimeout(mount, 80); });
        });
        window.addEventListener('load', mount);
        if (window.MutationObserver) {
            var timer = null;
            var mo = new MutationObserver(function () {
                if (document.getElementById(ID)) return;
                clearTimeout(timer);
                timer = setTimeout(mount, 120);
            });
            mo.observe(document.body, { childList: true, subtree: true });
        }
        // 页面切到后台时暂停循环动画
        document.addEventListener('visibilitychange', function () {
            var b = document.getElementById(ID);
            if (b) b.classList.toggle('jg-w-paused', document.hidden);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
/* JG-WEATHER-END */
