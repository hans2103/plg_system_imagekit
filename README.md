# System - ImageKit

Joomla 5 system plugin that serves local images through the [ImageKit.io](https://imagekit.io) CDN with automatic `srcset`, WebP/AVIF conversion, and quality optimisation.

## Features

- **Automatic CDN delivery** — every local `<img>` tag is rewritten to use your ImageKit endpoint
- **Responsive srcset** — generates `srcset` entries for all configured breakpoint widths
- **Format optimisation** — `f-auto` lets ImageKit serve WebP or AVIF based on the browser's Accept header
- **Per-image control** — add `data-ik-widths="320,768,1280"` to override breakpoints on a single image
- **Sizes-aware** — existing `sizes` attributes on `<img>` tags are never overwritten
- **CSS background guide** — built-in `Usage` tab in plugin settings documents `image-set()` and `@media` patterns
- **Media Library API** — upload files, list, get details, and delete via the ImageKit REST API
- **Zero dependencies** — no Composer, no Guzzle, no vendor directory; uses PHP's built-in `curl`

## Requirements

- Joomla 6.x
- PHP 8.3+
- `ext-curl` (standard on all hosting)
- An [ImageKit.io](https://imagekit.io) account

## Installation

1. Download the latest `plg_system_imagekit_vYY.WW.PP.zip` from the [Releases](https://github.com/hans2103/plg_system_imagekit/releases) page
2. In Joomla administrator go to **System → Extensions → Install**
3. Upload and install the ZIP
4. Go to **System → Plugins**, find **System - ImageKit** and enable it
5. Open the plugin settings and fill in your **URL endpoint**, **public key** and **private key**

## Configuration

### ImageKit connection tab

| Field | Description |
|---|---|
| URL endpoint | Your ImageKit URL, e.g. `https://ik.imagekit.io/yourname` |
| Public key | From ImageKit dashboard → Developer options |
| Private key | From ImageKit dashboard → Developer options (keep secret) |

### Default image settings tab

| Field | Default | Description |
|---|---|---|
| Output quality | `80` | JPEG/WebP quality (1–100) |
| Default srcset widths | `320,480,768,1024,1280,1600` | Comma-separated pixel widths |
| Default sizes attribute | `100vw` | HTML `sizes` value when none is set on the `<img>` |

## Per-image override

Add `data-ik-widths` to override the srcset breakpoints for a single image.
The attribute is stripped from the HTML output before it reaches the browser.

```html
<!-- Full-width hero — large breakpoints -->
<img src="/images/header.jpg" width="1920" height="600"
     data-ik-widths="768,1024,1280,1600,1920"
     sizes="100vw">

<!-- Blog-card thumbnail — smaller breakpoints -->
<img src="/images/article.jpg" width="600" height="400"
     data-ik-widths="320,480,768"
     sizes="(max-width: 768px) 100vw, 33vw">
```

## CSS background images

CSS `background-image` cannot use `srcset`. Use ImageKit URLs directly with `image-set()` or `@media`:

```css
/* Resolution switching */
.hero {
    background-image: image-set(
        url("https://ik.imagekit.io/yourname/tr:w-768,f-auto/images/header.jpg")  1x,
        url("https://ik.imagekit.io/yourname/tr:w-1536,f-auto/images/header.jpg") 2x
    );
}

/* Breakpoint control */
.hero { background-image: url("https://ik.imagekit.io/yourname/tr:w-768,f-auto/images/header.jpg"); }
@media (min-width: 1024px) { .hero { background-image: url("https://ik.imagekit.io/yourname/tr:w-1280,f-auto/images/header.jpg"); } }
@media (min-width: 1440px) { .hero { background-image: url("https://ik.imagekit.io/yourname/tr:w-1920,f-auto/images/header.jpg"); } }
```

## Joomla update server

Add the following URL to the plugin's update server to receive in-dashboard update notifications:

```
https://raw.githubusercontent.com/hans2103/plg_system_imagekit/main/update.xml
```

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).
