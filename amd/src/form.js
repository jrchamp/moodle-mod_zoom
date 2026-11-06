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
 * Advanced scripting needs for the activity module form.
 *
 * @module     mod_zoom/form
 * @copyright  2018 UC Regents
 * @author     Kubilay Agi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from "jquery";
import AutoComplete from "core/form-autocomplete";
import Notification from "core/notification";
// For backwards compatibility with Moodle <4.3
import {get_string as getString, get_strings as getStrings} from "core/str";

/**
 * CSS selectors used throughout the file.
 *
 * @type {object}
 */
const SELECTORS = {
    REPEAT_SELECT: 'select[name="recurrence_type"]',
    REPEAT_INTERVAL: '.repeat_interval',
    REPEAT_INTERVAL_DAILY: '#interval_daily',
    REPEAT_INTERVAL_WEEKLY: '#interval_weekly',
    REPEAT_INTERVAL_MONTHLY: '#interval_monthly',
    REPEAT_INTERVAL_OPTIONS: 'select[name="repeat_interval"] option',
    START_TIME: 'select[name*="start_time"]',
    DURATION: '*[name*="duration"]',
    RECURRING: 'input[name="recurring"][type!="hidden"]',
    OPTION_JBH: 'input[name="option_jbh"][type!="hidden"]',
    OPTION_WAITING_ROOM: 'input[name="option_waiting_room"][type!="hidden"]'
};

/**
 * Repeat interval options.
 *
 * @type {object}
 */
const REPEAT_OPTIONS = {
    NONE: 0,
    DAILY: 1,
    WEEKLY: 2,
    MONTHLY: 3
};

/**
 * The max values for each repeat option.
 *
 * @type {object}
 */
const REPEAT_MAX_OPTIONS = {
    DAILY: 90,
    WEEKLY: 12,
    MONTHLY: 3
};

/**
 * The init function.
 */
export const init = () => {
    const optionJoinBeforeHost = $(SELECTORS.OPTION_JBH);
    const optionWaitingRoom = $(SELECTORS.OPTION_WAITING_ROOM);
    optionJoinBeforeHost.on('change', () => {
        if (optionJoinBeforeHost.is(':checked') === true) {
            optionWaitingRoom.prop('checked', false);
        }
    });
    optionWaitingRoom.on('change', () => {
        if (optionWaitingRoom.is(':checked') === true) {
            optionJoinBeforeHost.prop('checked', false);
        }
    });

    // Add listener to "Repeat Every" drop-down and initialize.
    $(SELECTORS.REPEAT_SELECT).on('change', () => {
        const repeatValue = parseInt($(SELECTORS.REPEAT_SELECT).val(), 10);
        toggleStartTimeDuration(repeatValue);
        toggleRepeatIntervalText(repeatValue);
        limitRepeatValues(repeatValue);
    }).trigger('change');

    // Add listener for the "Recurring" checkbox
    $(SELECTORS.RECURRING).on('change', () => {
        const repeatValue = parseInt($(SELECTORS.REPEAT_SELECT).val(), 10);
        toggleStartTimeDuration(repeatValue);
    });

    new BreakoutroomsEditor();
};

/**
 * Toggle start time and duration elements.
 *
 * @param {number} repeatValue The repeat option.
 */
const toggleStartTimeDuration = (repeatValue) => {
    // Disable start time and duration if "No Fixed Time" recurring meeting/webinar selected.
    let disabled = false;
    if ($(SELECTORS.RECURRING).prop('checked') && repeatValue === REPEAT_OPTIONS.NONE) {
        disabled = true;
    }
    $(SELECTORS.START_TIME).prop('disabled', disabled);
    $(SELECTORS.DURATION).prop('disabled', disabled);
};

/**
 * Toggle the text based on repeat type.
 * To show either Days, Weeks or Months
 *
 * @param {number} repeatValue The repeat option.
 */
const toggleRepeatIntervalText = (repeatValue) => {
    $(SELECTORS.REPEAT_INTERVAL).hide();
    if (repeatValue === REPEAT_OPTIONS.DAILY) {
        $(SELECTORS.REPEAT_INTERVAL_DAILY).show();
    } else if (repeatValue === REPEAT_OPTIONS.WEEKLY) {
        $(SELECTORS.REPEAT_INTERVAL_WEEKLY).show();
    } else if (repeatValue === REPEAT_OPTIONS.MONTHLY) {
        $(SELECTORS.REPEAT_INTERVAL_MONTHLY).show();
    }
};

