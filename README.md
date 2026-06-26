# Nextcloud Reader

A powerful read-it-later application for Nextcloud that allows you to save, organize, and read web articles in a clean, distraction-free interface.

![Nextcloud Reader](screenshots/main.png)

## Features

### Core Functionality
- **Save Web Articles**: Add articles via URL with automatic content extraction
- **Clean Reading Experience**: Distraction-free, typography-optimized reading mode
- **Article Management**: Filter, search, and organize your saved articles
- **Tags & Organization**: Categorize articles with customizable tags
- **RSS Feed Import**: Automatically import articles from RSS feeds
- **Export Options**: Export articles as HTML

### Reading Experience
- **Dark Mode**: Toggle between light, dark, and auto (system) themes
- **Customizable Typography**: Adjust font size, family, line height, and content width
- **Reading Time**: Estimated reading time for each article
- **Responsive Design**: Optimized for desktop, tablet, and mobile devices

### Advanced Features
- **Browser Extension Support**: Pocket-compatible API for browser extensions
- **Full-Text Search**: Search across titles, content, and authors
- **Favorites**: Star your favorite articles for quick access
- **Archive**: Archive read articles to keep your reading list clean
- **Auto-Mark as Read**: Automatically mark articles as read when opened

## Requirements

- **Nextcloud**: Version 28 or higher
- **PHP**: 8.0 - 8.3
- **Database**: MySQL, PostgreSQL, or SQLite
- **Node.js**: 20.x or higher (for building)
- **npm**: 10.x or higher (for building)

## Installation

### Method 1: From Nextcloud App Store (Recommended)
1. Open your Nextcloud instance
2. Go to **Apps** in the settings menu
3. Search for **Reader**
4. Click **Download and enable**

