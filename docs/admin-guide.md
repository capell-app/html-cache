# Using HTML Cache

This guide is for owners and operators who manage how fast pages load. HTML Cache serves a saved copy of each public page so visitors get it quickly. You can see how much of your site is cached, clear a saved copy when a change is not showing, and put a site into maintenance mode while you work. No technical knowledge is needed. Every step uses the labels you see on screen.

## Using HTML Cache (how-to)

### How to check how much of your site is cached

1. On the admin dashboard, find the **HTML cache overview** panel.
2. **Cache coverage** shows how many of your page URLs are saved, with **Tracked cached URLs** and **Uncached URLs** counts.
3. **Cache coverage URLs** lists individual pages and whether each is **Cached** or **Uncached**, along with their **Hits**.
4. The **Regeneration queue** panel shows any pages waiting to be refreshed, with their **Status** such as **Pending**, **Processing**, **Processed**, or **Failed**.

![An administrator checks cache overview, coverage, and stale queue widgets.](screenshots/html-cache-dashboard-widgets.png)

### How to see cache state on the Pages list

1. Go to **Pages** in the admin.
2. Each row shows a small indicator for whether that page is currently saved in the cache.
3. Use this to spot at a glance which pages are not yet cached, without leaving the page list.

![An editor sees cache state indicators directly in the core Pages resource.](screenshots/html-cache-page-table-extension.png)

### How to find which URLs are cached and what they depend on

1. In the admin sidebar, open the **Monitoring** group.
2. Open **Maintenance cache**, then **Cached model URLs** beneath it.
3. The **Cache map** lists each cached **URL** with its **Site**, **Language**, when it was **Cached at**, and when it was **Last seen**.
4. The **Dependencies** show which content a page relies on, so you know which pages to clear when that content changes.
5. Use **Resource search** to find the URLs tied to a specific item by its title, name, slug, or ID.

![An administrator reviews model-to-URL cache map rows after a public page has been warmed.](screenshots/html-cache-cached-model-urls.png)

### How to clear a cached page when a change is not showing

1. Open **Monitoring > Maintenance cache > Cached model URLs**.
2. Find the **URL** whose saved copy is out of date.
3. Use **Clear URL** on that row to remove its saved copy for that site and language. Other sites and languages are left alone.
4. The page rebuilds its saved copy the next time a visitor loads it.
5. To clear more than one page, clear each affected URL. **Clear selection** in the Cache Map only resets its filters; it does not clear cached pages.

### How to turn on maintenance mode while you work

1. In the admin sidebar, open the **Monitoring** group and click **Maintenance cache**.
2. To take every site offline for visitors, click **Enable global maintenance**. To bring them back, click **Disable global maintenance**.
3. To take a single site offline instead, use the per-site override controls and **Toggle site override** for that site.
4. While maintenance is on, visitors see a static maintenance page instead of the normal site.

![An administrator clears, warms, or regenerates cached HTML for a site.](screenshots/html-cache-maintenance-cache-page.png)

### How to prepare the maintenance page in advance

1. Open **Monitoring > Maintenance cache**.
2. Click **Generate all maintenance pages** to build the static maintenance page for every site, or use the generate control for a single site.
3. Use **Edit maintenance page** to change what visitors see while a site is offline, and **Edit 404 page** to change the page shown when an address is not found.
4. Generating ahead of time means the maintenance page is ready the moment you enable maintenance mode.

### How to confirm caching is healthy and safe

1. Go to **Site Health** in the admin.
2. Find the **HTML cache** section.
3. It reports whether the cache storage is in place and whether the public output is safe, including the **Cached public HTML safety** check that confirms no authoring markers leaked into saved pages.
4. A green result means saved pages are being served correctly and contain only public content.

![An operator reviews cache-map diagnostics and public-output safety checks in Site Health.](screenshots/html-cache-site-health-cache-map.png)

## Troubleshooting

| What you see                                           | What it means                                         | What to do                                                                                               |
| ------------------------------------------------------ | ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| A change is not showing on the live site               | The page is still being served from an old saved copy | Open **Cached model URLs**, find the **URL**, and use **Clear URL**; the page rebuilds on the next visit |
| Cache coverage looks low                               | Some pages have not been saved yet                    | Leave it; pages are saved as visitors load them, or clear and let them rebuild                           |
| The **Regeneration queue** shows **Failed** rows       | A page could not rebuild its saved copy               | Note the **Reason** shown, then ask your developer to look into it                                       |
| Visitors see the maintenance page when they should not | Maintenance mode is still on                          | Open **Maintenance cache** and click **Disable global maintenance**, or turn off the per-site override   |
| The maintenance page looks wrong or empty              | It was not generated, or its content needs updating   | Use **Edit maintenance page**, then **Generate all maintenance pages**                                   |
| Site Health flags the **HTML cache** section           | The cache storage or safety check needs attention     | Read the message in **Site Health** and pass the remediation note to your developer                      |
