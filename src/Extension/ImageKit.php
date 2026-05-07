<?php

/**
 * @package     Hans2103.Plugin
 * @subpackage  System.ImageKit
 *
 * @author      Hans Kuijpers <hans2103@gmail.com>
 * @copyright   (C) 2026 Hans Kuijpers. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Hans2103\Plugin\System\ImageKit\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Hans2103\Plugin\System\ImageKit\Helper\ImageKitHelper;
use Joomla\CMS\Event\Application\AfterInitialiseEvent;
use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * ImageKit system plugin.
 *
 *   onAfterInitialise — seeds ImageKitHelper with the plugin params.
 *   onAfterRender     — rewrites image / video / asset URLs in the rendered HTML
 *                       according to the per-feature delivery toggles.
 *
 * @since  1.0.0
 */
final class ImageKit extends CMSPlugin implements SubscriberInterface, DispatcherAwareInterface
{
    use DispatcherAwareTrait;

    /** @var string[] Raster image extensions eligible for transformation. */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /** @var string[] Asset extensions eligible for proxying. */
    private const array ASSET_EXTENSIONS = ['css', 'js', 'mjs', 'woff', 'woff2', 'ttf', 'otf'];

    /** @var bool Whether to strip CMS-style "-WxH" suffix from filenames. */
    private bool $stripSizeSuffix = false;

    /**
     * @return  array<string, string>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRender'     => 'onAfterRender',
        ];
    }

    /**
     * Seed the static helper with the plugin's configuration.
     */
    public function onAfterInitialise(AfterInitialiseEvent $event): void
    {
        ImageKitHelper::configure(
            urlEndpoint:          (string) $this->params->get('url_endpoint', ''),
            publicKey:            (string) $this->params->get('public_key', ''),
            privateKey:           (string) $this->params->get('private_key', ''),
            quality:              (int)    $this->params->get('quality', 80),
            srcsetWidths:         (string) $this->params->get('srcset_widths', '320,480,768,1024,1280,1600'),
            defaultSizes:         (string) $this->params->get('default_sizes', '100vw'),
            extraTransformations: (string) $this->params->get('extra_transformations', ''),
            rejectPaths:          (string) $this->params->get('reject_paths', ''),
        );

        $this->stripSizeSuffix = (bool) $this->params->get('strip_size_suffix', 0);
    }

    /**
     * Walk the fully rendered HTML body and rewrite eligible URLs.
     */
    public function onAfterRender(AfterRenderEvent $event): void
    {
        if (ImageKitHelper::getUrlEndpoint() === '') {
            return;
        }

        $app = $event->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        if ($app->getDocument()->getType() !== 'html') {
            return;
        }

        $body = (string) $app->getBody();

        if ($body === '') {
            return;
        }

        $imageDelivery   = (bool) $this->params->get('enable_image_delivery', 1);
        $videoDelivery   = (bool) $this->params->get('enable_video_delivery', 0);
        $templateAssets  = (bool) $this->params->get('enable_template_assets', 0);
        $extensionAssets = (bool) $this->params->get('enable_extension_assets', 0);
        $coreAssets      = (bool) $this->params->get('enable_core_assets', 0);

        if ($imageDelivery) {
            $body = $this->rewriteImages($body);
        }

        if ($videoDelivery) {
            $body = $this->rewriteVideos($body);
        }

        if ($templateAssets || $extensionAssets || $coreAssets) {
            $body = $this->rewriteAssets($body, [
                'template'  => $templateAssets,
                'extension' => $extensionAssets,
                'core'      => $coreAssets,
            ]);
        }

        $app->setBody($body);
    }

    // -------------------------------------------------------------------------
    // Image rewriting
    // -------------------------------------------------------------------------

    private function rewriteImages(string $html): string
    {
        $html = preg_replace_callback(
            '/<img\b([^>]*?)(\s*\/?>)/i',
            fn (array $m): string => '<img' . $this->rewriteImgAttributes($m[1]) . $m[2],
            $html
        ) ?? $html;

        // <source> with srcset inside <picture> — image MIME types only (skip when explicit video/* type).
        return preg_replace_callback(
            '/<source\b([^>]*?)(\s*\/?>)/i',
            function (array $m): string {
                $attrs = $m[1];

                if (preg_match('/\btype=(["\'])video\//i', $attrs)) {
                    return '<source' . $attrs . $m[2];
                }

                return '<source' . $this->rewriteSrcsetWithin($attrs, srcsetAttrs: ['srcset', 'data-srcset']) . $m[2];
            },
            $html
        ) ?? $html;
    }

