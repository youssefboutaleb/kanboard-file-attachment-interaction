<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Stubs;

/**
 * Minimal ArrayAccess container mirroring Pimple\Container for controller tests.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class FakeContainer implements \ArrayAccess
{
    /**
     * @var array<string, mixed>
     */
    private array $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    /**
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->services[(string) $offset]);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->services[(string) $offset] ?? null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        $this->services[(string) $offset] = $value;
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->services[(string) $offset]);
    }
}

/**
 * Records the parameters Kanboard would receive on an HTTP request.
 */
class FakeRequest
{
    /**
     * @var array<string, mixed>
     */
    private array $params;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public function getIntegerParam(string $name, int $default = 0): int
    {
        return isset($this->params[$name]) ? (int) $this->params[$name] : $default;
    }

    public function getStringParam(string $name, string $default = ''): string
    {
        return isset($this->params[$name]) ? (string) $this->params[$name] : $default;
    }
}

/**
 * Captures the rendered template name and variables.
 */
class FakeTemplate
{
    public string $renderedTemplate = '';

    /**
     * @var array<string, mixed>
     */
    public array $renderedVars = [];

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $this->renderedTemplate = $template;
        $this->renderedVars = $vars;

        return '<div class="page-header">rendered:' . $template . '</div>';
    }
}

/**
 * Captures the emitted HTML body and status code.
 */
class FakeResponse
{
    public ?string $body = null;
    public int $statusCode = 0;

    public function html(string $data, int $statusCode = 200): string
    {
        $this->body = $data;
        $this->statusCode = $statusCode;

        return $data;
    }
}

/**
 * Returns a fixed attachment row, like Kanboard's TaskFileModel.
 */
class FakeFileModel
{
    /**
     * @var array<string, mixed>
     */
    private array $file;

    /**
     * @param array<string, mixed> $file
     */
    public function __construct(array $file)
    {
        $this->file = $file;
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(int $fileId): array
    {
        return $this->file;
    }
}

/**
 * Returns fixed binary content, like Kanboard's ObjectStorage.
 */
class FakeObjectStorage
{
    private string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function get(string $path): string
    {
        return $this->content;
    }
}
