/**
 * Copyright (C) 2012 KO GmbH <jos.van.den.oever@kogmbh.com>
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
/*global Node, NodeFilter, xmldom, runtime*/
/*jslint sub: true, emptyblock: true*/
if (typeof Object.create !== 'function') {
    Object['create'] = function (o) {
        "use strict";
        /**
         * @constructor
         */
        var F = function () {};
        F.prototype = o;
        return new F();
    };
}
/*jslint emptyblock: false*/

/**
 * Partial implementation of LSSerializer
 * @constructor
 */
xmldom.LSSerializer = function LSSerializer() {
    "use strict";
    var /**@const@type{!LSSerializer}*/ self = this;

    /**
     * @constructor
     * @param {!Object.<string,string>} nsmap
     */
    function Namespaces(nsmap) {
        function invertMap(map) {
            var m = {}, i;
            for (i in map) {
                if (map.hasOwnProperty(i)) {
                    m[map[i]] = i;
                }
            }
            return m;
        }
        var current = nsmap || {},
            currentrev = invertMap(nsmap),
            levels = [ current ],
            levelsrev = [ currentrev ],
            level = 0;
        this.push = function () {
            level += 1;
            current = levels[level] = Object.create(current);
            currentrev = levelsrev[level] = Object.create(currentrev);
        };
        this.pop = function () {
            levels[level] = undefined;
            levelsrev[level] = undefined;
            level -= 1;
            current = levels[level];
            currentrev = levelsrev[level];
        };
        /**
         * @return {!Object.<string,string>} nsmap
         */
        this.getLocalNamespaceDefinitions = function () {
            return currentrev;
        };
        /**
         * @param {!Node} node
         * @return {!string}
         */
        this.getQName = function (node) {
            var ns = node.namespaceURI, i = 0, p;
            if (!ns) {
                return node.localName;
            }
            p = currentrev[ns];
            if (p) {
                return p + ":" + node.localName;
            }
            do {
                if (p || !node.prefix) {
                    p = "ns" + i;
                    i += 1;
                } else {
                    p = node.prefix;
                }
                if (current[p] === ns) {
                    break;
                }
                if (!current[p]) {
                    current[p] = ns;
                    currentrev[ns] = p;
                    break;
                }
                p = null;
            } while (p === null);
            return p + ":" + node.localName;
        };
    }
    /**
     * Escape characters within document content
     * Follows basic guidelines specified at http://xerces.apache.org/xerces2-j/javadocs/api/org/w3c/dom/ls/LSSerializer.html
     * @param {string} value
     * @returns {string}
     */
    function escapeContent(value) {
        return value.replace(/&/g,"&amp;")
            .replace(/</g,"&lt;")
            .replace(/>/g,"&gt;")
            .replace(/'/g,"&apos;")
            .replace(/"/g,"&quot;");
    }
    /**
     * @param {!string} qname
     * @param {!Attr} attr
     * @return {!string}
     */
    function serializeAttribute(qname, attr) {
        var escapedValue = typeof attr.value === 'string' ? escapeContent(attr.value) : attr.value,
            /**@type{!string}*/ s = qname + "=\"" + escapedValue + "\"";
        return s;
    }
    /**
     * @param {!Namespaces} ns
     * @param {!string} qname
     * @param {!Node} element
     * @return {!string}
     */
    function startElement(ns, qname, element) {
        var /**@type{!string}*/ s = "",
            /**@const@type{!NamedNodeMap}*/ atts = element.attributes,
            /**@const@type{!number}*/ length,
            /**@type{!number}*/ i,
            /**@type{!Attr}*/ attr,
            /**@type{!string}*/ attstr = "",
            /**@type{!number}*/ accept,
            /**@type{!string}*/ prefix,
            nsmap;
        s += "<" + qname;
        length = atts.length;
        for (i = 0; i < length; i += 1) {
            attr = /**@type{!Attr}*/(atts.item(i));
            if (attr.namespaceURI !== "http://www.w3.org/2000/xmlns/") {
                accept = (self.filter) ? self.filter.acceptNode(attr) : NodeFilter.FILTER_ACCEPT;
                if (accept === NodeFilter.FILTER_ACCEPT) {
                    attstr += " " + serializeAttribute(ns.getQName(attr),
                        attr);
                }
            }
        }
        nsmap = ns.getLocalNamespaceDefinitions();
        for (i in nsmap) {
            if (nsmap.hasOwnProperty(i)) {
                prefix = nsmap[i];
                if (!prefix) {
                    s += " xmlns=\"" + i + "\"";
                } else if (prefix !== "xmlns") {
                    s += " xmlns:" + nsmap[i] + "=\"" + i + "\"";
                }
            }
        }
        s += attstr + ">";
        return s;
    }
    /**
     * @param {!Namespaces} ns
     * @param {!Node} node
     * @return {!string}
     */
    function serializeNode(ns, node) {
        var /**@type{!string}*/ s = "",
            /**@const@type{!number}*/ accept
                = (self.filter) ? self.filter.acceptNode(node) : NodeFilter.FILTER_ACCEPT,
            /**@type{Node}*/child,
            /**@const@type{string}*/ qname;
        if (accept === NodeFilter.FILTER_ACCEPT && node.nodeType === Node.ELEMENT_NODE) {
            ns.push();
            qname = ns.getQName(node);
            s += startElement(ns, qname, node);
        }
        if (accept === NodeFilter.FILTER_ACCEPT || accept === NodeFilter.FILTER_SKIP) {
            child = node.firstChild;
            while (child) {
                s += serializeNode(ns, child);
                child = child.nextSibling;
            }
            if (node.nodeValue) {
                s += escapeContent(node.nodeValue);
            }
        }
        if (qname) {
            s += "</" + qname + ">";
            ns.pop();
        }
        return s;
    }
    /**
     * @type {xmldom.LSSerializerFilter}
     */
    this.filter = null;
    /**
     * @param {?Node} node
     * @param {!Object.<string,string>} nsmap
     * @return {!string}
     */
    this.writeToString = function (node, nsmap) {
        if (!node) {
            return "";
        }
        var ns = new Namespaces(nsmap);
        return serializeNode(ns, node);
    };
};