/**
 * Limit the options shown in the drop-down based on repeat type selected.
 * Max value for daily meeting is 90.
 * Max value for weekly meeting is 12.
 * Max value for monthly meeting is 3.
 *
 * @param {number} repeatValue The repeat option.
 */
const limitRepeatValues = (repeatValue) => {
    let maxOptions;

    if (repeatValue === REPEAT_OPTIONS.WEEKLY) {
        maxOptions = REPEAT_MAX_OPTIONS.WEEKLY;
    } else if (repeatValue === REPEAT_OPTIONS.MONTHLY) {
        maxOptions = REPEAT_MAX_OPTIONS.MONTHLY;
    } else {
        maxOptions = REPEAT_MAX_OPTIONS.DAILY;
    }

    // Restrict options if weekly or monthly option selected.
    $(SELECTORS.REPEAT_INTERVAL_OPTIONS).each((index, option) => {
        if (option.value > maxOptions) {
            $(option).hide();
        } else {
            $(option).show();
        }
    });
};

/**
 * Tabs component.
 * @param {object} tabsColumn
 * @param {object} tabsContentColumn
 * @param {int}    initialTabsCount
 * @param {object} emptyAlert
 */
const TabsComponent = class {
    constructor(tabsColumn, tabsContentColumn, initialTabsCount, emptyAlert) {
        this.tabsColumn = tabsColumn;
        this.tabsContentColumn = tabsContentColumn;
        this.emptyAlert = emptyAlert;
        this.countTabs = initialTabsCount;
    }

    /**
     * Build tab
     * @param {object} item
     * @returns {object} tab element
     */
    buildTab(item) {
        const tab = item.tab.element;
        const tabLink = $(".nav-link", tab);

        // Setting tab id.
        tab.attr('id', 'tab-' + this.countTabs);

        // Setting tab name.
        $(".tab-name", tabLink).text(item.tab.name);

        // Setting tab href.
        tabLink.attr('href', '#link' + this.countTabs);

        // Activating tab
        $("li a", this.tabsColumn).removeClass('active');
        tabLink.addClass('active');

        return tab;
    }

    /**
     * Build tab content.
     * @param {object} item
     * @returns {object} content of tab element
     */
    buildTabContent(item) {
        const tabContent = item.tabContent.element;

        // Setting tabContent id.
        tabContent.attr('id', 'link' + this.countTabs);

        // Activating tabContent.
        $(".tab-pane", this.tabsContentColumn).removeClass('active');
        tabContent.addClass('active');

        return tabContent;
    }


    /**
     * Add tab
     * @param {object} item
     * @returns {object} tab element
     */
    addTab(item) {
        const tab = this.buildTab(item);
        const tabContent = this.buildTabContent(item);

        this.emptyAlert.addClass('hidden');
        $("ul", this.tabsColumn).append(tab);
        $(".tab-content", this.tabsContentColumn).append(tabContent);

        return {"element": tab, "content": tabContent};
    }

    /**
     * Delete tab
     * @param {object} item
     */
    deleteTab(item) {
        const tab = item;
        const tabContent = $($('a', tab).attr('href'));

        tab.remove();
        tabContent.remove();

        const countTabs = $("li", this.tabsColumn).length;
        if (!countTabs) {
            this.emptyAlert.removeClass('hidden');
        } else {
            const countActiveTabs = $("li a.active", this.tabsColumn).length;
            if (!countActiveTabs) {
                $("li:first-child a", this.tabsColumn).trigger('click');
            }
        }
    }
};

/**
 * Breakout rooms editor.
 */
