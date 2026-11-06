# Typecho Handsome 天气控件

给 Typecho 的 Handsome 主题加一个右侧栏天气卡片：**零模板改动、零 API Key、零第三方私有接口**。每个访客看到的是自己那儿的天气。

![控件效果](screenshot.jpg)

服务端从 CDN 回源头取访客 IP → 查归属地坐标 → 请求 [Open-Meteo](https://open-meteo.com/en/docs)，按坐标分桶缓存。样式和脚本通过主题自带的 `customCss` / `customJs` 注入，主题升级不会覆盖，删掉标记块即可卸载。

---

## 按访客 IP 定位，这件事我绕了一圈

最开始我照着别人站的控件抄，那套实现是「前端无参数 GET → 服务端读 IP → IP 库换坐标 → 天气 API」。实测下来两个问题：那个私有接口多次返回 `weather: []`，前端一直转圈；偶尔给回数据时，卡片上的城市名和用于查询的坐标相差 **773.6 km**。我当时的结论是「砍掉 IP 定位，坐标写死」，卡片标一个固定城市名。

后来发现这个结论下歪了：**错的是那个接口，不是 IP 定位这件事**。温度数值本身没问题（把错配坐标送进 Open-Meteo，返回 16.7° 晴，和接口给的 16.6° 几乎一致），是算错了地方。而坐标写死，等于让每个访客都看站长的天气——目标本来就是「访客当地天气」，写死坐标是答非所问。

于是把定位接回来了，但换了实现：

| 环节 | 做法 |
| --- | --- |
| 取真实 IP | 优先 `EO-Connecting-IP`（EdgeOne）、`CF-Connecting-IP`、`X-Forwarded-For`、`X-Real-IP`，最后才 `REMOTE_ADDR` |
| 为什么 | 站点前面有 CDN 时 `REMOTE_ADDR` 是**边缘节点**的 IP，不是访客的。实测回源头里 `EO-Connecting-IP = 153.37.203.176`、`REMOTE_ADDR = 114.66.247.140`，用后者定位会全站显示同一个城市 |
| 主定位源 | `ipwho.is/{ip}`，HTTPS、免 Key、直接给 `latitude` / `longitude` |
| 备用源 | `ipinfo.io/{ip}/json`，用 `loc` 字段的 `lat,lon` |
| 中文城市名 | `whois.pconline.com.cn` 返回 GBK，转 UTF-8 后取 `city`；境外 IP 保留英文城市名，常见城市有内置映射 |
| 防错配 | 两个 IP 库对同一个 IP 可能给出完全不同的归属地。用 pconline 的 `proCode` 前两位（国标行政区划码）和 ipwho.is 的 `region_code` 比对，省份对不上就判定错配，退回英文城市名的中文映射，不让名字和温度来自两个地方 |
| 降级 | 两个定位源都挂 → 回退到 `$FALLBACK_*` 常量坐标，返回 `fallback=1`，卡片底部标注「未能定位，显示默认城市」 |

所有 IP 先过 `filter_var(..., FILTER_VALIDATE_IP)` 且排除私网段才拼进上游 URL；客户端**不能**传坐标或 URL 进来，没有 SSRF 面。

### 两级缓存

- **IP 段 → 位置**：按 IPv4 前 3 段 / IPv6 前 4 组做 key，缓存 24 小时。同城同段没必要反复查定位接口；缓存里只留段前缀，不存完整 IP。
- **坐标 → 天气**：按 `round(lat,3),round(lon,3)` 做 key，缓存 15 分钟。不同城市各自一份，不会互相覆盖。

`/weather.php` 输出 `Cache-Control: no-store, private` 并带 `Vary: EO-Connecting-IP, X-Forwarded-For`。实测 EdgeOne 对该路径是 `EO-Cache-Status: MISS`，没有串味；万一以后 CDN 规则改成按 URL 缓存，`Vary` 也能兜住。

### 精度和隐私的边界

IP 定位拿到的是**运营商出口**位置，精度到城市级，移动网络下落到邻市很常见。想精确到街道只能上浏览器 Geolocation 授权，代价是多一个权限弹窗，多数访客会拒绝，本项目没做。

访客 IP 会被发给 `ipwho.is` / `ipinfo.io` / `pconline` 用来查归属地，这是这类控件的固有代价；服务端不写访问日志，也不把完整 IP 落到缓存文件里。

---

## 文件

| 文件 | 作用 | 放哪 |
| --- | --- | --- |
| `weather.php` | 服务端代理：取访客 IP、定位、请求 Open-Meteo、两级缓存、降级、WMO 码转中文 | Typecho 站点根目录 |
| `weather.css` | 卡片样式，跟随 Handsome widget 规范与 grass 配色，含暗色模式 | 注入 `customCss` |
| `weather.js` | 挂载、渲染、SVG 图标、刷新、PJAX 重挂 | 注入 `customJs` |
| `install.php` | 幂等安装脚本，把上面两个文件写进主题设置并回读校验 | 任意目录，执行一次 |

### 数据链路

```
浏览器 ──GET /weather.php──> 站点 PHP
                              │ ① 从 CDN 回源头取访客 IP
                              │ ② IP 段缓存(24h) 未命中 → ipwho.is → ipinfo.io → 常量兜底
                              │    (CN 时再问 pconline 拿中文城市名)
                              │ ③ 坐标缓存(15min) 未命中 → Open-Meteo /v1/forecast
                              │    timezone=auto + current + daily
浏览器 <──同源 JSON── 卡片渲染（localStorage 再缓存 10 min）
```

`force=1` 只影响是否跳过天气缓存，且距上次真实回源不满 20 秒时不生效，防止刷新按钮被点爆上游。

---

## 安装

前置：Typecho + Handsome 主题（主题目录名为 `handsome`）、PHP 7.4+、PHP cURL（没有 cURL 时会自动退回 `file_get_contents`）、`mbstring` 或 `iconv`（pconline 的 GBK 转码用，两者都没有就退回英文城市名）。**先备份数据库**，脚本会改 `typecho_options` 里的一行。

1. 把 `weather.php` 复制到站点根目录，改顶部三个**兜底**常量（只在定位全部失败时生效）：

   ```php
   $FALLBACK_CITY = '本地';
   $FALLBACK_LAT  = 39.9042;   // 示例纬度，换成你自己的
   $FALLBACK_LON  = 116.4074;  // 示例经度，换成你自己的
   ```

   浏览器直接访问 `/weather.php`，应返回自己所在城市的天气：

   ```json
   {"ok":1,"city":"南京","temp":18.4,"desc":"大部晴朗","geo":"ipwho.is","fallback":0,"source":"Open-Meteo"}
   ```

   想验证不同访客确实拿到不同结果，可以绕过 CDN 直连 nginx 并伪造回源头：

   ```bash
   curl -s -H 'Host: your-domain.com' -H 'EO-Connecting-IP: 114.114.114.114' http://127.0.0.1/weather.php
   curl -s -H 'Host: your-domain.com' -H 'EO-Connecting-IP: 8.8.8.8'        http://127.0.0.1/weather.php
   ```

2. 把 `weather.css`、`weather.js` 和 `install.php` 放在同一目录，执行：

   ```bash
   php install.php /你的/typecho/站点根目录
   # 可选：附带 HTTP 自检（绕过 CDN 直连本机 nginx）
   php install.php /你的/typecho/站点根目录 your-domain.com
   ```

   脚本会读站点 `config.inc.php` 拿数据库连接信息（不硬编码任何凭据）、备份原主题配置、用 `JG-WEATHER-START/END` 标记块幂等替换 `customCss` / `customJs`，最后回读校验字段数没变、标记各只出现一次。重复执行是安全的。

3. 前台刷新。若仍显示旧内容，清一次 CDN 缓存（建议 HTML 不缓存）或 Ctrl+F5。

**不想跑脚本也行**：把 `weather.css` 全文粘进主题设置「自定义 CSS」、`weather.js` 全文粘进「自定义 JavaScript」，效果一样。标记块只是为了让卸载方便。

### 卸载

从主题设置的 `customCss` / `customJs` 里删掉 `/* JG-WEATHER-START */` 到 `/* JG-WEATHER-END */` 之间的内容，再删掉站点根目录的 `weather.php`。备份在 `站点根目录/../weather-backup/`；临时缓存 `sys_get_temp_dir()/jg_geo_prefix_v1.json` 和 `jg_weather_*.json` 可直接删。

---

## API 与限流

依据 Open-Meteo 官方文档核对，日期 **2026-11-06**：

- 普通调用**不需要 API Key**；`apikey` 仅商业客户访问保留资源时才需要。
- 免费档原文：`Only for non-commercial use and less than 10.000 daily API calls.` 商业使用请购买其商用许可。
- `current` 支持所有 hourly 变量；本项目取 `temperature_2m`、`relative_humidity_2m`、`apparent_temperature`、`weather_code`、`is_day`、`wind_speed_10m`、`wind_direction_10m`。当前实况基于 15 分钟模型数据，返回里的 `interval=900` 就是它。
- `daily` 取 `temperature_2m_max` / `temperature_2m_min`。**带 daily 字段时 `timezone` 是必需的**，这里传 `timezone=auto` 让 Open-Meteo 按坐标选当地时区——否则境外访客的「今日高低温」和更新时间会按北京时间算。`forecast_days=1`（范围 0–16，默认 7）。
- `weather_code` 只返回数字，文字描述要客户端自己映射，官方给了 WMO 表。`weather.php` 里的中文映射按该表对齐，包含容易漏掉的 `97`（强雷雨）；`96` / `99` 分别对应伴小冰雹、伴大冰雹的雷雨。
- 页面上的日期也不再取服务器 `date()`，改用返回里 `daily.time[0]` 拆月日，避免时区串味。

每个坐标桶按 15 分钟缓存算，一天最多回源 `24 × 60 / 15 = 96` 次；城市越多桶越多，但个人博客的实际城市数有限。定位接口有 24 小时 IP 段缓存，不会每个页面请求都打过去。前端另有 10 分钟 `localStorage` 缓存。

---

## 实现里几个踩过的点

- **CDN 后的 `REMOTE_ADDR` 不是访客**。EdgeOne 回源时真实 IP 在 `EO-Connecting-IP` 和 `X-Forwarded-For`，`REMOTE_ADDR` 是边缘节点。先用探针 PHP 把 `$_SERVER` 里所有 IP 相关头打出来确认，再决定优先级。
- **X-Forwarded-For 可能是逗号串**，要逐段取第一个公网 IP，并且每段都过 `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`，别把伪造的私网地址送出去。
- **pconline 返回 GBK**，直接 `json_decode` 会失败，必须先 `mb_convert_encoding($raw,'UTF-8','GBK')`；它也没有经纬度，只能拿来补中文城市名。
- **两个 IP 库会打架，名字和坐标必须同源**。实测 `119.29.29.29`：ipwho.is 说广东广州（23.12, 113.25），pconline 说北京市（腾讯注册地），拼起来就是「北京市 30.1°C」——11 月的北京不可能。现在的做法是用 `proCode` 前两位查国标省份码，跟 ipwho.is 的 `region_code` 比对，不一致就丢弃中文名、退回英文映射表（Guangzhou → 广州）。`114.114.114.114`（南京）、`223.5.5.5`（杭州）两库一致，正常显示中文市名。
- **天气缓存命中也要重写位置字段**。缓存按坐标分桶，同一坐标可能被不同城市名的请求命中，直接吐旧 JSON 会把第一个访客的城市名带给后来者。
- **境外访客要 `timezone=auto`**。实测 `8.8.8.8` 拿到 `San Jose` 当地 00:30、`is_day=0`，`1.1.1.1` 拿到 `Brisbane` 当地 18:30，都和北京时间不同；写死 `Asia/Shanghai` 会把人家的「今日高低温」和更新时间算错。
- **暗色模式不能靠背景色猜**。第一版读 `getComputedStyle(document.body).backgroundColor`，结果 body 常是透明 `rgba(0,0,0,0)`，被算成深色，白卡片直接变黑。改成检测 Handsome 固定加在 `<html>` 上的 `theme-dark` 类，再用主题 CSS 变量做兜底。
- **全宽模板没有右侧栏**。Handsome 的 `cross.php`（时光机）等模板不输出 `#sidebar`，控件自然不显示。这是跟随主题的正确行为，硬塞进去反而不协调。
- **PJAX 换页会冲掉卡片**。监听 `pjax:end` / `pjax:complete`，再加 `MutationObserver` 兜底重挂。
- **风格跟着主题走，别把原主题的皮一起搬**。原实现是紫色玻璃拟态 + 雨雪粒子动画 + 温度滚动数字；这里全去掉，只留「按 WMO 天气码微调顶部渐变条和图标颜色」，其余按 Handsome 的 widget 规范来：白底、6px 圆角、细阴影、内联 SVG 图标。
- **上游挂掉要能用**。缓存过期但回源失败时，返回旧数据并标 `stale=1`；定位源全挂时回退常量坐标并标 `fallback=1`；完全没缓存才显示重试入口。

## 已验证环境

Typecho 1.3.0 + Handsome 10.1.0 + PHP 8.2 + EdgeOne CDN，桌面与移动宽度、浅色与暗色模式、首页 / 文章页 / 独立页面、PJAX 换页。境内服务器实测 `ipwho.is` 0.58s、`pconline` 0.18s、`ipinfo.io` 1.37s、Open-Meteo 0.79s 均可直连。其他 Typecho / Handsome 版本未逐个验证；主题目录名不是 `handsome` 时改 `install.php` 顶部的 `OPTION_NAME`。没有 CDN 时 `REMOTE_ADDR` 优先级兜底仍然可用。

---

## 许可

MIT，见 [LICENSE](LICENSE)。

天气数据由 Open-Meteo 提供，使用时遵守其免费档的非商业条款；本项目与 Open-Meteo 无关联。IP 归属地数据来自 ipwho.is / ipinfo.io / pconline，各自条款以其站点为准。参数与额度以[官方文档](https://open-meteo.com/en/docs)为准，最后核对日期 2026-11-06。
