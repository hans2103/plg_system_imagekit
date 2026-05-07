# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Version numbers follow the `yy.ww.int` scheme: year · ISO week · patch integer.

## [26.19.02] - 2026-05-07

### Added
- New **Delivery** fieldset:
  - **Image delivery** toggle to suspend image rewriting without uninstalling.
  - **Video delivery** toggle — proxies `<video src>`, `<source type="video/*">`, and rewrites `<video poster>` images. Off by default; enable only after configuring video delivery on the ImageKit account.
  - **Reject paths** — one glob pattern per line (`*`, `**`, `?`, `#` comments) for site-wide path-based exclusion.
- New **Asset proxying** fieldset (opt-in, requires a web origin configured on ImageKit):
  - **Template assets** — proxy CSS/JS/fonts under `templates/` and `media/templates/`.
  - **Extension assets** — proxy CSS/JS/fonts under `media/com_*`, `media/mod_*`, `media/plg_*`, `media/lib_*`, and the legacy `components/`, `modules/`, `plugins/`, `libraries/` paths.
  - **Core assets** — proxy Joomla core CSS/JS/fonts under `media/system/`, `media/vendor/`, `media/legacy/`, `media/cms/`.
- New **Status** diagnostics panel (custom `StatusField`): live configuration overview, sample rewritten image and asset URLs, and a copy-paste plain-text report (no secrets) for support tickets.
- Per-image opt-out: `data-ik-skip` on any `<img>` leaves that image alone; the attribute is stripped before the HTML reaches the browser.
- **Extra transformations** setting — comma-separated tokens appended to every image URL after `w/q/f-auto` (e.g. `pr-true,c-at_max` for progressive JPEG + max-fit cropping).
- Opt-in **Strip "-WxH" suffix** setting. When enabled, filenames like `photo-300x200.jpg` are reduced to `photo.jpg` and the dimensions are passed as `tr:w-300,h-200` so ImageKit can serve the canonical original at any size. Off by default to stay safe with extensions whose origin only stores sized variants.
- `srcset` density descriptors (`2x`, `3x`) now resolve to the correct render width using the `<img>`'s native width as the base.
- `<picture>` / `<source srcset>` and lazy-loader attributes (`data-src`, `data-srcset`) are now rewritten alongside `<img>`.
- `ImageKitHelper::buildAssetUrl()` builds a passthrough (no `tr:`) URL for non-image assets.
- `ImageKitHelper::buildUrl()` and `buildSrcset()` accept an optional `extraTokens` parameter for per-call transformations.

### Changed
- `loading="lazy"` is no longer added when `fetchpriority="high"` is present. The two are semantically conflicting (LCP hint vs. defer); honouring the author's priority hint matches modern Core Web Vitals guidance.
- Original quote style on rewritten attributes (`src=`, `data-src`, `srcset`, `href`, `poster`) is preserved instead of being normalised to double quotes.
- `ImageKitHelper::configure()` now also accepts `extraTransformations` and `rejectPaths`.
- Plugin description rewritten to reflect image, video, and optional asset delivery.
- Internals: `declare(strict_types=1)` in the helper, typed class constants, and `#[\Override]` on `getSubscribedEvents()` (PHP 8.3 polish).

## [26.09.01] - 2026-02-26

### Added
- Initial release
- `onAfterRender` rewrites all local `<img>` src attributes to ImageKit CDN URLs
- Automatic `srcset` generation for configured breakpoint widths
- `f-auto` format transformation (WebP / AVIF delivered per browser Accept header)
- Configurable output quality, srcset widths, and default `sizes` attribute
- `data-ik-widths` attribute support for per-image breakpoint override
- Existing `sizes` attribute on `<img>` tags is preserved unchanged
- CSS `background-image` documentation in plugin settings (Usage tab)
- Upload local files or remote URLs to ImageKit via built-in curl client
- List, get details, and delete files in the ImageKit Media Library
- Signed URL generation using HMAC-SHA1
- Zero external dependencies — no Composer, no Guzzle, no vendor directory
