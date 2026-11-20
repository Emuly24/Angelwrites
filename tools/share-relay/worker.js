/**
 * AngelWrites link-preview relay (Cloudflare Workers, free plan).
 *
 * Why: the free host answers WhatsApp, Telegram, X, LinkedIn, Discord and Slack with a JavaScript
 * "browser check" page, so their link previews have no title or picture. Facebook's crawler is let
 * through. This relay asks the site for the page the way Facebook does, and hands the apps a tiny page
 * with the poem's or book's title, description and cover. People who open the link are sent straight
 * on to the real page.
 *
 *   /p/<id>   a poem   -> https://angelwrites.gt.tc/poem_view.php?id=<id>
 *   /b/<id>   a book   -> https://angelwrites.gt.tc/book.php?id=<id>
 *   /s/<page>.php?<query>   any public page on the allow-list below (posts, reflections, events,
 *                           plans, prayer, prompts, videos…) -> https://angelwrites.gt.tc/<page>.php?<query>
 *   /         the home page
 *   /i?u=/path/on/the/site   a picture from the site (the preview image), passed through
 *
 * It stores nothing and reads only public pages. Responses are cached for an hour (pages) or a
 * month (images; their address changes when the cover changes).
 */
const SITE = 'https://angelwrites.gt.tc';
// public pages people share; anything else is sent to the home page
const PAGES = new Set([
  'index.php', 'about.php', 'poem_view.php', 'poetry.php', 'book.php', 'books.php', 'blog.php', 'blog_post.php',
  'reflections.php', 'video_watch.php', 'videos.php', 'events.php', 'plans.php', 'reading_plan.php', 'prayer.php',
  'prompts.php', 'bible_reader.php', 'contact.php', 'newsletter.php', 'community_guidelines.php',
]);
const CRAWLER_UA = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    let m;
    if ((m = url.pathname.match(/^\/(p|b)\/(\d{1,9})\/?$/))) {
      return page(SITE + (m[1] === 'b' ? '/book.php?id=' : '/poem_view.php?id=') + m[2], url, ctx);
    }
    if ((m = url.pathname.match(/^\/s\/([a-z_]{2,40}\.php)$/)) && PAGES.has(m[1])) {
      // only simple query strings (ids, slugs, dates), never anything that could reach a private page
      const q = url.search.replace(/^\?/, '');
      if (q === '' || /^[A-Za-z0-9=&._%-]{1,200}$/.test(q)) return page(SITE + '/' + m[1] + (q ? '?' + q : ''), url, ctx);
    }
    if (url.pathname === '/' || url.pathname === '') return page(SITE + '/', url, ctx);
    if (url.pathname === '/i') return image(url, ctx);
    return Response.redirect(SITE + '/', 302);
  },
};

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const unesc = (s) => String(s).replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

function meta(html, prop) {
  const re = new RegExp('<meta\\s+(?:property|name)="' + prop.replace(/[:.]/g, '\\$&') + '"\\s+content="([^"]*)"', 'i');
  const m = html.match(re);
  return m ? unesc(m[1]) : '';
}

async function page(target, url, ctx) {
  const cache = caches.default;
  const key = new Request(url.origin + url.pathname + url.search, { method: 'GET' });
  const hit = await cache.match(key);
  if (hit) return hit;

  let title = 'AngelWrites', desc = 'Christian writing and community by Angella Bottoman.', img = url.origin + '/i?u=' + encodeURIComponent('/assets/images/og-logo.jpg'), w = '1200', h = '800', type = 'image/jpeg';
  try {
    const r = await fetch(target, { headers: { 'User-Agent': CRAWLER_UA, 'Accept': 'text/html' }, redirect: 'follow' });
    const html = await r.text();
    if (r.ok && !/slowAES|aes\.js/.test(html)) { // a real page, not the host's browser check
      title = meta(html, 'og:title') || title;
      desc = meta(html, 'og:description') || desc;
      const ogi = meta(html, 'og:image');
      if (ogi) {
        const u = new URL(ogi, SITE);
        // a picture on the site goes through this relay too, or the apps would meet the browser check
        img = u.origin === new URL(SITE).origin ? url.origin + '/i?u=' + encodeURIComponent(u.pathname + u.search) : ogi;
        w = meta(html, 'og:image:width') || w; h = meta(html, 'og:image:height') || h; type = meta(html, 'og:image:type');
      }
    }
  } catch (e) { /* keep the defaults; people are still sent on to the page */ }

  const self = url.origin + url.pathname + url.search;
  const body = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>${esc(title)}</title>
<meta name="robots" content="noindex">
<link rel="canonical" href="${esc(target)}">
<meta property="og:site_name" content="AngelWrites">
<meta property="og:type" content="article">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(desc)}">
<meta property="og:url" content="${esc(self)}">
<meta property="og:image" content="${esc(img)}">
<meta property="og:image:secure_url" content="${esc(img)}">
${type ? `<meta property="og:image:type" content="${esc(type)}">` : ''}
<meta property="og:image:width" content="${esc(w)}">
<meta property="og:image:height" content="${esc(h)}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="${esc(title)}">
<meta name="twitter:description" content="${esc(desc)}">
<meta name="twitter:image" content="${esc(img)}">
<meta http-equiv="refresh" content="0;url=${esc(target)}">
</head><body style="font-family:Georgia,serif;text-align:center;padding:40px">
<p><a href="${esc(target)}">Open “${esc(title)}” on AngelWrites</a></p>
<script>location.replace(${JSON.stringify(target)});</script>
</body></html>`;
  const res = new Response(body, { headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'public, max-age=3600' } });
  ctx.waitUntil(cache.put(key, res.clone()));
  return res;
}

async function image(url, ctx) {
  const path = url.searchParams.get('u') || '';
  // only pictures from the site itself, by path
  if (!/^\/[A-Za-z0-9._\/-]*(\?[A-Za-z0-9=&._%-]*)?$/.test(path) || path.includes('..')) return new Response('Not found', { status: 404 });
  const cache = caches.default;
  const key = new Request(url.toString(), { method: 'GET' });
  const hit = await cache.match(key);
  if (hit) return hit;
  try {
    const r = await fetch(SITE + path, { headers: { 'User-Agent': CRAWLER_UA, 'Accept': 'image/*' } });
    const ct = r.headers.get('Content-Type') || '';
    if (r.ok && ct.startsWith('image/')) {
      const res = new Response(r.body, { headers: { 'Content-Type': ct, 'Cache-Control': 'public, max-age=2592000' } });
      ctx.waitUntil(cache.put(key, res.clone()));
      return res;
    }
  } catch (e) { /* fall through */ }
  return new Response('Not found', { status: 404 });
}
