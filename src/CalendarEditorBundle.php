<?php

/**
 * This file is part of
 *
 * CalendarEditorBundle
 * @copyright  Daniel Gaußmann 2018
 * @author     Daniel Gaußmann (Gausi)
 * @package    Calendar_Editor
 * @license    LGPL-3.0-or-later
 * @see        https://github.com/DanielGausi/Contao-CalendarEditor
 *
 * an extension for
 * Contao Open Source CMS
 * (c) Leo Feyer, LGPL-3.0-or-later
 *
 */

namespace DanielGausi\CalendarEditorBundle;

use DanielGausi\CalendarEditorBundle\DependencyInjection\CalendarEditorExtension;
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
}

