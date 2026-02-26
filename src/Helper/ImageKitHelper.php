<?php

/**
 * @package     Hans2103.Plugin
 * @subpackage  System.ImageKit
 *
 * @author      Hans Kuijpers <hans2103@gmail.com>
 * @copyright   (C) 2026 Hans Kuijpers. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Hans2103\Plugin\System\ImageKit\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Lightweight ImageKit.io helper using PHP's built-in curl.
 *
 * No external dependencies — no SDK, no Guzzle, no Composer required.
 * Ships as plain PHP inside the plugin.
 *
 * URL-building methods need no API keys.
 * Management methods (upload, listFiles, deleteFile, getSignedUrl) require
 * the private key to be configured.
 *
 * @since  1.0.0
 */
final class ImageKitHelper
{
    /** @var string ImageKit URL endpoint, e.g. https://ik.imagekit.io/momentsuntil */
    private static string $urlEndpoint = '';

    /** @var string ImageKit public API key */
    private static string $publicKey = '';

    /** @var string ImageKit private API key */
    private static string $privateKey = '';

    /** @var int JPEG/WebP output quality (1–100) */
    private static int $quality = 80;

    /** @var int[] Widths (px) emitted in every srcset attribute */
    private static array $srcsetWidths = [320, 480, 768, 1024, 1280, 1600];

    /** @var string Default value for the HTML sizes attribute */
    private static string $defaultSizes = '100vw';

    /** @var string ImageKit Upload API endpoint */
    private const UPLOAD_ENDPOINT = 'https://upload.imagekit.io/api/v2/files/upload';

    /** @var string ImageKit Media Library API base URL */
    private const MEDIA_ENDPOINT = 'https://api.imagekit.io/v1';

