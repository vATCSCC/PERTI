/**
 * RAD Event Bus — Simple pub/sub for inter-module communication.
 * Usage: RADEventBus.on('event', callback); RADEventBus.emit('event', data);
 */
window.RADEventBus = (function() {
    var listeners = {};
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
        }
    };
})();
