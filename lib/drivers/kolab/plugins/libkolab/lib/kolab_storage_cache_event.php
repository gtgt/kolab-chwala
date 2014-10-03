<?php

/**
 * Kolab storage cache class for calendar event objects
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_storage_cache_event extends kolab_storage_cache
{
    protected $extra_cols = array('dtstart','dtend');

    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     *
     * @override
     */
    protected function _serialize($object)
    {
        $sql_data = parent::_serialize($object);

        $sql_data['dtstart'] = is_object($object['start']) ? $object['start']->format(self::DB_DATE_FORMAT) : date(self::DB_DATE_FORMAT, $object['start']);
        $sql_data['dtend']   = is_object($object['end'])   ? $object['end']->format(self::DB_DATE_FORMAT)   : date(self::DB_DATE_FORMAT, $object['end']);

        // extend date range for recurring events
        if ($object['recurrence'] && $object['_formatobj']) {
            $recurrence = new kolab_date_recurrence($object['_formatobj']);
            $dtend = $recurrence->end() ?: new DateTime('now +10 years');
            $sql_data['dtend'] = $dtend->format(self::DB_DATE_FORMAT);
        }

        return $sql_data;
    }
}