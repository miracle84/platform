<?php

namespace Oro\Component\Layout;

class HierarchyCollection
{
    /**
     * @var array
     *
     * Example:
     *  [
     *      'root' => [
     *          'header' => [
     *              'logo' => [],
     *              'menu' => [
     *                  'favorites' => [],
     *                  'history'   => []
     *              ]
     *          ],
     *          'body'   => [],
     *          'footer' => [
     *              'links' => []
     *          ]
     *      ]
     *  ]
     */
    protected $hierarchy = [];

    /**
     * Returns the identifier of the root item
     *
     * @return string
     *
     * @throws Exception\LogicException if the root item does not exist
     */
    public function getRootId()
    {
        if (empty($this->hierarchy)) {
            throw new Exception\LogicException('The root item does not exist.');
        }

        reset($this->hierarchy);
        $id = key($this->hierarchy);

        return $id;
    }

    /**
     * Gets hierarchy by the path
     *
     * @param string[] $path
     *
     * @return array
     */
    public function get(array $path)
    {
        $current = &$this->hierarchy;
        foreach ($path as $childId) {
            if (!isset($current[$childId])) {
                return [];
            }
            $current = &$current[$childId];
        }

        return $current;
    }

    /**
     * Adds a new item to the hierarchy
     *
     * @param string[]    $parentPath
     * @param string      $id
     * @param string|null $siblingId The id of nearest neighbor item
     * @param bool        $prepend   Determines whether the item should be added before or after
     *                               the specified sibling item
     *                               If the sibling item is not specified and $prepend is true than
     *                               the item is added to the begin of the parent hierarchy
     * @param array       $children  The child hierarchy
     *
     * @throws Exception\LogicException if the operation failed
     */
    public function add(array $parentPath, $id, $siblingId = null, $prepend = false, array $children = [])
    {
        $current          = &$this->hierarchy;
        $parentPathLength = count($parentPath);
        for ($i = 0; $i < $parentPathLength; $i++) {
            if (!isset($current[$parentPath[$i]])) {
                if ($i === 0) {
                    throw new Exception\LogicException(
                        sprintf(
                            'Cannot add "%s" item to "%s" because "%s" root item does not exist.',
                            $id,
                            implode('/', $parentPath),
                            $parentPath[$i]
                        )
                    );
                } else {
                    throw new Exception\LogicException(
                        sprintf(
                            'Cannot add "%s" item to "%s" because "%s" item does not have "%s" child.',
                            $id,
                            implode('/', $parentPath),
                            $parentPath[$i - 1],
                            $parentPath[$i]
                        )
                    );
                }
            }
            $current = &$current[$parentPath[$i]];
        }
        if (isset($current[$id])) {
            throw new Exception\LogicException(
                sprintf(
                    'Cannot add "%s" item to "%s" because such item already exists.',
                    $id,
                    implode('/', $parentPath)
                )
            );
        }
        if (empty($siblingId)) {
            if ($prepend && !empty($current)) {
                $current = array_merge([$id => $children], $current);
            } else {
                $current[$id] = $children;
            }
        } elseif (!isset($current[$siblingId])) {
            throw new Exception\LogicException(
                sprintf(
                    'Cannot add "%s" item to "%s" because "%s" sibling item does not exist.',
                    $id,
                    implode('/', $parentPath),
                    $siblingId
                )
            );
        } else {
            $new = [];
            foreach ($current as $key => $value) {
                if ($key === $siblingId) {
                    if ($prepend) {
                        $new[$id]  = $children;
                        $new[$key] = $value;
                    } else {
                        $new[$key] = $value;
                        $new[$id]  = $children;
                    }
                } else {
                    $new[$key] = $value;
                }
            }
            $current = $new;
        }
    }

    /**
     * Removes the item from the hierarchy
     *
     * @param string[] $path
     */
    public function remove(array $path)
    {
        $current    = &$this->hierarchy;
        $pathLength = count($path);
        for ($i = 0; $i < $pathLength; $i++) {
            if (!isset($current[$path[$i]])) {
                break;
            }
            if ($i === $pathLength - 1) {
                unset($current[$path[$i]]);
                break;
            }
            $current = &$current[$path[$i]];
        }
    }

    /**
     * Checks whether the hierarchy is empty
     *
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->hierarchy);
    }

    /**
     * Removes all data from this collection
     */
    public function clear()
    {
        $this->hierarchy = [];
    }
}
