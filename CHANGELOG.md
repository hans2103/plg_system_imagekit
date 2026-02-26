# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Version numbers follow the `yy.ww.int` scheme: year · ISO week · patch integer.

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
