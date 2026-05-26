<?php

namespace Diversworld\CalendarEditorBundle\Models;

use Contao\CalendarModel;
use function is_array;

class CalendarModelEdit extends CalendarModel
{
    public static function findByIds($arrIds, array $arrOptions = array())
    {
        if (empty($arrIds) || !is_array($arrIds)) {
            return null;
        }

        return static::findBy(['tl_calendar.id IN (' . implode(',', array_map('intval', $arrIds)) . ')'], null, $arrOptions);
    }

}
