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
 * French strings for the FormaSuisse patch (see FORMASUISSE.md).
 *
 * Only the strings added by the patch live here; everything else comes from
 * the AMOS language pack.
 *
 * @package    mod_zoom
 * @copyright  2026 FormaSuisse SA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['joinbrowser'] = 'Rejoindre depuis le navigateur';
$string['zoomerr_registration_dropped'] = 'Zoom a accepté l\'enregistrement mais a silencieusement supprimé les paramètres d\'inscription : la séance n\'a PAS été enregistrée comme configurée. Causes connues : l\'animateur n\'a pas de licence Zoom (siège), la séance a été convertie en réunion personnelle (PMI) de l\'animateur, ou un conflit d\'exigences d\'authentification. Corrigez la cause puis enregistrez à nouveau.';
$string['zoomerr_seat_unavailable'] = 'Aucune licence Zoom (siège) n\'est disponible pour cette action : tous les sièges sont utilisés par des sessions en cours ou protégées, ou le quota mensuel de réattribution est épuisé. Veuillez réessayer après la fin des sessions en cours, ou contacter l\'administration.';