### Method 2: Manual Installation
1. Download the latest release from [GitHub Releases](https://github.com/yourusername/nextcloud-reader/releases)
2. Extract the archive to your Nextcloud apps directory:
   ```bash
   cd /path/to/nextcloud/apps/
   tar -xzf reader-1.0.0.tar.gz
   ```
3. Install PHP dependencies:
   ```bash
   cd reader
   composer install --no-dev
   ```
4. Build the frontend:
   ```bash
   npm install
   npm run build
   ```
5. Enable the app in Nextcloud:
   ```bash
   php occ app:enable reader
   ```

### Method 3: Development Setup
1. Clone the repository:
   ```bash
   cd /path/to/nextcloud/apps/
   git clone https://github.com/yourusername/nextcloud-reader.git reader
   cd reader
   ```
2. Install dependencies:
   ```bash
   composer install
   npm install
   ```
3. Build for development:
   ```bash
   npm run dev
   ```
4. Enable the app:
   ```bash
   php occ app:enable reader
   ```

## Usage

### Adding Articles

#### Via URL Input
1. Click the **Add Article** button in the toolbar
2. Enter the article URL
3. Click **Add Article** to save

#### Via Browser Extension
Reader supports Pocket-compatible browser extensions. Configure your extension to use:
- **Server URL**: `https://your-nextcloud.com/apps/reader/api/v1`

### Managing Articles

#### Filters
- **All Articles**: View all saved articles
- **Unread**: Show only unread articles
- **Favorites**: Show only starred articles
- **Archived**: Show archived articles
- **By Tag**: Filter by specific tags
- **By Feed**: Filter by RSS feed source

#### Search
Use the search bar to find articles by title, content, or author.

#### Actions
- **Mark as Read/Unread**: Toggle read status
- **Add to Favorites**: Star important articles
- **Archive**: Move articles to archive
- **Export**: Download as HTML
- **Delete**: Permanently remove articles

### RSS Feeds

1. Navigate to **Feeds** in the sidebar
2. Click **Add Feed**
3. Enter the RSS feed URL
4. Configure auto-import settings (optional)
5. Click **Refresh** to import articles

### Settings

Access settings via the gear icon in the bottom-left:

- **Theme**: Light, Dark, or Auto (follows system preference)
- **Font Size**: Small, Medium, Large, Extra Large
- **Font Family**: Default, Serif, Sans Serif, Monospace
- **Max Content Width**: 600-1200px (default: 800px)
- **Line Height**: 1.2-2.0 (default: 1.6)
- **Default View**: All, Unread, or Favorites
- **Articles Per Page**: 10-100 (default: 50)
- **Auto Mark as Read**: Automatically mark articles as read when opened

## API Documentation

### Browser Extension API (Pocket-Compatible)

#### Add Article
```
POST /apps/reader/api/v1/add
Content-Type: application/json

{
  "url": "https://example.com/article",
  "title": "Article Title (optional)",
  "tags": ["tag1", "tag2"] (optional)
}
```

#### Get Articles
```
POST /apps/reader/api/v1/get
Content-Type: application/json

{
  "state": "unread|archive|all",
  "count": 10,
  "offset": 0
}
```

#### Modify Article
```
POST /apps/reader/api/v1/send
Content-Type: application/json

{
  "actions": [
    {
      "action": "archive|readd|favorite|unfavorite|delete",
      "item_id": "123"
    }
  ]
}
```

### REST API

All endpoints require authentication via Nextcloud session.

#### Articles

- `GET /apps/reader/api/articles` - Get all articles
- `GET /apps/reader/api/articles/{id}` - Get single article
- `POST /apps/reader/api/articles` - Create article
  ```json
  {
    "url": "https://example.com/article",
    "tagIds": [1, 2]
  }
  ```
- `PUT /apps/reader/api/articles/{id}` - Update article
- `DELETE /apps/reader/api/articles/{id}` - Delete article
- `PUT /apps/reader/api/articles/{id}/read` - Toggle read status
- `PUT /apps/reader/api/articles/{id}/favorite` - Toggle favorite
- `PUT /apps/reader/api/articles/{id}/archive` - Toggle archive
- `GET /apps/reader/api/articles/search?query=term` - Search articles

#### Tags

- `GET /apps/reader/api/tags` - Get all tags
- `POST /apps/reader/api/tags` - Create tag
- `PUT /apps/reader/api/tags/{id}` - Update tag
- `DELETE /apps/reader/api/tags/{id}` - Delete tag

#### Feeds

- `GET /apps/reader/api/feeds` - Get all feeds
- `POST /apps/reader/api/feeds` - Create feed
- `PUT /apps/reader/api/feeds/{id}` - Update feed
- `DELETE /apps/reader/api/feeds/{id}` - Delete feed
- `POST /apps/reader/api/feeds/{id}/refresh` - Refresh single feed
- `POST /apps/reader/api/feeds/refresh` - Refresh all feeds

## Architecture

### Backend (PHP)

```
reader/
├── lib/
│   ├── Controller/          # HTTP Controllers
│   │   ├── ArticleController.php
│   │   ├── TagController.php
│   │   ├── FeedController.php
│   │   ├── ExtensionController.php
│   │   └── SettingsController.php
│   ├── Service/             # Business Logic
│   │   ├── ContentExtractorService.php
│   │   ├── FeedService.php
│   │   └── ExportService.php
│   ├── Db/                  # Database Layer
│   │   ├── Article.php
│   │   ├── ArticleMapper.php
│   │   ├── Tag.php
│   │   ├── TagMapper.php
│   │   ├── Feed.php
│   │   └── FeedMapper.php
│   └── Migration/           # Database Migrations
```

### Frontend (Vue.js)

```
src/
├── main.js                  # Entry point
├── App.vue                  # Main component
├── store/
│   └── index.js             # Vuex store
├── api/                     # API wrappers
│   ├── articles.js
│   ├── tags.js
│   ├── feeds.js
│   └── settings.js
└── components/
    ├── ArticleList.vue      # Article grid view
    ├── ArticleCard.vue      # Article card
    ├── ArticleReader.vue    # Reading mode
    ├── AddArticleDialog.vue # Add article modal
    └── SettingsDialog.vue   # Settings panel
```

## Development

### Building

```bash
# Development build with watch
npm run dev

# Production build
npm run build

# Watch mode
npm run watch
```

### Code Quality

```bash
# PHP linting
composer run lint

# PHP code style check
composer run cs:check

# PHP code style fix
composer run cs:fix

# JavaScript linting
npm run lint

# JavaScript linting fix
npm run lint:fix

# CSS linting
npm run stylelint

# CSS linting fix
npm run stylelint:fix
```

### Testing

```bash
# Run PHP unit tests
vendor/bin/phpunit

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

## Troubleshooting

### Articles fail to extract
- Check that the URL is publicly accessible
- Some sites may block automated content extraction
- Try using the browser extension to save the full page content

### RSS feeds not updating
- Verify the feed URL is valid
- Check Nextcloud cron is running: `php occ background:cron`
- Manually refresh feeds via the UI

### Build errors
- Ensure Node.js 20+ and npm 10+ are installed
- Clear node_modules and reinstall: `rm -rf node_modules && npm install`
- Clear npm cache: `npm cache clean --force`

### Database errors
- Run migrations manually: `php occ migrations:execute reader <version>`
- Check database permissions

## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Make your changes
4. Run tests and linting
5. Commit your changes: `git commit -m 'Add amazing feature'`
6. Push to the branch: `git push origin feature/amazing-feature`
7. Open a Pull Request

### Code Style

- **PHP**: Follow [Nextcloud Coding Standard](https://docs.nextcloud.com/server/latest/developer_manual/getting_started/codingguidelines.html)
- **JavaScript/Vue**: Follow [@nextcloud/eslint-config](https://github.com/nextcloud/eslint-config)
- **CSS**: Follow [@nextcloud/stylelint-config](https://github.com/nextcloud/stylelint-config)

## License

This project is licensed under the AGPL-3.0 License - see the [LICENSE](LICENSE) file for details.

## Credits

- Built with [Nextcloud App Framework](https://docs.nextcloud.com/server/latest/developer_manual/)
- Content extraction powered by [fivefilters/readability.php](https://github.com/fivefilters/readability.php)
- RSS parsing with [SimplePie](https://simplepie.org/)
- UI components from [@nextcloud/vue](https://nextcloud-vue-components.netlify.app/)

## Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/nextcloud-reader/issues)
- **Forum**: [Nextcloud Community](https://help.nextcloud.com/)
- **Chat**: [Nextcloud Talk](https://cloud.nextcloud.com/call/your-room)

## Roadmap

- [ ] Mobile apps (iOS/Android)
- [ ] Browser extensions (Chrome, Firefox, Safari)
- [ ] Annotations and highlights
- [ ] Text-to-speech
- [ ] Sharing and collaboration
- [ ] Offline mode with service worker
- [ ] Import from Pocket, Instapaper, Wallabag
- [ ] Machine learning-based recommendations

---

Made with ❤️ for Nextcloud
