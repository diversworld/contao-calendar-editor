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
        $t = static::$strTable;
        $arrColumns = ["$t.id IN(" . implode(',', array_map('\\intval', $arrIds)) . ")"];

        // Wichtig: Signature von Model::findBy ist findBy(array $columns, mixed $values=null, array $options=array())
        // Da wir die IDs direkt im Column-Statement verwenden, gibt es keine Values → also null übergeben.
        return static::findBy($arrColumns, null, $arrOptions);
    }

}
