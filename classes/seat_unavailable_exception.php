<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Exception thrown when no Zoom seat can be safely provided to a host.
 *
 * FormaSuisse patch (see FORMASUISSE.md). Raised by webservice::with_seat()
 * when the license pool is exhausted and every current holder is live,
 * leased or protected ($reason = 'pool'), or when Zoom refuses the promote
 * PATCH because the monthly license-reassignment quota is spent
 * ($reason = 'quota').
 *
 * @package   mod_zoom
 * @copyright 2026 FormaSuisse SA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom;

use moodle_exception;

/**
 * Seat unavailable exception class.
 */
class seat_unavailable_exception extends moodle_exception {
    /**
     * Why no seat could be provided: 'pool' or 'quota'.
     * @var string
     */
    public $reason;

    /**
     * Constructor
     *
     * @param string $reason 'pool' (nothing safely stealable) or 'quota' (reassignment quota spent).
     * @param string $debuginfo optional debugging information
     */
    public function __construct($reason, $debuginfo = null) {
        $this->reason = $reason;

        parent::__construct('zoomerr_seat_unavailable', 'mod_zoom', '', null, $debuginfo);
    }
}
