# Repository Guidelines

## Project Structure & Module Organization

```
youtube-clone/
├── index.php              # Entry point — pulls in all components
├── style.css              # Single global stylesheet
└── components/
    ├── data.php           # Video data arrays (mock database)
    ├── header.php         # Top nav bar with logo, search, user controls
    ├── sidebar.php        # Left navigation sidebar
    └── video-grid.php     # Main content grid of video cards
```

- **`index.php`** is the only page. It includes all components via `require_once` and `include`.
- **`components/`** holds reusable PHP partials. Each file outputs a self-contained chunk of HTML.
- **`data.php`** acts as a mock data layer — add or edit video entries here.

## Build, Test, and Development Commands

No build step, package manager, or test suite is configured. To run locally:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000` in a browser. All PHP files use plain procedural code with no external dependencies.

## Coding Style & Naming Conventions

- **Indentation**: 4 spaces (no tabs), as seen throughout the codebase.
- **PHP**: Procedural style. Use `<?php` open tags without closing `?>` in pure-PHP files (`data.php`).
- **CSS**: Unprefixed class names with BEM-like patterns (e.g., `.header-left`, `.icon-btn`).
- **Strings**: Single quotes in PHP unless interpolation is needed.
- **HTML**: Double quotes for attributes, consistent with existing markup.

No formatter or linter is configured. Match the existing style in any contribution.

## Commit & Pull Request Guidelines

This is a small educational project. If you contribute:

- Use short, imperative commit messages (e.g., `Add channel page component`).
- PRs should describe the change and note which component(s) were touched.
- No linked issues or CI checks are configured — keep it lightweight.

## Adding New Components

1. Create the file in `components/` (e.g., `channel.php`).
2. Include any extra data in `components/data.php` as a new array.
3. Require or include the component in `index.php` where it belongs in the layout.
