<?php

/**
 * This file is part of
 *
 * CalendarEditorBundle
 * @copyright  Daniel Gaußmann 2018
 * @author     Daniel Gaußmann (Gausi)
 * @package    Calendar_Editor
 * @license    LGPL-3.0-or-later
 * @see        https://github.com/Diversworld/Contao-CalendarEditor
 *
 * an extension for
 * Contao Open Source CMS
 * (c) Leo Feyer, LGPL-3.0-or-later
 *
 */

namespace Diversworld\CalendarEditorBundle;

use \Diversworld\CalendarEditorBundle\DependencyInjection\CalendarEditorExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class CalendarEditorBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): CalendarEditorExtension
    {
        return new CalendarEditorExtension();
    }

    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
