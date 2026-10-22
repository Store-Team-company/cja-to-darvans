# Data Pilot - SiteGround upload-and-install guide

This package installs on a **new empty SiteGround website or subdomain**. Do **not** extract it over WordPress or any live site. The ZIP already includes production Composer dependencies; **SSH and Composer are not required for a normal first-time installation**.

## Step 1. Prepare your site

1. Create a new SiteGround website or subdomain.
2. In **Site Tools > Security > SSL Manager**, issue/install SSL and verify HTTPS works.
3. In **Site Tools > Devs > PHP Manager**, select PHP **8.2 or 8.3**.
4. In **Site Tools > Site > MySQL**, create an empty database and a dedicated database user; grant permissions to the new user on the database.
5. Save the MySQL database, username, password, host (normally localhost), and port (3306). Never send these credentials in chat or post them on GitHub.

## Step 2. Upload the installation ZIP

1. Open the **SiteGround package** GitHub Actions run and download its artifact. The artifact may contain another ZIP named **data-pilot-siteground.zip**.
2. Go to **Site Tools > Site > File Manager**. Open the folder **containing** the website's public_html folder (often /home/customer/www/YOUR-DOMAIN/).
3. Upload data-pilot-siteground.zip to this parent directory and extract it there, merging the new public_html folder with the empty existing public_html.
4. Verify this folder layout:

    YOUR-DOMAIN/
       data-pilot-app/          (PRIVATE; Laravel, vendor, .env after setup)
          app/
          bootstrap/
          config/
          database/
          storage/
          vendor/
          artisan
       public_html/             (PUBLIC; domain's document root)
          .htaccess
          index.php
          setup.php
          bdp-assets/
       INSTALLATION-CODE.txt    (PRIVATE; one-time setup code)
       SITEGROUND_INSTALL.md

**Never move data-pilot-app, vendor, storage or .env into public_html.** This keeps application credentials private.

## Step 3. Run the one-time browser wizard

1. Visit **https://YOUR-DOMAIN/setup.php**. The wizard refuses plain HTTP.
2. Open the private INSTALLATION-CODE.txt file using File Manager and enter its unique code into the wizard.
3. Confirm server checks are green. In particular PHP must write to the private application folder, storage folders and bootstrap/cache.
4. Enter HTTPS site URL, MySQL host/port, database name, database username/password, and the new admin username/password (12+ characters).
5. Click **Install Data Pilot**. The wizard verifies MySQL, writes a private Laravel configuration, generates unique encryption/consumer keys, saves a password hash, runs the database migrations, creates the initial admin profile, and disables itself.
6. Once successful, sign in at **https://YOUR-DOMAIN/login**.

**Cleanup:** Delete INSTALLATION-CODE.txt, public_html/setup.php and the uploaded ZIP from the server after installation. Keep your private Laravel application folder.

## Step 4. Connect CJ Dropshipping

1. Sign in to Data Pilot.
2. Open **CJ Settings** and enter your CJ Dropshipping API key.
3. Click **Get Access Token** then **Test Connection**.
4. Open **CJ API Import**, search for a product, and import it as a draft.
5. Review products, variants, inventory, images and costs before transferring anything to the destination store.

The separately implemented WooCommerce-compatible CJA authorization flow has additional security restrictions and needs CJA's real callback domain verified before use. The direct CJ product API is separate.

## Optional store-sync cron

If you use the application queue to push products to a connected store, add a SiteGround cron task under **Devs > Cron Jobs** with your actual app path, e.g.:

    cd /home/customer/www/YOUR-DOMAIN/data-pilot-app && php artisan bdp:sync-queued-products --limit=1

Running every five minutes is typically enough. Cron is not required for manual CJ product imports.

## Troubleshooting

- **HTTPS error:** Enable SSL in Site Tools and open the HTTPS version of the website.
- **Vendor missing:** Use the GitHub Actions release artifact, not the repository's Source Code ZIP.
- **Database error:** Verify the MySQL user, database privileges, host, port and password in Site Tools.
- **Writable-folder error:** Fix ownership/permissions for data-pilot-app, storage and bootstrap/cache.
- **Migration interrupted:** Fix the problem and retry the wizard with the same code; it retains a private recovery marker.
- **Images missing:** Confirm public_html/storage points to data-pilot-app/storage/app/public; some hosts require SSH for a symlink.
- **HTTP 500 after install:** Check data-pilot-app/storage/logs/laravel.log and the PHP version.
- **Installer disabled:** This is expected after successful installation; use the authenticated application settings page.

**Security note:** A previous repository version contained database credentials in version-controlled config/database.php. If these were valid, rotate the database user password. Fixing the latest source does not erase Git history.

Official SiteGround Laravel documentation: https://www.siteground.com/kb/install-laravel
