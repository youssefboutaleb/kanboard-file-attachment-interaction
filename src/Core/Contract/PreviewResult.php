<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core\Contract;

/**
 * Immutable value object representing the result of a file preview operation.
 */
final class PreviewResult
{
    private string $content;
    private bool $isFormatted;
    /**
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $content, bool $isFormatted = false, array $metadata = [])
    {
        $this->content = $content;
        $this->isFormatted = $isFormatted;
        $this->metadata = $metadata;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function isFormatted(): bool
    {
        return $this->isFormatted;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
