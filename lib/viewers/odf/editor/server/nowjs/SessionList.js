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

/*global define, ops, runtime */

define("webodf/editor/server/nowjs/SessionList", [], function () {
    "use strict";

    return function NowjsSessionList(nowjsServer) {

        var cachedSessionData = {},
            subscribers = [];

        function onSessionData(sessionData) {
            var i,
                isNew = ! cachedSessionData.hasOwnProperty(sessionData.id);

            // cache
            cachedSessionData[sessionData.id] = sessionData;
            runtime.log("get session data for:"+sessionData.title+", is new:"+isNew);

            for (i = 0; i < subscribers.length; i += 1) {
                if (isNew) {
                    subscribers[i].onCreated(sessionData);
                } else {
                    subscribers[i].onUpdated(sessionData);
                }
            }
        }

        function onSessionRemoved(sessionId) {
            var i;

            if (cachedSessionData.hasOwnProperty(sessionId)) {
                delete cachedSessionData[sessionId];

                for (i = 0; i < subscribers.length; i += 1) {
                    subscribers[i].onRemoved(sessionId);
                }
            }
        }

        this.getSessions = function (subscriber) {
            var i,
                sessionList = [];

            if (subscriber) {
                subscribers.push(subscriber);
            }

            for (i in cachedSessionData) {
                if (cachedSessionData.hasOwnProperty(i)) {
                    sessionList.push(cachedSessionData[i]);
                }
            }

            return sessionList;
        };

        this.unsubscribe = function (subscriber) {
            var i;

            for (i=0; i<subscribers.length; i+=1) {
                if (subscribers[i] === subscriber) {
                    break;
                }
            }

            runtime.assert((i < subscribers.length),
                            "tried to unsubscribe when not subscribed.");

            subscribers.splice(i,1);
        };

        this.setUpdatesEnabled = function (enabled) {
            var nowObject = nowjsServer.getNowObject();

            // no change?
            if ((nowObject.onSessionAdded === onSessionData) === enabled) {
                return;
            }

            if (enabled) {
                nowObject.onSessionAdded = onSessionData;
                nowObject.onSessionChanged = onSessionData;
                nowObject.onSessionRemoved = onSessionRemoved;
            } else {
                delete nowObject.onSessionAdded;
                delete nowObject.onSessionChanged;
                delete nowObject.onSessionRemoved;
            }
        };

        function init() {
            var nowObject = nowjsServer.getNowObject();
            nowObject.onSessionAdded = onSessionData;
            nowObject.onSessionChanged = onSessionData;
            nowObject.onSessionRemoved = onSessionRemoved;

            nowObject.getSessionList( function(sessionList) {
                var idx;
            runtime.log("get sessions on init:"+sessionList.length);
                for (idx=0; idx<sessionList.length; idx+=1) {
                    onSessionData(sessionList[idx])
                }
            });
        }

        init();
    };
});