const BreakoutroomsEditor = class {
    // Initialize.
    constructor() {
        this.roomsListColumn = $("#mod-zoom-meeting-rooms-list");
        this.roomsList = $("ul", this.roomsListColumn);
        this.addBtn = $("#add-room", this.roomsListColumn);
        this.emptyAlert = $(".empty-alert", this.roomsListColumn);
        this.deleteBtn = $(".delete-room", this.roomsListColumn);
        this.roomsDataColumn = $("#mod-zoom-meeting-rooms-data");
        this.roomItemToClone = $('#rooms-list-item').html();
        this.roomItemDataToClone = $('#rooms-list-item-data').html();
        this.initialRoomsCount = parseInt(this.roomsListColumn.attr('data-initial-rooms-count'));
        this.tabsComponent = new TabsComponent(this.roomsListColumn, this.roomsDataColumn, this.initialRoomsCount, this.emptyAlert);

        const stringkeys = [{key: 'room', component: 'zoom'}];
        getStrings(stringkeys).catch(Notification.exception);

        const countRooms = $("li", this.roomsListColumn).length;
        if (countRooms < 1) {
            this.emptyAlert.removeClass('hidden');
        }

        const thisObject = this;

        // Adding addroom button click event.
        thisObject.addBtn.on('click', async() => {
            thisObject.tabsComponent.countTabs++;

            const roomString = await getString('room', 'zoom');
            const newRoomName = roomString + ' ' + thisObject.tabsComponent.countTabs;
            const newRoomElement = $(thisObject.roomItemToClone);
            const newRoomDataElement = $(thisObject.roomItemDataToClone);
            const newRoomIndex = thisObject.tabsComponent.countTabs;

            // Setting new room name.
            const roomNameInputId = 'room-name-' + newRoomIndex;
            const roomNameInput = $("input[type=text]", newRoomDataElement);
            roomNameInput.prev().attr('for', roomNameInputId);
            roomNameInput.attr('id', roomNameInputId);
            roomNameInput.attr('name', roomNameInputId);
            roomNameInput.val(newRoomName);
            roomNameInput.next().attr('name', 'rooms[' + newRoomIndex + ']');
            roomNameInput.next().val(newRoomName);

            // Setting new room participants select id/name.
            const roomParticipantsSelectId = 'participants-' + newRoomIndex;
            const roomParticipantsSelect = $(".room-participants", newRoomDataElement);
            roomParticipantsSelect.attr('id', roomParticipantsSelectId);
            roomParticipantsSelect.attr('name', 'roomsparticipants[' + newRoomIndex + '][]');

            // Setting new room participant groups select id/name.
            const roomGroupsSelectId = 'groups-' + newRoomIndex;
            const roomGroupsSelect = $(".room-groups", newRoomDataElement);
            roomGroupsSelect.attr('id', roomGroupsSelectId);
            roomGroupsSelect.attr('name', 'roomsgroups[' + newRoomIndex + '][]');

            // Add new room tab
            const newRoom = {
                "tab": {
                    "name": newRoomName,
                    "element": newRoomElement
                },
                "tabContent": {
                    "element": newRoomDataElement
                }
            };

            const addedTab = thisObject.tabsComponent.addTab(newRoom);

            // Adding new room tab delete button click event.
            $("li:last .delete-room", thisObject.roomsList).on('click', (event) => {
                const item = $(event.target).closest('li');
                thisObject.tabsComponent.deleteTab(item);
            });

            // Adding new room change name event.
            $("input[type=text]", addedTab.content).on("change keyup paste", (event) => {
                const newHiddenValue = event.target.value;
                $(event.target).next().val(newHiddenValue);
                $(".tab-name", addedTab.element).text(newHiddenValue);
            });

            // Convert select dropdowns to autocomplete component.
            thisObject.buildAutocompleteComponent(roomParticipantsSelectId, 'addparticipant');
            thisObject.buildAutocompleteComponent(roomGroupsSelectId, 'addparticipantgroup');
        });

        // Adding deleteroom button click event.
        thisObject.deleteBtn.on('click', (event) => {
            const item = $(event.target).closest('li');
            thisObject.tabsComponent.deleteTab(item);
        });

        // Change room name event.
        $("li", thisObject.roomsListColumn).each((index, item) => {
            const tabIdArr = $(item).attr('id').split('-');
            const tabIndex = tabIdArr[1];
            $('input[name="room-name-' + tabIndex + '"]', thisObject.roomsDataColumn).on("change keyup paste", (event) => {
                const newHiddenValue = event.target.value;
                $(event.target).next().val(newHiddenValue);

                $("#tab-" + tabIndex + " .tab-name").text(newHiddenValue);
            });
        });

        // Build autocomplete components.
        $(".room-participants", thisObject.roomsDataColumn).each((index, item) => {
            const itemId = $(item).attr('id');
            thisObject.buildAutocompleteComponent(itemId, 'addparticipant');
        });

        $(".room-groups", thisObject.roomsDataColumn).each((index, item) => {
            const itemId = $(item).attr('id');
            thisObject.buildAutocompleteComponent(itemId, 'addparticipantgroup');
        });
    }

    /**
     * Build autocomplete component.
     * @param {string} id
     * @param {string} placeholder
     */
    buildAutocompleteComponent(id, placeholder) {
        const stringkeys = [{key: placeholder, component: 'zoom'}, {key: 'selectionarea', component: 'zoom'}];
        getStrings(stringkeys).then((langstrings) => {
            const placeholderString = langstrings[0];
            const noSelectionString = langstrings[1];

            AutoComplete.enhance('#' + id, false, '', placeholderString, false, true, noSelectionString, true);
            return;
        }).catch(Notification.exception);
    }
};
