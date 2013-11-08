/**
 * @license
 * Copyright (C) 2012-2013 KO GmbH <copyright@kogmbh.com>
 *
 * @licstart
 * The JavaScript code in this page is free software: you can redistribute it
 * and/or modify it under the terms of the GNU Affero General Public License
 * (GNU AGPL) as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.  The code is distributed
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU AGPL for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this code.  If not, see <http://www.gnu.org/licenses/>.
 *
 * As additional permission under GNU AGPL version 3 section 7, you
 * may distribute non-source (e.g., minimized or compacted) forms of
 * that code without the copy of the GNU GPL normally required by
 * section 4, provided you include this license notice and a URL
 * through which recipients can access the Corresponding Source.
 *
 * As a special exception to the AGPL, any HTML file which merely makes function
 * calls to this code, and for that purpose includes it by reference shall be
 * deemed a separate work for copyright law purposes. In addition, the copyright
 * holders of this code give you permission to combine this code with free
 * software libraries that are released under the GNU LGPL. You may copy and
 * distribute such a system following the terms of the GNU AGPL for this code
 * and the LGPL for the libraries. If you modify this code, you may extend this
 * exception to your version of the code, but you are not obligated to do so.
 * If you do not wish to do so, delete this exception statement from your
 * version.
 *
 * This license applies to this entire compilation.
 * @licend
 * @source: http://www.webodf.org/
 * @source: https://github.com/kogmbh/WebODF/
 */

/*global runtime, ops*/

/**
 * @constructor
 * @implements ops.Operation
 */
ops.OpMoveCursor = function OpMoveCursor() {
    "use strict";

    var memberid, timestamp, position, length, /**@type {!string}*/selectionType;

    this.init = function (data) {
        memberid = data.memberid;
        timestamp = data.timestamp;
        position = data.position;
        length = data.length || 0;
        selectionType = data.selectionType || ops.OdtCursor.RangeSelection;
    };

    // TODO: instead of calling odtDocument.getCursorPosition(...) and calculating the difference
    // get the position directly and reinsert the cursor there
    this.execute = function (odtDocument) {
        var cursor = odtDocument.getCursor(memberid),
            oldPosition = odtDocument.getCursorPosition(memberid),
            positionFilter = odtDocument.getPositionFilter(),
            number = position - oldPosition,
            stepsToSelectionStart,
            stepsToSelectionEnd,
            stepCounter;

        if (!cursor) {
            return false;
        }

        stepCounter = cursor.getStepCounter();
        stepsToSelectionStart = stepCounter.countSteps(number, positionFilter);
        cursor.move(stepsToSelectionStart);
        if (length) {
            // if the length is non-zero (either positive or negative), this indicates that a range of nodes is
            // being selected.
            stepsToSelectionEnd = stepCounter.countSteps(length, positionFilter);
            cursor.move(stepsToSelectionEnd, true);
        }

        cursor.setSelectionType(selectionType);
        odtDocument.emit(ops.OdtDocument.signalCursorMoved, cursor);
        return true;
    };

    this.spec = function () {
        return {
            optype: "MoveCursor",
            memberid: memberid,
            timestamp: timestamp,
            position: position,
            length: length,
            selectionType: selectionType
        };
    };
};