    /**
     * Configure the helper. Called once by the plugin's event handler.
     *
     * @param   string  $urlEndpoint   ImageKit URL endpoint.
     * @param   string  $publicKey     Public API key.
     * @param   string  $privateKey    Private API key (required for uploads / signed URLs).
     * @param   int     $quality       Output quality (1–100).
     * @param   string  $srcsetWidths  Comma-separated pixel widths for srcset.
     * @param   string  $defaultSizes  Default value for the HTML sizes attribute.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public static function configure(
        string $urlEndpoint,
        string $publicKey,
        string $privateKey,
        int $quality = 80,
        string $srcsetWidths = '320,480,768,1024,1280,1600',
        string $defaultSizes = '100vw',
    ): void {
        self::$urlEndpoint  = rtrim($urlEndpoint, '/');
        self::$publicKey    = $publicKey;
        self::$privateKey   = $privateKey;
        self::$quality      = max(1, min(100, $quality));
        self::$defaultSizes = $defaultSizes ?: '100vw';

        $parsed = array_values(
            array_filter(
                array_map('intval', array_map('trim', explode(',', $srcsetWidths)))
            )
        );

        if (!empty($parsed)) {
            sort($parsed);
            self::$srcsetWidths = $parsed;
        }
    }

    // -------------------------------------------------------------------------
    // URL / srcset builders — no HTTP, no API keys needed
    // -------------------------------------------------------------------------

    /**
     * Build a single ImageKit transformation URL.
     *
     * URL format: {endpoint}/tr:w-{width},q-{quality},f-auto/{path}
     *
     * ImageKit transformation parameters used:
     *   w      width in pixels; height auto-scales to preserve aspect ratio
     *   q      output quality (1–100)
     *   f-auto ImageKit picks the best format (WebP / AVIF) per browser Accept header
     *
     * @param   string    $imagePath  Path relative to site root (e.g. images/photo.jpg).
     * @param   int       $width      Target width in pixels.
     * @param   int|null  $quality    Override quality; null falls back to configured default.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function buildUrl(string $imagePath, int $width, ?int $quality = null): string
    {
        $q    = $quality !== null ? max(1, min(100, $quality)) : self::$quality;
        $path = ltrim($imagePath, '/');

        return self::$urlEndpoint . '/tr:w-' . $width . ',q-' . $q . ',f-auto/' . $path;
    }

    /**
     * Build an ImageKit URL with an arbitrary transformation string.
     *
     * @param   string  $imagePath       Path relative to site root.
     * @param   string  $transformation  Raw transformation string (e.g. "w-800,h-600,cm-pad_extract").
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function buildUrlWithTransformation(string $imagePath, string $transformation): string
    {
        $path = ltrim($imagePath, '/');

        return self::$urlEndpoint . '/tr:' . ltrim($transformation, ':') . '/' . $path;
    }

    /**
     * Build a complete srcset string.
     *
     * @param   string    $imagePath  Path relative to site root.
     * @param   int|null  $quality    Override quality; null uses the configured default.
     * @param   int[]|null $widths    Override width set; null uses the configured default.
     *                                Useful for per-image breakpoints (hero vs thumbnail).
     *
     * @return  string  Ready to use as the srcset attribute value.
     *
     * @since   1.0.0
     */
    public static function buildSrcset(string $imagePath, ?int $quality = null, ?array $widths = null): string
    {
        $parts      = [];
        $widthsToUse = ($widths !== null && !empty($widths)) ? $widths : self::$srcsetWidths;

        foreach ($widthsToUse as $w) {
            $parts[] = self::buildUrl($imagePath, $w, $quality) . ' ' . $w . 'w';
        }

        return implode(', ', $parts);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * @return  string
     * @since   1.0.0
     */
    public static function getUrlEndpoint(): string
    {
        return self::$urlEndpoint;
    }

    /**
     * @return  int[]
     * @since   1.0.0
     */
    public static function getSrcsetWidths(): array
    {
        return self::$srcsetWidths;
    }

    /**
     * @return  string
     * @since   1.0.0
     */
    public static function getDefaultSizes(): string
    {
        return self::$defaultSizes;
    }

    // -------------------------------------------------------------------------
    // Media Library API — require public + private keys, use curl internally
    // -------------------------------------------------------------------------

    /**
     * Upload a local file to ImageKit.
     *
     * @param   string  $filePath   Absolute local path to the file.
     * @param   string  $fileName   Desired file name on ImageKit.
     * @param   string  $folder     Target folder on ImageKit (default: /).
     *
     * @return  array  Decoded API response ({fileId, name, url, …}).
     *
     * @throws  \RuntimeException  On API or curl error.
     *
     * @since   1.0.0
     */
    public static function upload(string $filePath, string $fileName, string $folder = '/'): array
    {
        self::requireKeys();

        $payload = [
            'file'              => base64_encode(\file_get_contents($filePath)),
            'fileName'          => $fileName,
            'folder'            => $folder,
            'useUniqueFileName' => 'true',
        ];

        return self::request('POST', self::UPLOAD_ENDPOINT, $payload, true);
    }

    /**
     * Upload a remote URL to ImageKit.
     *
     * @param   string  $url       Public URL of the source image.
     * @param   string  $fileName  Desired file name on ImageKit.
     * @param   string  $folder    Target folder on ImageKit (default: /).
     *
     * @return  array  Decoded API response.
     *
     * @throws  \RuntimeException  On API or curl error.
     *
     * @since   1.0.0
     */
    public static function uploadFromUrl(string $url, string $fileName, string $folder = '/'): array
    {
        self::requireKeys();

        $payload = [
            'file'              => $url,
            'fileName'          => $fileName,
            'folder'            => $folder,
            'useUniqueFileName' => 'true',
        ];

        return self::request('POST', self::UPLOAD_ENDPOINT, $payload, true);
    }

    /**
     * List files in the ImageKit Media Library.
     *
     * @param   array  $options  Query parameters (e.g. ['path' => '/', 'limit' => 20, 'skip' => 0]).
     *
     * @return  array  Array of file objects.
     *
     * @throws  \RuntimeException  On API or curl error.
     *
     * @since   1.0.0
     */
    public static function listFiles(array $options = []): array
    {
        self::requireKeys();

        $url = self::MEDIA_ENDPOINT . '/files';

        if (!empty($options)) {
            $url .= '?' . http_build_query($options);
        }

        return self::request('GET', $url);
    }

    /**
     * Get details of a single file.
     *
     * @param   string  $fileId  ImageKit file ID.
     *
     * @return  array  File detail object.
     *
     * @throws  \RuntimeException  On API or curl error.
     *
     * @since   1.0.0
     */
    public static function getFileDetails(string $fileId): array
    {
        self::requireKeys();

        return self::request('GET', self::MEDIA_ENDPOINT . '/files/' . urlencode($fileId) . '/details');
    }

    /**
     * Delete a file from the ImageKit Media Library.
     *
     * @param   string  $fileId  ImageKit file ID.
     *
     * @return  bool  True on success.
     *
     * @throws  \RuntimeException  On API or curl error.
     *
     * @since   1.0.0
     */
    public static function deleteFile(string $fileId): bool
    {
        self::requireKeys();

        self::request('DELETE', self::MEDIA_ENDPOINT . '/files/' . urlencode($fileId));

        return true;
    }

    /**
     * Generate a signed URL (for private / access-controlled images).
     *
     * @param   string  $imagePath     Image path on ImageKit (e.g. /images/photo.jpg).
     * @param   string  $transformation  Transformation string (e.g. "w-800,q-80").
     * @param   int     $expireSeconds   Seconds until the signed URL expires.
     *
     * @return  string  Signed URL.
     *
     * @since   1.0.0
     */
    public static function getSignedUrl(
        string $imagePath,
        string $transformation = '',
        int $expireSeconds = 300,
    ): string {
        self::requireKeys();

        $expiry    = time() + $expireSeconds;
        $path      = ltrim($imagePath, '/');
        $urlPath   = $transformation !== ''
            ? '/tr:' . $transformation . '/' . $path
            : '/' . $path;

        $token     = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        $signature = hash_hmac('sha1', self::$urlEndpoint . $urlPath . $expiry . $token, self::$privateKey);

        return self::$urlEndpoint . $urlPath . '?ik-t=' . $expiry . '&ik-s=' . $signature . '&ik-sdk-version=php-custom';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Make an API request using curl.
     *
     * @param   string  $method      HTTP method (GET, POST, DELETE).
     * @param   string  $url         Full request URL.
     * @param   array   $payload     Request body for POST requests.
     * @param   bool    $multipart   Send as multipart/form-data instead of JSON.
     *
     * @return  array  Decoded JSON response body.
     *
     * @throws  \RuntimeException  On curl error or non-2xx HTTP status.
     *
     * @since   1.0.0
     */
    private static function request(
        string $method,
        string $url,
        array $payload = [],
        bool $multipart = false,
    ): array {
        $ch = curl_init();

        // Basic auth: private_key as username, empty password
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, self::$privateKey . ':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = ['Accept: application/json'];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            if ($multipart) {
                // Upload endpoint expects multipart/form-data
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } else {
                $headers[]  = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($error !== '') {
            throw new \RuntimeException('ImageKit API curl error: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(
                'ImageKit API error (HTTP ' . $httpCode . '): ' . $body
            );
        }

        if ($body === '' || $body === false) {
            return [];
        }

        $decoded = json_decode((string) $body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('ImageKit API: invalid JSON response — ' . $body);
        }

        return $decoded ?? [];
    }

    /**
     * Assert that API keys are configured before making an API call.
     *
     * @return  void
     *
     * @throws  \LogicException  If the private key is not set.
     *
     * @since   1.0.0
     */
    private static function requireKeys(): void
    {
        if (self::$privateKey === '') {
            throw new \LogicException(
                'ImageKit private key is not configured. Set it in the plugin parameters.'
            );
        }
    }
}
