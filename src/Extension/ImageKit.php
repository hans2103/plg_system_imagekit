<?php

/**
 * @package     Hans2103.Plugin
 * @subpackage  System.ImageKit
 *
 * @author      Hans Kuijpers <hans2103@gmail.com>
 * @copyright   (C) 2026 Hans Kuijpers. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

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
 * onAfterInitialise — seeds ImageKitHelper with the plugin params.
 * onAfterRender     — walks every <img> tag in the rendered HTML and:
 *                       • rewrites local src to an ImageKit CDN URL
 *                       • adds a srcset for all configured widths
 *                       • adds sizes and loading="lazy" when missing
 *
 * No layout overrides, no Composer, no external dependencies.
 * All ImageKit API calls use PHP's built-in curl.
 *
 * @since  1.0.0
 */
final class ImageKit extends CMSPlugin implements SubscriberInterface, DispatcherAwareInterface
{
    use DispatcherAwareTrait;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRender'     => 'onAfterRender',
        ];
    }

    /**
     * Listener for the `onAfterInitialise` event.
     *
     * Seeds the ImageKitHelper static helper with the plugin's configuration
     * so it is available to all call-sites throughout the request lifecycle.
     *
     * @param   AfterInitialiseEvent  $event  The event instance.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterInitialise(AfterInitialiseEvent $event): void
    {
        ImageKitHelper::configure(
            $this->params->get('url_endpoint', 'https://ik.imagekit.io/momentsuntil'),
            $this->params->get('public_key', ''),
            $this->params->get('private_key', ''),
            (int) $this->params->get('quality', 80),
            $this->params->get('srcset_widths', '320,480,768,1024,1280,1600'),
            $this->params->get('default_sizes', '100vw'),
        );
    }

    /**
     * Listener for the `onAfterRender` event.
     *
     * Scans the fully rendered HTML body and rewrites every local <img> tag
     * to use an ImageKit CDN URL with a full srcset.
     *
     * @param   AfterRenderEvent  $event  The event instance.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterRender(AfterRenderEvent $event): void
    {
        if (ImageKitHelper::getUrlEndpoint() === '') {
            return;
        }

        $app = $event->getApplication();

        // Only process frontend requests.
        if (!$app->isClient('site')) {
            return;
        }

        // Only process HTML documents.
        if ($app->getDocument()->getType() !== 'html') {
            return;
        }

        $body = $app->getBody();

        if (empty($body)) {
            return;
        }

        $app->setBody($this->processImages($body));
    }

    /**
     * Walk every <img> tag in the HTML and inject ImageKit CDN URLs + srcset.
     *
     * Skipped images:
     *   - data: URIs (inline/base64)
     *   - Already pointing at the ImageKit endpoint
     *   - External URLs on other domains
     *   - Non-image file extensions (e.g. SVG data or icon paths)
     *
     * @param   string  $html  The full rendered HTML body.
     *
     * @return  string  HTML with rewritten <img> tags.
     *
     * @since   1.0.0
     */
    private function processImages(string $html): string
    {
        $urlEndpoint = ImageKitHelper::getUrlEndpoint();
        $siteRoot    = rtrim(Uri::root(), '/');

        return preg_replace_callback(
            '/<img\b([^>]*?)(\s*\/?>)/i',
            static function (array $matches) use ($urlEndpoint, $siteRoot): string {
                $attrs  = $matches[1];
                $close  = $matches[2];

                // Must have a src attribute.
                if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $attrs, $srcMatch)) {
                    return $matches[0];
                }

                $src = $srcMatch[2];

                // --- Determine the image path relative to the site root ------
                if (str_starts_with($src, 'data:')) {
                    // Inline base64 image — skip.
                    return $matches[0];
                }

                if (str_starts_with($src, $urlEndpoint)) {
                    // Already an ImageKit URL — skip.
                    return $matches[0];
                }

                if (preg_match('/^https?:\/\//i', $src)) {
                    // Absolute URL — only rewrite if it belongs to this site.
                    if (!str_starts_with($src, $siteRoot . '/')) {
                        return $matches[0];
                    }

                    $imagePath = ltrim(substr($src, \strlen($siteRoot)), '/');
                } else {
                    // Relative URL.
                    $imagePath = ltrim($src, '/');
                }

                // Only rewrite common raster formats.
                $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

                if (!\in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true)) {
                    return $matches[0];
                }

                // --- Per-image width override (data-ik-widths) ---------------
                // Markup: <img src="…" data-ik-widths="768,1024,1280,1920">
                // Useful when an image needs a different set of breakpoints
                // than the plugin default (e.g. a full-width hero vs. a card thumbnail).
                $widths = ImageKitHelper::getSrcsetWidths(); // plugin default

                if (preg_match('/\bdata-ik-widths=(["\'])([^"\']+)\1/i', $attrs, $ikwm)) {
                    $custom = array_values(
                        array_filter(
                            array_map('intval', array_map('trim', explode(',', $ikwm[2])))
                        )
                    );

                    if (!empty($custom)) {
                        sort($custom);
                        $widths = $custom;
                    }
                }

                // --- Build ImageKit URLs ------------------------------------
                // Primary src uses the image's own declared width, or falls
                // back to the largest width in the (possibly custom) set.
                $nativeWidth = preg_match('/\bwidth=["\']?(\d+)["\']?/i', $attrs, $wm)
                    ? (int) $wm[1]
                    : end($widths);

                $ikSrc    = ImageKitHelper::buildUrl($imagePath, $nativeWidth);
                $ikSrcset = ImageKitHelper::buildSrcset($imagePath, null, $widths);
                $sizes    = ImageKitHelper::getDefaultSizes();

                // --- Rewrite attributes -------------------------------------
                // Remove data-ik-widths (no value on the client side).
                $newAttrs = preg_replace('/\s*\bdata-ik-widths=(["\'])[^"\']*\1/i', '', $attrs);

                // Replace src.
                $newAttrs = preg_replace(
                    '/\bsrc=(["\'])([^"\']+)\1/i',
                    'src="' . $ikSrc . '"',
                    $newAttrs
                );

                // Add srcset if absent.
                if (!preg_match('/\bsrcset=/i', $newAttrs)) {
                    $newAttrs .= ' srcset="' . $ikSrcset . '"';
                }

                // Add sizes if absent (template can set its own sizes value).
                if (!preg_match('/\bsizes=/i', $newAttrs)) {
                    $newAttrs .= ' sizes="' . $sizes . '"';
                }

                // Add loading="lazy" if absent (requires width + height for CLS safety).
                if (!preg_match('/\bloading=/i', $newAttrs)
                    && preg_match('/\bwidth=\S+/i', $newAttrs)
                    && preg_match('/\bheight=\S+/i', $newAttrs)
                ) {
                    $newAttrs .= ' loading="lazy"';
                }

                return '<img' . $newAttrs . $close;
            },
            $html
        ) ?? $html;
    }
}
