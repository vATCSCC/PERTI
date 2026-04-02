/**
 * RAD Event Bus — Simple pub/sub for inter-module communication.
 * Usage: RADEventBus.on('event', callback); RADEventBus.emit('event', data);
 */
window.RADEventBus = (function() {
    var listeners = {};

    // Callsign color palette (matches brainstorm mockups)
    var CS_PALETTE = ['#4ECDC4','#FF6B6B','#FFD93D','#9B59B6','#E94560','#00CED1','#FF8C00','#7B68EE'];
    var csColorCache = {};

    /**
     * Deterministic callsign color: hash airline prefix → palette index.
     * Same airline always gets the same color within a session.
     */
    function callsignColor(callsign) {
        if (!callsign) return '#ccc';
        var key = callsign.replace(/[0-9]/g, '');  // strip digits → airline prefix
        if (csColorCache[key]) return csColorCache[key];
        var hash = 0;
        for (var i = 0; i < key.length; i++) {
            hash = ((hash << 5) - hash) + key.charCodeAt(i);
            hash |= 0;
        }
        var color = CS_PALETTE[Math.abs(hash) % CS_PALETTE.length];
        csColorCache[key] = color;
        return color;
    }

    return {
        on: function(event, fn) {
            if (!listeners[event]) listeners[event] = [];
            listeners[event].push(fn);
        },
        off: function(event, fn) {
            if (!listeners[event]) return;
            listeners[event] = listeners[event].filter(function(f) { return f !== fn; });
        },
        emit: function(event, data) {
            if (!listeners[event]) return;
            listeners[event].forEach(function(fn) {
                try { fn(data); } catch(e) { console.error('RADEventBus error on ' + event, e); }
            });
        },
        callsignColor: callsignColor
    };
})();
