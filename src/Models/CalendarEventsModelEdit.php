<?php

namespace Diversworld\CalendarEditorBundle\Models;

use Contao\CalendarEventsModel;
use Contao\Date;

class CalendarEventsModelEdit extends CalendarEventsModel
{
    public static function findByIdOrAlias($val, array $opt = []): ?CalendarEventsModel
    {
        $t = static::$strTable;
        $arrColumns = !is_numeric($val) ? array("$t.alias=?") : array("$t.id=?");

        if (!static::isPreviewMode($opt)) {
            $time = Date::floorToMinute();
            $arrColumns[] = "$t.published=1 AND ($t.start='' OR $t.start<=$time) AND ($t.stop='' OR $t.stop>$time)";
        }

        $eventObject = static::findOneBy($arrColumns, $val, $opt);

        //return static::findOneBy($arrColumns, $ids, $options);
        return $eventObject;
    }

}
