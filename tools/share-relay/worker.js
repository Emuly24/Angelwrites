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
 *   /i?u=/path/on/the/site   a picture from the site (the preview image), passed through
 *
 * It stores nothing and reads only public pages. Responses are cached for an hour (pages) or a
 * month (images; their address changes when the cover changes).
 */
const SITE = 'https://angelwrites.gt.tc';
const CRAWLER_UA = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    let m;
    if ((m = url.pathname.match(/^\/(p|b)\/(\d{1,9})\/?$/))) return page(m[1], m[2], url, ctx);
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

async function page(kind, id, url, ctx) {
  const target = SITE + (kind === 'b' ? '/book.php?id=' : '/poem_view.php?id=') + id;
  const cache = caches.default;
  const key = new Request(url.origin + url.pathname, { method: 'GET' });
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

  const self = url.origin + url.pathname;
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
