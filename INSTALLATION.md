# Nextcloud Reader - Quick Installation Guide

## Installation Steps

### 1. Prepare the App

First, copy the `reader` folder to your Nextcloud apps directory:

```bash
cd /path/to/your/reader
cp -r . /path/to/nextcloud/apps/reader
cd /path/to/nextcloud/apps/reader
```

### 2. Install PHP Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

If you don't have Composer installed, download it from https://getcomposer.org/

### 3. Install JavaScript Dependencies and Build

```bash
npm install
npm run build
```

Make sure you have Node.js 20+ and npm 10+ installed.

### 4. Set Permissions

Make sure your web server has write access to the app directory:

```bash
chown -R www-data:www-data /path/to/nextcloud/apps/reader
chmod -R 755 /path/to/nextcloud/apps/reader
```

Replace `www-data` with your web server user (e.g., `apache`, `nginx`, or `www`).

### 5. Enable the App

```bash
cd /path/to/nextcloud
php occ app:enable reader
```

Or enable it from the Nextcloud web interface:
1. Go to **Apps** → **Your apps**
2. Find **Reader**
3. Click **Enable**

### 6. Verify Installation

1. Refresh your Nextcloud page
2. You should see a new **Reader** icon in the app menu
3. Click it to start using the app

## Next Steps

1. **Add your first article**: Click the "Add Article" button and paste a URL
2. **Configure RSS feeds**: Add your favorite RSS feeds for automatic article import
3. **Customize settings**: Adjust reading preferences via the settings panel
4. **Set up browser extension**: Configure a Pocket-compatible extension to save articles from your browser

## Troubleshooting

### Common Issues

**App doesn't appear after enabling:**
- Clear browser cache
- Run: `php occ maintenance:repair`
- Check Nextcloud logs: `tail -f /path/to/nextcloud/data/nextcloud.log`

**Build errors:**
- Make sure Node.js 20+ is installed: `node --version`
- Clear npm cache: `npm cache clean --force`
- Delete node_modules and reinstall: `rm -rf node_modules && npm install`

**PHP errors:**
- Check PHP version (needs 8.0+): `php --version`
- Verify all PHP extensions are installed:
  - php-xml
  - php-mbstring
  - php-curl
  - php-json

**Database errors:**
- Run migrations manually: `php occ migrations:execute reader`
- Check database permissions

### Getting Help

- Read the full [README.md](README.md) for detailed documentation
- Check [GitHub Issues](https://github.com/yourusername/nextcloud-reader/issues)
- Visit the [Nextcloud Community Forum](https://help.nextcloud.com/)

## Development Mode

For development, use:

```bash
npm run dev    # Start development build with watch mode
npm run watch  # Watch mode for Vite
```

## Production Optimization

For production deployments:

1. **Optimize autoloader:**
   ```bash
   composer install --no-dev --optimize-autoloader --classmap-authoritative
   ```

2. **Minify assets:**
   ```bash
   npm run build
   ```

3. **Enable caching:**
   - Configure Nextcloud caching (Redis/Memcached recommended)
   - Set up proper browser caching headers

4. **Performance tuning:**
   - Enable PHP OPcache
   - Configure database query caching
   - Use CDN for static assets (optional)

---

**Need more help?** See the complete documentation in [README.md](README.md)
