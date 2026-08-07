<?php

namespace Kanboard\Controller;

/**
 * Test double mirroring the semantics of Kanboard\Core\Base.
 *
 * IMPORTANT: like Kanboard core, this class resolves services through __get()
 * and deliberately does NOT implement __isset(). Preserving that asymmetry is
 * what allows the regression test for the "empty preview modal" bug to fail if
 * `isset($this->request)`-style context detection is ever reintroduced.
 *
 * @property mixed $request
 * @property mixed $response
 * @property mixed $template
 * @property mixed $taskFileModel
 * @property mixed $projectFileModel
 * @property mixed $taskFinderModel
 * @property mixed $objectStorage
 */
abstract class BaseController
{
    /** @var mixed */
    protected $container;

    /**
     * @param mixed $container
     */
    public function __construct($container = null)
    {
        $this->container = $container;
    }

    /**
     * Resolve a service from the container, exactly like Kanboard\Core\Base.
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        if ($this->container instanceof \ArrayAccess && $this->container->offsetExists($name)) {
            return $this->container[$name];
        }

        return null;
    }
}
