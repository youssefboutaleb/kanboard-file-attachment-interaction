<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileRevisionWriterInterface;

/**
 * In-memory revision writer for standalone unit testing and isolated environments.
 */
class MockFileRevisionWriter implements FileRevisionWriterInterface
{
    /**
     * @var list<array{mode: string, filename: string, content: string}>
     */
    private array $writes = [];

    private bool $succeed;

    public function __construct(bool $succeed = true)
    {
        $this->succeed = $succeed;
    }

    /**
     * Simulate a storage backend rejecting writes.
     */
    public function setSucceed(bool $succeed): void
    {
        $this->succeed = $succeed;
    }

    public function writeRevision(string $filename, string $content): bool
    {
        return $this->record('revision', $filename, $content);
    }

    public function overwriteContent(string $filename, string $content): bool
    {
        return $this->record('overwrite', $filename, $content);
    }

    /**
     * @return list<array{mode: string, filename: string, content: string}>
     */
    public function getWrites(): array
    {
        return $this->writes;
    }

    /**
     * @return array{mode: string, filename: string, content: string}|null
     */
    public function getLastWrite(): ?array
    {
        return $this->writes === [] ? null : $this->writes[count($this->writes) - 1];
    }

    /**
     * Stored content for a filename, or null when it was never written.
     */
    public function getContent(string $filename): ?string
    {
        foreach (array_reverse($this->writes) as $write) {
            if ($write['filename'] === $filename) {
                return $write['content'];
            }
        }

        return null;
    }

    private function record(string $mode, string $filename, string $content): bool
    {
        if (!$this->succeed) {
            return false;
        }

        $this->writes[] = [
            'mode' => $mode,
            'filename' => $filename,
            'content' => $content,
        ];

        return true;
    }
}
