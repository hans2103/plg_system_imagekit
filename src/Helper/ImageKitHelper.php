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

namespace Hans2103\Plugin\System\ImageKit\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Lightweight ImageKit.io helper using PHP's built-in curl.
 *
 * No external dependencies — no SDK, no Guzzle, no Composer required.
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

    /** @var string[] Extra ImageKit transformation tokens appended after w/q/f-auto, e.g. ['pr-true', 'c-at_max'] */
    private static array $extraTransformations = [];

    /** @var string[] Compiled regex patterns derived from the user's reject list */
    private static array $rejectRegex = [];

    /** @var string ImageKit Upload API endpoint */
    private const UPLOAD_ENDPOINT = 'https://upload.imagekit.io/api/v2/files/upload';

    /** @var string ImageKit Media Library API base URL */
    private const MEDIA_ENDPOINT = 'https://api.imagekit.io/v1';

    /**
     * Configure the helper. Called once by the plugin's event handler.
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
        string $extraTransformations = '',
        string $rejectPaths = '',
    ): void {
        self::$urlEndpoint  = rtrim($urlEndpoint, '/');
        self::$publicKey    = $publicKey;
        self::$privateKey   = $privateKey;
        self::$quality      = max(1, min(100, $quality));
        self::$defaultSizes = $defaultSizes !== '' ? $defaultSizes : '100vw';

        $widths = array_values(
            array_filter(
                array_map('intval', array_map('trim', explode(',', $srcsetWidths)))
            )
        );

        if (!empty($widths)) {
            sort($widths);
            self::$srcsetWidths = $widths;
        }

        self::$extraTransformations = array_values(
            array_filter(
                array_map('trim', explode(',', $extraTransformations)),
                static fn (string $t): bool => $t !== '',
            )
        );

        self::$rejectRegex = self::compileRejectPatterns($rejectPaths);
    }

    // -------------------------------------------------------------------------
    // URL / srcset builders — no HTTP, no API keys needed
    // -------------------------------------------------------------------------

    /**
     * Build a single ImageKit transformation URL for an image.
     *
     * URL format: {endpoint}/tr:w-{width},q-{quality},f-auto[,extra][,perCall]/{path}
     *
     * @param  string[]  $extraTokens  Per-call transformation tokens (e.g. ['h-200']).
     *                                 Appended after the configured global extras.
     *
     * @since   1.0.0
     */
    public static function buildUrl(string $imagePath, int $width, ?int $quality = null, array $extraTokens = []): string
    {
        $q    = $quality !== null ? max(1, min(100, $quality)) : self::$quality;
        $path = ltrim($imagePath, '/');

        $perCall = array_values(array_filter(
            $extraTokens,
            static fn ($t): bool => \is_string($t) && $t !== '',
        ));

        $tokens = ['w-' . $width, 'q-' . $q, 'f-auto', ...self::$extraTransformations, ...$perCall];

        return self::$urlEndpoint . '/tr:' . implode(',', $tokens) . '/' . $path;
    }

    /**
     * Build an ImageKit URL with an arbitrary transformation string (escape hatch).
     *
     * @since   1.0.0
     */
    public static function buildUrlWithTransformation(string $imagePath, string $transformation): string
    {
        $path = ltrim($imagePath, '/');

        return self::$urlEndpoint . '/tr:' . ltrim($transformation, ':') . '/' . $path;
    }

    /**
     * Build a complete srcset string for an image.
     *
     * @param   int[]|null   $widths       Override width set; null falls back to the configured default.
     * @param   string[]     $extraTokens  Per-call transformation tokens applied to every variant.
     *
     * @since   1.0.0
     */
    public static function buildSrcset(string $imagePath, ?int $quality = null, ?array $widths = null, array $extraTokens = []): string
    {
        $widthsToUse = ($widths !== null && !empty($widths)) ? $widths : self::$srcsetWidths;

        $parts = [];

        foreach ($widthsToUse as $w) {
            $parts[] = self::buildUrl($imagePath, $w, $quality, $extraTokens) . ' ' . $w . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * Build a passthrough proxy URL for a non-image asset (CSS, JS, font, …).
     *
     * Format: {endpoint}/{path}[?query] — no /tr:.../ segment.
     *
     * Requires ImageKit's web-proxy origin to be configured to fetch from your site.
     *
     * @since   2.0.0
     */
    public static function buildAssetUrl(string $path, string $query = ''): string
    {
        $url = self::$urlEndpoint . '/' . ltrim($path, '/');

        return $query !== '' ? $url . '?' . ltrim($query, '?') : $url;
    }

    /**
     * Test a path against the configured reject list.
     *
     * Patterns are glob-style:
     *   *   matches any sequence (excluding /)
     *   **  matches any sequence (including /)
     *   ?   matches any single character
     *
     * @since   2.0.0
     */
    public static function isRejected(string $path): bool
    {
        if (self::$rejectRegex === []) {
            return false;
        }

        $needle = ltrim($path, '/');

        foreach (self::$rejectRegex as $regex) {
            if (preg_match($regex, $needle) === 1) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public static function getUrlEndpoint(): string
    {
        return self::$urlEndpoint;
    }

    /** @return int[] */
    public static function getSrcsetWidths(): array
    {
        return self::$srcsetWidths;
    }

    public static function getDefaultSizes(): string
    {
        return self::$defaultSizes;
    }

    public static function getQuality(): int
    {
        return self::$quality;
    }

    /** @return string[] */
    public static function getExtraTransformations(): array
    {
        return self::$extraTransformations;
    }

    /** @return int Number of compiled reject patterns. */
    public static function getRejectPatternCount(): int
    {
        return \count(self::$rejectRegex);
    }

    public static function hasPrivateKey(): bool
    {
        return self::$privateKey !== '';
    }

    public static function hasPublicKey(): bool
    {
        return self::$publicKey !== '';
    }

    // -------------------------------------------------------------------------
    // Media Library API — require public + private keys, use curl internally
    // -------------------------------------------------------------------------

    /**
     * Upload a local file to ImageKit.
     *
     * @return  array<string, mixed>  Decoded API response ({fileId, name, url, …}).
     *
     * @throws  \RuntimeException  On API or curl error.
     *
     * @since   1.0.0
     */
    public static function upload(string $filePath, string $fileName, string $folder = '/'): array
    {
        self::requireKeys();

        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            throw new \RuntimeException('ImageKit upload: could not read file at ' . $filePath);
        }

        $payload = [
            'file'              => base64_encode($contents),
            'fileName'          => $fileName,
            'folder'            => $folder,
            'useUniqueFileName' => 'true',
        ];

        return self::request('POST', self::UPLOAD_ENDPOINT, $payload, multipart: true);
    }

    /**
     * Upload a remote URL to ImageKit.
     *
     * @return  array<string, mixed>  Decoded API response.
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

        return self::request('POST', self::UPLOAD_ENDPOINT, $payload, multipart: true);
    }

    /**
     * List files in the ImageKit Media Library.
     *
     * @param   array<string, scalar>  $options  Query parameters (e.g. ['path' => '/', 'limit' => 20]).
     *
     * @return  array<int, array<string, mixed>>  Array of file objects.
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
     * @return  array<string, mixed>  File detail object.
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
     * Generate a signed URL for private / access-controlled images.
     *
     * @since   1.0.0
     */
    public static function getSignedUrl(
        string $imagePath,
        string $transformation = '',
        int $expireSeconds = 300,
    ): string {
        self::requireKeys();

        $expiry  = time() + $expireSeconds;
        $path    = ltrim($imagePath, '/');
        $urlPath = $transformation !== ''
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
     * Compile a newline-delimited reject list into anchored PCRE patterns.
     *
     * Lines starting with `#` and blank lines are ignored.
     *
     * @return  string[]
     */
    private static function compileRejectPatterns(string $rejectPaths): array
    {
        $regex = [];

        foreach (preg_split('/\r\n|\r|\n/', $rejectPaths) ?: [] as $line) {
            $pattern = trim($line);

            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }

            $regex[] = '~^' . self::globToRegex(ltrim($pattern, '/')) . '$~i';
        }

        return $regex;
    }

    /**
     * Convert a simple glob pattern into a PCRE fragment (no delimiters/anchors).
     *
     * Supports `**`, `*`, `?`. Everything else is escaped.
     */
    private static function globToRegex(string $glob): string
    {
        $out = '';
        $i   = 0;
        $len = \strlen($glob);

        while ($i < $len) {
            $char = $glob[$i];

            if ($char === '*') {
                if ($i + 1 < $len && $glob[$i + 1] === '*') {
                    $out .= '.*';
                    $i  += 2;
                    continue;
                }

                $out .= '[^/]*';
                $i++;
                continue;
            }

            if ($char === '?') {
                $out .= '[^/]';
                $i++;
                continue;
            }

            $out .= preg_quote($char, '~');
            $i++;
        }

        return $out;
    }

    /**
     * Make an API request using curl.
     *
     * @param   array<string, mixed>  $payload  Request body for POST requests.
     *
     * @return  array<string, mixed>|array<int, array<string, mixed>>  Decoded JSON response body.
     *
     * @throws  \RuntimeException  On curl error or non-2xx HTTP status.
     */
    private static function request(
        string $method,
        string $url,
        array $payload = [],
        bool $multipart = false,
    ): array {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, self::$privateKey . ':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = ['Accept: application/json'];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            if ($multipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } else {
                $headers[] = 'Content-Type: application/json';
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
                'ImageKit API error (HTTP ' . $httpCode . '): ' . (\is_string($body) ? $body : '')
            );
        }

        if ($body === '' || $body === false) {
            return [];
        }

        $decoded = json_decode((string) $body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('ImageKit API: invalid JSON response — ' . $body);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Assert that API keys are configured before making an API call.
     *
     * @throws  \LogicException  If the private key is not set.
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
