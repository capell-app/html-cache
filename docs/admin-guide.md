# Using HTML Cache

This guide is for owners and operators who manage how fast pages load. HTML Cache serves a saved copy of each public page so visitors get it quickly. You can see how much of your site is cached, clear a saved copy when a change is not showing, and put a site into maintenance mode while you work. No technical knowledge is needed. Every step uses the labels you see on screen.

## Using HTML Cache (how-to)

### How to check how much of your site is cached

1. On the admin dashboard, find the **HTML cache overview** panel.
2. **Cache coverage** shows how many of your page URLs are saved, with **Tracked cached URLs** and **Uncached URLs** counts.
3. **Cache coverage URLs** lists individual pages and whether each is **Cached** or **Uncached**, along with their **Hits**.
4. The **Regeneration queue** panel shows any pages waiting to be refreshed, with their **Status** such as **Pending**, **Processing**, **Processed**, or **Failed**.

### How to see cache state on the Pages list

1. Go to **Pages** in the admin.
2. Each row shows a small indicator for whether that page is currently saved in the cache.
3. Use this to spot at a glance which pages are not yet cached, without leaving the page list.

### How to find which URLs are cached and what they depend on

1. In the admin sidebar, open the **Insights & health** group.
2. Open **Site Health**, then **Cached pages** beneath it.
3. The **Cache map** lists each cached **URL** with its **Site**, **Language**, when it was **Cached at**, and when it was **Last seen**.
4. The **Dependencies** show which content a page relies on, so you know which pages to clear when that content changes.
5. Use **Resource search** to find the URLs tied to a specific item by its title, name, slug, or ID.

### How to diagnose uncached public pages

Open **Insights & health > Site Health > Uncached public pages**. Access uses the
same cache-view permission and site scope as **Cached pages**. The list expands
enabled CMS URLs with enabled, publicly accessible blueprints over their enabled
site and language domains; it includes page-backed redirects and URLs without a
domain so their blockers remain visible. Application
route templates and URLs discovered by application-specific policies are outside
this package inventory. Public URLs use Core's normalisation, including wildcard paths.

The page and non-rendering `capell:html-cache:diagnose` command share an anonymous
public-request evaluation, independent of the viewing admin's authentication or
session. The page does not render URLs or change cache files. A usable HTML file
excludes a URL even if its dependency index is absent. Missing, expired and empty
files remain visible even if index rows exist. Blocking reasons match the non-rendering diagnose command
for the same URL. Cache-file state, recorded `stale_cached_urls` refresh status,
last error and canonical-path mismatch are displayed separately.
Response-only blockers are known only when a previous refresh recorded them.

**Cached at** comes from the most recent `cached_model_urls.cached_at` value;
**Recorded cached hits** uses the largest dependency-row count for that URL,
because a hit updates every dependency. These records are not durable history:
cache clearing can remove them. **Last sampled render (ms)** measures the actual
downstream public HTML render, excluding lock waits, cache writes and observation
storage. **Estimated origin hit rate** uses weighted hits and misses sampled with
the same rate, over the observation window shown below the percentage. The default
samples one in ten eligible origin requests; edge-cache traffic is not measured,
and neither are cache files the web server serves directly without starting PHP, so
the rate reads lower than the true figure where nginx serves cached HTML from disk.
Recording runs after the response is sent, so it adds no latency to a cache hit.

Only anonymous successful GET HTML responses for known CMS routes are observed.
Query strings, authorisation, session and access-gate visitors, synthetic renders
and diagnostic inspections are excluded. A configured URL bypass or disabled
HTML caching can still produce a measured render. Metrics remain blank until an
eligible request is sampled; a hit alone supplies a rate but no render duration.
Wildcard host aliases share their configured CMS route identity. Observations are
separate from rendering dependencies, so recording them cannot invalidate HTML.

Run package migrations before enabling observations. Set
`CAPELL_HTML_CACHE_OBSERVATIONS=false` to disable recording, or change
`CAPELL_HTML_CACHE_OBSERVATION_SAMPLE_RATE` (minimum 1) to control sampling. Missing
storage or observation failures do not interrupt public responses. The inventory
and `capell:html-cache:diagnose --json` read the same stored metrics. Rows with
measured durations sort slowest first; unknown durations sort last.
`page_urls.hit_count` records redirects and does not supply an HTML-cache hit rate.

### How to clear a cached page when a change is not showing

1. Open **Insights & health > Site Health > Cached pages**.
2. Find the **URL** whose saved copy is out of date.
3. Use **Clear URL** on that row to remove its saved copy for that site and language. Other sites and languages are left alone.
4. The page rebuilds its saved copy the next time a visitor loads it.
5. To clear more than one page, clear each affected URL. **Clear selection** in the Cache Map only resets its filters; it does not clear cached pages.

### How to turn on maintenance mode while you work

1. In the admin sidebar, open the **Insights & health** group, expand **Site Health**, and click **Maintenance cache**.
2. To take every site offline for visitors, use **Prepare pages** if preparation is needed, then **Review and enable** and confirm. To bring them back, click **Exit maintenance**.
3. To take a single site offline instead, use the per-site override controls and **Enable override** or **Disable override** for that site.
4. While maintenance is on, visitors see a static maintenance page instead of the normal site.

### How to prepare the maintenance page in advance

1. Open **Insights & health > Site Health > Maintenance cache**.
2. Click **Prepare pages** to build the static maintenance page for every site, or use **Prepare** for a single site.
3. Use **Edit maintenance page** to change what visitors see while a site is offline, and **Edit 404 page** to change the page shown when an address is not found.
4. Generating ahead of time means the maintenance page is ready the moment you enable maintenance mode.

### How to confirm caching is healthy and safe

1. Go to **Site Health** in the admin.
2. Find the **HTML cache** section.
3. It reports whether the cache storage is in place and whether the public output is safe, including the **Cached public HTML safety** check that confirms no authoring markers leaked into saved pages.
4. A green result means saved pages are being served correctly and contain only public content.

## Troubleshooting

| What you see                                           | What it means                                         | What to do                                                                                          |
| ------------------------------------------------------ | ----------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| A change is not showing on the live site               | The page is still being served from an old saved copy | Open **Cached pages**, find the **URL**, and use **Clear URL**; the page rebuilds on the next visit |
| Cache coverage looks low                               | Some pages have not been saved yet                    | Leave it; pages are saved as visitors load them, or clear and let them rebuild                      |
| The **Regeneration queue** shows **Failed** rows       | A page could not rebuild its saved copy               | Note the **Reason** shown, then ask your developer to look into it                                  |
| Visitors see the maintenance page when they should not | Maintenance mode is still on                          | Open **Maintenance cache** and click **Exit maintenance**, or turn off the per-site override        |
| The maintenance page looks wrong or empty              | It was not generated, or its content needs updating   | Use **Edit maintenance page**, then **Prepare pages**                                               |
| Site Health flags the **HTML cache** section           | The cache storage or safety check needs attention     | Read the message in **Site Health** and pass the remediation note to your developer                 |
