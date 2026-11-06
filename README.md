# SahilPHP Firefiber

HTML-first, event-driven reactivity for SahilPHP applications.

## Installation

> [!WARNING]
> This package is a **library**, not a project skeleton.
> `composer create-project sahilphp/firefiber` fails by design.
> Create a project with `sahilphp new my-app` and choose **Firefiber** during
> setup; the installer wires the client runtime and your CSS framework together.
>
> Install it as a dependency with:
>
> ```bash
> composer require sahilphp/firefiber
> ```

Create a new application with the installer:

```bash
sahilphp new my-app --ui-kit=firefiber --css=tailwind
cd my-app
```

## Development

```bash
npm run dev          # Vite on http://127.0.0.1:5173
php sahil start      # PHP on http://127.0.0.1:8000
```
