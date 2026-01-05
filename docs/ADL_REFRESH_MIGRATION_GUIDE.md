# ADL Refresh Pattern Migration Guide

This guide documents the double-buffering pattern applied across PERTI to prevent
UI "flashing" or data gaps during periodic refreshes.

## Summary of Changes Applied

The following files have been updated to use buffered refresh patterns:

### Core ADL Files
- **nod.js** - `loadTraffic()`, `loadTMIData()`, `loadAdvisories()`, `loadJATOCData()`, `updateStats()`
- **tmi.js** - `refreshAdl()` - Now keeps previous data on error/empty response
- **route-maplibre.js** - `fetchFlights()` - Buffered update pattern
- **route.js** - `fetchFlights()` - Buffered update pattern  
- **route_bu.js** - `fetchFlights()` - Buffered update pattern

### Other Data Files
- **reroute.js** - `refreshCompliance()` - Keeps previous flight data on error
- **jatoc.js** - `loadIncidents()` - Keeps previous incident data on error
- **public-routes.js** - `fetchRoutes()` - Keeps previous route data on error

## The Pattern Applied

Each fetch function was modified to:

1. **Store previous data before fetch**
```javascript
var previousFlights = state.flights ? state.flights.slice() : [];
```

2. **Only update if we got valid data OR had no prior data**
```javascript
if (newFlights.length > 0 || previousFlights.length === 0) {
    state.flights = newFlights;
} else {
    console.log('Empty response, keeping previous data');
}
```

3. **Keep old data on error (don't set to null/empty)**
```javascript
.catch(function(err) {
    console.error('Fetch failed:', err);
    // DON'T do: state.flights = null;  
    // Keep previous data available
});
```

## Key Principles

1. **Never clear before you have replacement data**
   - Don't set `innerHTML = ''` before building new content
   - Don't set state to `null` during fetch
   - Don't clear map sources before new data arrives

2. **Build complete content before swapping**
   - Accumulate all HTML in a string
   - Prepare all map features in an array
   - Then do a single DOM/source update

3. **Fail gracefully**
   - On error, keep displaying old data
   - Log errors but don't break the UI

## Optional: Centralized ADL Service

For future use or more complex scenarios, the `adl-service.js` module provides:
- Centralized data fetching with subscriber pattern
- Built-in buffering
- Rate limiting
- Multiple page support

Include it when needed:
```html
<script src="/assets/js/adl-service.js"></script>
```

## CSS Classes Available

Both `adl-service.js` and `adl-refresh-utils.js` inject these CSS classes:

- `.adl-refreshing` - Applied during fetch, shows subtle pulse animation
- `.adl-stale-data` - Applied when data might be outdated

```css
/* Customize if needed */
.adl-refreshing {
    animation: adl-pulse 1s ease-in-out infinite;
}
.adl-stale-data {
    opacity: 0.7;
}
```
