<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe PDF embedded read-only preview handler supporting .pdf files.
 */
class PdfPreviewHandler extends AbstractPreviewHandler
{
    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = $this->normalizeExtension($extension);
        $normalizedMime = $this->normalizeMimeType($mimeType);

        if ($normalizedExt === 'pdf') {
            return true;
        }

        return str_contains($normalizedMime, 'application/pdf') || str_contains($normalizedMime, 'application/x-pdf');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $sizeBytes = strlen($content);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'isBinary' => true,
            'sizeBytes' => $sizeBytes,
        ];

        return new PreviewResult('', true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'PdfPreviewHandler';
    }
}
