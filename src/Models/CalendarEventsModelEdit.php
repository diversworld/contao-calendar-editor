<?php

namespace Diversworld\CalendarEditorBundle\Models;

use Contao\CalendarEventsModel;
use Contao\Date;
use Contao\System;
use Psr\Log\LoggerInterface;

class CalendarEventsModelEdit extends CalendarEventsModel
{

    public static function findByIdOrAlias($val, array $opt = []): ?CalendarEventsModel
    {
        $t = static::$strTable;
        $arrColumns = !is_numeric($val) ? array("$t.alias=?") : array("$t.id=?");

        if (!static::isPreviewMode($opt)) {
            $time = Date::floorToMinute();
            $arrColumns[] = "($t.start='' OR $t.start<='$time') AND ($t.stop='' OR $t.stop>'" . ($time + 60) . "')";
        }

        return static::findOneBy($arrColumns, $val, $opt);
    }

}
