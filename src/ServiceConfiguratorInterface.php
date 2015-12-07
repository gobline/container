<?php

/*
 * Gobline Framework
 *
 * (c) Mathieu Decaffmeyer <mdecaffmeyer@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gobline\Container;

/**
 * @author Mathieu Decaffmeyer <mdecaffmeyer@gmail.com>
 */
interface ServiceConfiguratorInterface
{
    public function configure($service, array $config);
}
