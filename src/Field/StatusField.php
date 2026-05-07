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

namespace Hans2103\Plugin\System\ImageKit\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Read-only diagnostic panel rendered inside the plugin params form.
 *
 * Reflects the current values of sibling fields so administrators can verify
 * configuration without saving + visiting the front-end.
 *
 * @since  2.0.0
 */
class StatusField extends FormField
{
    /** @var string */
    protected $type = 'Status';

    /**
     * Hide the standard label column — this field renders full-width content.
     */
    public function getLabel(): string
    {
        return '';
    }

    public function renderField($options = []): string
    {
        $options['hiddenLabel'] = true;

        return parent::renderField($options);
    }

    protected function getInput(): string
    {
        $endpoint   = (string) $this->getParam('url_endpoint', '');
        $publicKey  = (string) $this->getParam('public_key', '');
        $privateKey = (string) $this->getParam('private_key', '');
        $quality    = (string) $this->getParam('quality', '80');
        $widths     = (string) $this->getParam('srcset_widths', '320,480,768,1024,1280,1600');
        $sizes      = (string) $this->getParam('default_sizes', '100vw');
        $extra      = (string) $this->getParam('extra_transformations', '');
        $rejects    = (string) $this->getParam('reject_paths', '');

        $imageOn      = (bool) $this->getParam('enable_image_delivery', 1);
        $videoOn      = (bool) $this->getParam('enable_video_delivery', 0);
        $tplAssetsOn  = (bool) $this->getParam('enable_template_assets', 0);
        $extAssetsOn  = (bool) $this->getParam('enable_extension_assets', 0);
        $coreAssetsOn = (bool) $this->getParam('enable_core_assets', 0);

        $rejectCount = $this->countLines($rejects);

        $sampleImagePath = 'images/banner.jpg';
        $sampleAssetPath = 'templates/cassiopeia/css/template.css';

        $imageSampleUrl = $endpoint !== ''
            ? $this->buildPreviewImageUrl($endpoint, $sampleImagePath, 1280, (int) $quality, $extra)
            : '';

        $assetSampleUrl = $endpoint !== ''
            ? rtrim($endpoint, '/') . '/' . $sampleAssetPath
            : '';

        $rows = [
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_ENDPOINT'), $endpoint !== '' ? $this->escape($endpoint) : $this->muted(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_NOT_SET'))),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_PUBLIC_KEY'), $this->yesNo($publicKey !== '')),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_PRIVATE_KEY'), $this->yesNo($privateKey !== '')),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_QUALITY'), $this->escape($quality)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_WIDTHS'), $this->escape($widths)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_SIZES'), $this->escape($sizes)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_EXTRA'), $extra !== '' ? $this->escape($extra) : $this->muted('—')),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_REJECT'), $rejectCount > 0 ? Text::plural('PLG_SYSTEM_IMAGEKIT_STATUS_REJECT_N', $rejectCount) : $this->muted('—')),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_IMAGE'), $this->yesNo($imageOn)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_VIDEO'), $this->yesNo($videoOn)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_ASSETS_TPL'), $this->yesNo($tplAssetsOn)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_ASSETS_EXT'), $this->yesNo($extAssetsOn)),
            $this->row(Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_LABEL_ASSETS_CORE'), $this->yesNo($coreAssetsOn)),
        ];

        $samples = '';

        if ($endpoint !== '') {
            $samples = sprintf(
                '<h4 class="mt-3">%s</h4>'
                . '<p class="small text-muted">%s</p>'
                . '<dl class="row small">'
                . '<dt class="col-sm-3">%s</dt><dd class="col-sm-9"><code>%s</code></dd>'
                . '<dt class="col-sm-3">%s</dt><dd class="col-sm-9"><code>%s</code></dd>'
                . '</dl>',
                Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_HEADING_SAMPLES'),
                Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_SAMPLES_INTRO'),
                Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_SAMPLE_IMAGE_LABEL'),
                $this->escape($imageSampleUrl),
                Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_SAMPLE_ASSET_LABEL'),
                $this->escape($assetSampleUrl),
            );
        }

        $diagnostics = $this->buildPlainTextReport(
            endpoint:        $endpoint,
            publicKey:       $publicKey !== '',
            privateKey:      $privateKey !== '',
            quality:         $quality,
            widths:          $widths,
            sizes:           $sizes,
            extra:           $extra,
            rejectCount:     $rejectCount,
            imageOn:         $imageOn,
            videoOn:         $videoOn,
            tplAssetsOn:     $tplAssetsOn,
            extAssetsOn:     $extAssetsOn,
            coreAssetsOn:    $coreAssetsOn,
            imageSampleUrl:  $imageSampleUrl,
            assetSampleUrl:  $assetSampleUrl,
        );

        return sprintf(
            '<div class="alert alert-info" role="status" aria-live="polite">'
            . '<h3 class="alert-heading h5">%s</h3>'
            . '<dl class="row small mb-0">%s</dl>'
            . '%s'
            . '<h4 class="mt-3">%s</h4>'
            . '<p class="small text-muted">%s</p>'
            . '<label class="form-label visually-hidden" for="plg_imagekit_diag">%s</label>'
            . '<textarea id="plg_imagekit_diag" class="form-control font-monospace" rows="10" readonly aria-label="%s">%s</textarea>'
            . '</div>',
            Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_HEADING_OVERVIEW'),
            implode('', $rows),
            $samples,
            Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_HEADING_REPORT'),
            Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_REPORT_INTRO'),
            Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_REPORT_LABEL'),
            Text::_('PLG_SYSTEM_IMAGEKIT_STATUS_REPORT_LABEL'),
            $this->escape($diagnostics),
        );
    }

    private function getParam(string $name, mixed $default): mixed
    {
        return $this->form?->getValue($name, 'params', $default) ?? $default;
    }

    private function buildPreviewImageUrl(
        string $endpoint,
        string $imagePath,
        int $width,
        int $quality,
        string $extra,
    ): string {
        $tokens = ['w-' . $width, 'q-' . max(1, min(100, $quality)), 'f-auto'];

        foreach (array_filter(array_map('trim', explode(',', $extra))) as $token) {
            $tokens[] = $token;
        }

        return rtrim($endpoint, '/') . '/tr:' . implode(',', $tokens) . '/' . ltrim($imagePath, '/');
    }

    private function buildPlainTextReport(
        string $endpoint,
        bool $publicKey,
        bool $privateKey,
        string $quality,
        string $widths,
        string $sizes,
        string $extra,
        int $rejectCount,
        bool $imageOn,
        bool $videoOn,
        bool $tplAssetsOn,
        bool $extAssetsOn,
        bool $coreAssetsOn,
        string $imageSampleUrl,
        string $assetSampleUrl,
    ): string {
        return implode("\n", [
            '== ImageKit plugin diagnostics ==',
            'Site:          ' . Uri::root(),
            'Endpoint:      ' . ($endpoint !== '' ? $endpoint : '(not set)'),
            'Public key:    ' . ($publicKey ? 'yes' : 'no'),
            'Private key:   ' . ($privateKey ? 'yes' : 'no'),
            '',
            'Quality:       ' . $quality,
            'Widths:        ' . $widths,
            'Sizes:         ' . $sizes,
            'Extra trans:   ' . ($extra !== '' ? $extra : '(none)'),
            'Reject lines:  ' . $rejectCount,
            '',
            'Image delivery:           ' . ($imageOn ? 'on' : 'off'),
            'Video delivery:           ' . ($videoOn ? 'on' : 'off'),
            'Asset delivery — templates: ' . ($tplAssetsOn ? 'on' : 'off'),
            'Asset delivery — extensions: ' . ($extAssetsOn ? 'on' : 'off'),
            'Asset delivery — core:    ' . ($coreAssetsOn ? 'on' : 'off'),
            '',
            'Sample image: ' . $imageSampleUrl,
            'Sample asset: ' . $assetSampleUrl,
        ]);
    }

    private function row(string $label, string $value): string
    {
        return '<dt class="col-sm-4">' . $this->escape($label) . '</dt>'
            . '<dd class="col-sm-8 mb-1">' . $value . '</dd>';
    }

    private function yesNo(bool $value): string
    {
        return $value
            ? '<span class="badge bg-success">' . $this->escape(Text::_('JYES')) . '</span>'
            : '<span class="badge bg-secondary">' . $this->escape(Text::_('JNO')) . '</span>';
    }

    private function muted(string $text): string
    {
        return '<span class="text-muted">' . $this->escape($text) . '</span>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function countLines(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $count = 0;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '#')) {
                $count++;
            }
        }

        return $count;
    }
}
