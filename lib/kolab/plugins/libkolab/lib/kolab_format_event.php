<?php

/**
 * Kolab Event model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_event extends kolab_format_xcal
{
    public $CTYPEv2 = 'application/x-vnd.kolab.event';

    protected $objclass = 'Event';
    protected $read_func = 'readEvent';
    protected $write_func = 'writeEvent';

    /**
     * Default constructor
     */
    function __construct($data = null, $version = 3.0)
    {
        parent::__construct(is_string($data) ? $data : null, $version);

        // got an Event object as argument
        if (is_object($data) && is_a($data, $this->objclass)) {
            $this->obj = $data;
            $this->loaded = true;
        }
    }

    /**
     * Clones into an instance of libcalendaring's extended EventCal class
     *
     * @return mixed EventCal object or false on failure
     */
    public function to_libcal()
    {
        static $error_logged = false;

        if (class_exists('kolabcalendaring')) {
            return new EventCal($this->obj);
        }
        else if (!$error_logged) {
            $error_logged = true;
            rcube::raise_error(array(
                'code' => 900, 'type' => 'php',
                'message' => "required kolabcalendaring module not found"
            ), true);
        }

        return false;
    }

    /**
     * Set event properties to the kolabformat object
     *
     * @param array  Event data as hash array
     */
    public function set(&$object)
    {
        // set common xcal properties
        parent::set($object);

        // do the hard work of setting object values
        $this->obj->setStart(self::get_datetime($object['start'], null, $object['allday']));
        $this->obj->setEnd(self::get_datetime($object['end'], null, $object['allday']));
        $this->obj->setTransparency($object['free_busy'] == 'free');

        $status = kolabformat::StatusUndefined;
        if ($object['free_busy'] == 'tentative')
            $status = kolabformat::StatusTentative;
        if ($object['cancelled'])
            $status = kolabformat::StatusCancelled;
        $this->obj->setStatus($status);

        // save recurrence exceptions
        if ($object['recurrence']['EXCEPTIONS']) {
            $vexceptions = new vectorevent;
            foreach((array)$object['recurrence']['EXCEPTIONS'] as $exception) {
                $exevent = new kolab_format_event;
                $exevent->set($this->compact_exception($exception, $object));  // only save differing values
                $exevent->obj->setRecurrenceID(self::get_datetime($exception['start'], null, true), (bool)$exception['thisandfuture']);
                $vexceptions->push($exevent->obj);
            }
            $this->obj->setExceptions($vexceptions);
        }

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return !$this->formaterror && (($this->data && !empty($this->data['start']) && !empty($this->data['end'])) ||
            (is_object($this->obj) && $this->obj->isValid() && $this->obj->uid()));
    }

    /**
     * Convert the Event object into a hash array data structure
     *
     * @param array Additional data for merge
     *
     * @return array  Event data as hash array
     */
    public function to_array($data = array())
    {
        // return cached result
        if (!empty($this->data))
            return $this->data;

        // read common xcal props
        $object = parent::to_array($data);

        // read object properties
        $object += array(
            'end'         => self::php_datetime($this->obj->end()),
            'allday'      => $this->obj->start()->isDateOnly(),
            'free_busy'   => $this->obj->transparency() ? 'free' : 'busy',  // TODO: transparency is only boolean
            'attendees'   => array(),
        );

        // derive event end from duration (#1916)
        if (!$object['end'] && $object['start'] && ($duration = $this->obj->duration()) && $duration->isValid()) {
            $interval = new DateInterval('PT0S');
            $interval->d = $duration->weeks() * 7 + $duration->days();
            $interval->h = $duration->hours();
            $interval->i = $duration->minutes();
            $interval->s = $duration->seconds();
            $object['end'] = clone $object['start'];
            $object['end']->add($interval);
        }

        // organizer is part of the attendees list in Roundcube
        if ($object['organizer']) {
            $object['organizer']['role'] = 'ORGANIZER';
            array_unshift($object['attendees'], $object['organizer']);
        }

        // status defines different event properties...
        $status = $this->obj->status();
        if ($status == kolabformat::StatusTentative)
          $object['free_busy'] = 'tentative';
        else if ($status == kolabformat::StatusCancelled)
          $object['cancelled'] = true;

        // read exception event objects
        if (($exceptions = $this->obj->exceptions()) && is_object($exceptions) && $exceptions->size()) {
            for ($i=0; $i < $exceptions->size(); $i++) {
                if (($exobj = $exceptions->get($i))) {
                    $exception = new kolab_format_event($exobj);
                    if ($exception->is_valid()) {
                        $object['recurrence']['EXCEPTIONS'][] = $this->expand_exception($exception->to_array(), $object);
                    }
                }
            }
        }
        // this is an exception object
        else if ($this->obj->recurrenceID()->isValid()) {
          $object['thisandfuture'] = $this->obj->thisAndFuture();
        }

        return $this->data = $object;
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        foreach ((array)$this->data['categories'] as $cat) {
            $tags[] = rcube_utils::normalize_string($cat);
        }

        if (!empty($this->data['alarms'])) {
            $tags[] = 'x-has-alarms';
        }

        return $tags;
    }

    /**
     * Remove some attributes from the exception container
     */
    private function compact_exception($exception, $master)
    {
      $forbidden = array('recurrence','organizer','attendees','sequence');

      foreach ($forbidden as $prop) {
        if (array_key_exists($prop, $exception)) {
          unset($exception[$prop]);
        }
      }

      return $exception;
    }

    /**
     * Copy attributes not specified by the exception from the master event
     */
    private function expand_exception($exception, $master)
    {
      foreach ($master as $prop => $value) {
        if (empty($exception[$prop]) && !empty($value))
          $exception[$prop] = $value;
      }

      return $exception;
    }

}