    /**
     * Mutate an `<img>` tag's attribute string in place.
     */
    private function rewriteImgAttributes(string $attrs): string
    {
        // Per-image opt-out — strip the marker and bail.
        if (preg_match('/\bdata-ik-skip\b/i', $attrs)) {
            return preg_replace('/\s*\bdata-ik-skip(?:=(["\'])[^"\']*\1)?/i', '', $attrs) ?? $attrs;
        }

        if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $attrs, $srcMatch)) {
            return $attrs;
        }

        $src       = $srcMatch[2];
        $imagePath = $this->resolveLocalImagePath($src);

        if ($imagePath === null) {
            return $attrs;
        }

        if (ImageKitHelper::isRejected($imagePath)) {
            return $attrs;
        }

        // Optional: collapse "-WxH" suffix in the filename and apply the implied transform.
        $hintW = $hintH = null;

        if ($this->stripSizeSuffix) {
            [$imagePath, $hintW, $hintH] = $this->stripSizeSuffix($imagePath);

            if (ImageKitHelper::isRejected($imagePath)) {
                return $attrs;
            }
        }

        $widths = $this->extractCustomWidths($attrs) ?? ImageKitHelper::getSrcsetWidths();

        $explicitWidth = preg_match('/\bwidth=["\']?(\d+)["\']?/i', $attrs, $wm) ? (int) $wm[1] : null;
        $nativeWidth   = $explicitWidth ?? $hintW ?? (int) end($widths);

        // Apply h- transform on the src URL only when the explicit display width matches the suffix's
        // (or no explicit width is set) — otherwise the aspect ratio would be wrong.
        $srcExtras = [];

        if ($hintH !== null && ($explicitWidth === null || $explicitWidth === $hintW)) {
            $srcExtras[] = 'h-' . $hintH;
        }

        $newSrc    = ImageKitHelper::buildUrl($imagePath, $nativeWidth, extraTokens: $srcExtras);
        $newSrcset = ImageKitHelper::buildSrcset($imagePath, widths: $widths);
        $sizes     = ImageKitHelper::getDefaultSizes();

        // Strip per-image override marker.
        $attrs = preg_replace('/\s*\bdata-ik-widths=(["\'])[^"\']*\1/i', '', $attrs) ?? $attrs;

        // Replace src — preserve original quote style.
        $attrs = preg_replace_callback(
            '/\bsrc=(["\'])([^"\']+)\1/i',
            static fn (array $m): string => 'src=' . $m[1] . $newSrc . $m[1],
            $attrs,
            1
        ) ?? $attrs;

        // Rewrite data-src (lazy-loaders) when present.
        $attrs = $this->rewriteUrlAttribute($attrs, 'data-src', $nativeWidth);

        // Existing srcset / data-srcset: rewrite each URL in place; otherwise add a fresh srcset.
        if (preg_match('/\bsrcset=/i', $attrs)) {
            $attrs = $this->rewriteSrcsetWithin($attrs, srcsetAttrs: ['srcset'], nativeWidth: $nativeWidth);
        } else {
            $attrs .= ' srcset="' . $newSrcset . '"';
        }

        $attrs = $this->rewriteSrcsetWithin($attrs, srcsetAttrs: ['data-srcset'], nativeWidth: $nativeWidth);

        if (!preg_match('/\bsizes=/i', $attrs)) {
            $attrs .= ' sizes="' . $sizes . '"';
        }

        // Don't lazy-load when the author signalled this is an LCP / above-fold image.
        $hasFetchPriorityHigh = preg_match('/\bfetchpriority=(["\']?)high\1/i', $attrs) === 1;

        if (
            !$hasFetchPriorityHigh
            && !preg_match('/\bloading=/i', $attrs)
            && preg_match('/\bwidth=\S+/i', $attrs)
            && preg_match('/\bheight=\S+/i', $attrs)
        ) {
            $attrs .= ' loading="lazy"';
        }

        if (!preg_match('/\bdecoding=/i', $attrs)) {
            $attrs .= ' decoding="async"';
        }

        return $attrs;
    }

    /**
     * Strip a "-WIDTHxHEIGHT" suffix from a filename and return the canonical path and W/H hints.
     *
     * Example: "images/photo-300x200.jpg" → ["images/photo.jpg", 300, 200].
     *
     * @return  array{0: string, 1: int|null, 2: int|null}
     */
    private function stripSizeSuffix(string $path): array
    {
        $dir      = \dirname($path);
        $filename = basename($path);
        $ext      = pathinfo($filename, PATHINFO_EXTENSION);
        $name     = $ext !== '' ? substr($filename, 0, -1 * (\strlen($ext) + 1)) : $filename;

        if (!preg_match('/^(.+)-(\d+)x(\d+)$/', $name, $m)) {
            return [$path, null, null];
        }

        $w = (int) $m[2];
        $h = (int) $m[3];

        if ($w <= 0 && $h <= 0) {
            return [$path, null, null];
        }

        $newName = $m[1] . ($ext !== '' ? '.' . $ext : '');
        $newPath = ($dir === '.' || $dir === '') ? $newName : rtrim($dir, '/') . '/' . $newName;

        return [$newPath, $w > 0 ? $w : null, $h > 0 ? $h : null];
    }

    /**
     * Parse `data-ik-widths="320,768,..."` from a tag's attribute string.
     *
     * @return  int[]|null
     */
    private function extractCustomWidths(string $attrs): ?array
    {
        if (!preg_match('/\bdata-ik-widths=(["\'])([^"\']+)\1/i', $attrs, $m)) {
            return null;
        }

        $custom = array_values(
            array_filter(
                array_map('intval', array_map('trim', explode(',', $m[2])))
            )
        );

        if ($custom === []) {
            return null;
        }

        sort($custom);

        return $custom;
    }

    /**
     * Resolve a `src=` value to a site-relative image path, or `null` if not eligible.
     */
    private function resolveLocalImagePath(string $src): ?string
    {
        if (str_starts_with($src, 'data:')) {
            return null;
        }

        $endpoint = ImageKitHelper::getUrlEndpoint();

        if ($endpoint !== '' && str_starts_with($src, $endpoint)) {
            return null;
        }

        $siteRoot = rtrim(Uri::root(), '/');

        if (preg_match('/^https?:\/\//i', $src)) {
            if ($siteRoot === '' || !str_starts_with($src, $siteRoot . '/')) {
                return null;
            }

            $imagePath = ltrim(substr($src, \strlen($siteRoot)), '/');
        } else {
            $imagePath = ltrim($src, '/');
        }

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        if (!\in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return null;
        }

        return $imagePath;
    }

    // -------------------------------------------------------------------------
    // Video rewriting
    // -------------------------------------------------------------------------

    private function rewriteVideos(string $html): string
    {
        // <video poster="…"> is an image, not a video.
        $html = preg_replace_callback(
            '/<video\b([^>]*?)(\s*\/?>)/i',
            function (array $m): string {
                $attrs = $m[1];

                if (preg_match('/\bsrc=/i', $attrs)) {
                    $attrs = $this->rewriteAssetAttribute($attrs, 'src', 'video');
                }

                $widths = ImageKitHelper::getSrcsetWidths();
                $width  = (int) (end($widths) ?: 1280);

                return '<video' . $this->rewriteUrlAttribute($attrs, 'poster', $width) . $m[2];
            },
            $html
        ) ?? $html;

        // <source src="…" type="video/…"> — proxy as an asset (no transformation).
        return preg_replace_callback(
            '/<source\b([^>]*?)(\s*\/?>)/i',
            function (array $m): string {
                $attrs = $m[1];

                if (!preg_match('/\btype=(["\'])video\//i', $attrs)) {
                    return '<source' . $attrs . $m[2];
                }

                return '<source' . $this->rewriteAssetAttribute($attrs, 'src', 'video') . $m[2];
            },
            $html
        ) ?? $html;
    }

    /**
     * Rewrite a single image-URL attribute (e.g. `src` / `data-src` / `poster`) to a transformed CDN URL.
     */
    private function rewriteUrlAttribute(string $attrs, string $attrName, int $width): string
    {
        $pattern = '/\b' . preg_quote($attrName, '/') . '=(["\'])([^"\']+)\1/i';

        return preg_replace_callback(
            $pattern,
            function (array $m) use ($attrName, $width): string {
                $imagePath = $this->resolveLocalImagePath($m[2]);

                if ($imagePath === null || ImageKitHelper::isRejected($imagePath)) {
                    return $m[0];
                }

                $extras = [];

                if ($this->stripSizeSuffix) {
                    [$imagePath, , $hintH] = $this->stripSizeSuffix($imagePath);

                    if (ImageKitHelper::isRejected($imagePath)) {
                        return $m[0];
                    }

                    if ($hintH !== null) {
                        $extras[] = 'h-' . $hintH;
                    }
                }

                return $attrName . '=' . $m[1] . ImageKitHelper::buildUrl($imagePath, $width, extraTokens: $extras) . $m[1];
            },
            $attrs
        ) ?? $attrs;
    }

    /**
     * Rewrite each URL within one or more srcset-shaped attributes.
     *
     * @param  string[]  $srcsetAttrs
     * @param  ?int      $nativeWidth  CSS-pixel display width (used to resolve density descriptors).
     */
    private function rewriteSrcsetWithin(string $attrs, array $srcsetAttrs, ?int $nativeWidth = null): string
    {
        foreach ($srcsetAttrs as $attrName) {
            $pattern = '/\b' . preg_quote($attrName, '/') . '=(["\'])([^"\']+)\1/i';

            $attrs = preg_replace_callback(
                $pattern,
                function (array $m) use ($attrName, $nativeWidth): string {
                    return $attrName . '=' . $m[1] . $this->rewriteSrcsetValue($m[2], $nativeWidth) . $m[1];
                },
                $attrs
            ) ?? $attrs;
        }

        return $attrs;
    }

    /**
     * Rewrite each URL token within a srcset value, preserving the descriptor (`320w`, `2x`).
     *
     * @param  ?int  $nativeWidth  CSS-pixel base width used to resolve density descriptors.
     */
    private function rewriteSrcsetValue(string $srcset, ?int $nativeWidth = null): string
    {
        $parts = array_map('trim', explode(',', $srcset));
        $out   = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $part, 2) ?: [$part];
            $url    = $tokens[0];
            $desc   = isset($tokens[1]) ? trim((string) $tokens[1]) : '';

            $imagePath = $this->resolveLocalImagePath($url);

            if ($imagePath === null || ImageKitHelper::isRejected($imagePath)) {
                $out[] = $part;
                continue;
            }

            if ($this->stripSizeSuffix) {
                [$imagePath] = $this->stripSizeSuffix($imagePath);

                if (ImageKitHelper::isRejected($imagePath)) {
                    $out[] = $part;
                    continue;
                }
            }

            $defaultWidths = ImageKitHelper::getSrcsetWidths();
            $fallback      = (int) (end($defaultWidths) ?: 1280);

            if ($desc !== '' && preg_match('/^(\d+)w$/i', $desc, $wm)) {
                $width = (int) $wm[1];
            } elseif ($desc !== '' && preg_match('/^(\d+(?:\.\d+)?)x$/i', $desc, $dm)) {
                $density = (float) $dm[1];
                $base    = $nativeWidth ?? $fallback;
                $width   = max(1, (int) round($base * $density));
            } else {
                $width = $fallback;
            }

            $newUrl = ImageKitHelper::buildUrl($imagePath, $width);
            $out[]  = $desc !== '' ? $newUrl . ' ' . $desc : $newUrl;
        }

        return implode(', ', $out);
    }

    // -------------------------------------------------------------------------
    // Asset rewriting (CSS / JS / fonts)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, bool>  $categoryToggles  Map of category => enabled (template, extension, core).
     */
    private function rewriteAssets(string $html, array $categoryToggles): string
    {
        $rewriteAttr = function (string $tag, string $attrName, string $type) use ($categoryToggles): string {
            $pattern = '/\b' . preg_quote($attrName, '/') . '=(["\'])([^"\']+)\1/i';

            return preg_replace_callback(
                $pattern,
                function (array $m) use ($attrName, $type, $categoryToggles): string {
                    $newUrl = $this->maybeRewriteAssetUrl($m[2], $type, $categoryToggles);

                    if ($newUrl === null) {
                        return $m[0];
                    }

                    return $attrName . '=' . $m[1] . $newUrl . $m[1];
                },
                $tag
            ) ?? $tag;
        };

        // <link rel="stylesheet" href="…"> and <link rel="preload" as="style" href="…">
        $html = preg_replace_callback(
            '/<link\b[^>]*>/i',
            static function (array $m) use ($rewriteAttr): string {
                $tag = $m[0];

                if (!preg_match('/\brel=(["\'])([^"\']+)\1/i', $tag, $relMatch)) {
                    return $tag;
                }

                $rel = strtolower(trim($relMatch[2]));
                $as  = preg_match('/\bas=(["\'])([^"\']+)\1/i', $tag, $asMatch)
                    ? strtolower(trim($asMatch[2]))
                    : '';

                if (!str_contains($rel, 'stylesheet') && !($rel === 'preload' && $as === 'style')) {
                    return $tag;
                }

                return $rewriteAttr($tag, 'href', 'style');
            },
            $html
        ) ?? $html;

        // <script src="…">
        return preg_replace_callback(
            '/<script\b[^>]*>/i',
            static fn (array $m): string => $rewriteAttr($m[0], 'src', 'script'),
            $html
        ) ?? $html;
    }

    /**
     * Rewrite an asset attribute (`src` / `href`) to an ImageKit-proxied URL when its category is enabled.
     */
    private function rewriteAssetAttribute(string $attrs, string $attrName, string $type): string
    {
        $pattern = '/\b' . preg_quote($attrName, '/') . '=(["\'])([^"\']+)\1/i';

        return preg_replace_callback(
            $pattern,
            function (array $m) use ($attrName, $type): string {
                $newUrl = $this->maybeRewriteAssetUrl($m[2], $type, []);

                if ($newUrl === null) {
                    return $m[0];
                }

                return $attrName . '=' . $m[1] . $newUrl . $m[1];
            },
            $attrs
        ) ?? $attrs;
    }

    /**
     * Decide whether a given URL should be rewritten as an asset.
     *
     * @param  array<string, bool>  $categoryToggles  Per-category enabled map; pass [] to skip the gate (videos).
     */
    private function maybeRewriteAssetUrl(string $url, string $type, array $categoryToggles): ?string
    {
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
            return null;
        }

        $endpoint = ImageKitHelper::getUrlEndpoint();

        if ($endpoint !== '' && str_starts_with($url, $endpoint)) {
            return null;
        }

        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['path'])) {
            return null;
        }

        $path = (string) $parsed['path'];

        // Reject absolute URLs that don't belong to this site.
        if (isset($parsed['host'])) {
            $siteHost = parse_url(Uri::root(), PHP_URL_HOST);

            if (!\is_string($siteHost) || strcasecmp($parsed['host'], $siteHost) !== 0) {
                return null;
            }
        }

        // Strip the site sub-directory prefix so the proxied path starts at the doc root.
        $sitePath = (string) (parse_url(Uri::root(), PHP_URL_PATH) ?: '');
        $sitePath = rtrim($sitePath, '/');

        if ($sitePath !== '' && str_starts_with($path, $sitePath . '/')) {
            $path = substr($path, \strlen($sitePath));
        }

        $relative = ltrim($path, '/');

        if (ImageKitHelper::isRejected($relative)) {
            return null;
        }

        // Video sources skip the category gate (their toggle is the parent).
        if ($categoryToggles !== []) {
            $category = $this->classifyAssetPath($relative);

            if ($category === null || ($categoryToggles[$category] ?? false) === false) {
                return null;
            }
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (
            $type !== 'video'
            && $ext !== ''
            && !\in_array($ext, self::ASSET_EXTENSIONS, true)
        ) {
            return null;
        }

        return ImageKitHelper::buildAssetUrl($relative, (string) ($parsed['query'] ?? ''));
    }

    /**
     * Classify a Joomla site-relative path into one of: 'template', 'extension', 'core', or null.
     */
    private function classifyAssetPath(string $relative): ?string
    {
        $relative = ltrim($relative, '/');

        if (
            str_starts_with($relative, 'media/system/')
            || str_starts_with($relative, 'media/vendor/')
            || str_starts_with($relative, 'media/legacy/')
            || str_starts_with($relative, 'media/cms/')
            || str_starts_with($relative, 'media/jui/')
        ) {
            return 'core';
        }

        if (
            str_starts_with($relative, 'templates/')
            || str_starts_with($relative, 'media/templates/')
        ) {
            return 'template';
        }

        if (
            str_starts_with($relative, 'media/com_')
            || str_starts_with($relative, 'media/mod_')
            || str_starts_with($relative, 'media/plg_')
            || str_starts_with($relative, 'media/lib_')
            || str_starts_with($relative, 'components/')
            || str_starts_with($relative, 'modules/')
            || str_starts_with($relative, 'plugins/')
            || str_starts_with($relative, 'libraries/')
        ) {
            return 'extension';
        }

        return null;
    }
}
