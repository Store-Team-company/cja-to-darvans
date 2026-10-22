# Data Pilot — SiteGround UI update

This is an **update-only ZIP for an existing installed Data Pilot application**, particularly the installation ZIP delivered from the SiteGround installer branch on October 22, 2026.

## Important: Do not reinstall

- Do not open /setup.php or run the installation wizard again.
- Do not replace your MySQL database, .env file, storage/ or public_html/ folder.
- Do not extract the original full-installation ZIP over this installation.
- Do not use this update on a different or customized Data Pilot revision without comparing the changed controller and templates first.

## Files included

- data-pilot-app/app/Http/Controllers/BalencoDataPilotController.php
- data-pilot-app/resources/views/balenco-data-pilot/layout.blade.php
- data-pilot-app/resources/views/balenco-data-pilot/dashboard.blade.php
- data-pilot-app/resources/views/balenco-data-pilot/sync-queue.blade.php

No migrations or Composer dependencies have changed.

## Apply using SiteGround File Manager

1. Take a site backup. At minimum, download copies of the four existing files listed above, and take a SiteGround MySQL backup/snapshot.
2. Open **Site Tools > Site > File Manager** and navigate to the **folder containing data-pilot-app and public_html**.
3. Upload data-pilot-siteground-ui-update.zip and extract it **in that folder**, choosing to overwrite only the included four PHP/Blade files.
4. Keep your existing **data-pilot-app/.env**, **storage/**, **vendor/**, **public_html/** and all uploaded images untouched.
5. Open **your-domain/sync-queue** and reload once (Ctrl+F5). Confirm Sync Queue now appears in the sidebar and remains highlighted while you are on that page.
6. Check Dashboard, Products and CJ API Import. Test the header search using an existing SKU, if products are available.

If the old Blade output is stuck in cache, run the following from the private data-pilot-app directory if SSH is available (or safely clear compiled files in storage/framework/views via File Manager):

    php artisan view:clear

Do not delete .gitignore or any other required Laravel storage subfolder.

## What changes

- Sidebar groups only link to actual pages: Workspace, Connections and Administration.
- Sync Queue is a visible, active sidebar menu item.
- The former Reports/Team placeholders are removed because those pages do not exist.
- The global product search now goes to the actual product-search screen.
- The topbar no longer shows a fake notification count.
- More professional mobile navigation: close on outside click, Escape, or menu selection.
- Sync Queue helps you get started when no items are present and explains when filters return nothing.
- Dashboard sync chart uses real product percentages and shows a clear empty-state CTA.
- Pricing summary reads your saved settings instead of fixed demo figures.
- Queue sorting works with both MySQL and SQLite, with bounded page sizes.

## Rollback

If any page unexpectedly fails, restore the backed-up versions of the four overwritten source files. Then refresh the browser, or clear compiled views if necessary.

**This is a UI/application code update only. It cannot verify or modify the server cron configuration, live CJ credentials or store connection.**
