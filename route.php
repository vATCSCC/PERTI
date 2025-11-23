<?php

    include("sessions/handler.php");
    // Session Start (S)
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
        ob_start();
    }
    // Session Start (E)
    
    include("load/config.php");
    include("load/connect.php");

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {

            // Getting CID Value
            $cid = strip_tags($_SESSION['VATSIM_CID']);
    
            $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    
            if ($p_check) {
                $perm = true;
            }
    
        }
    } else {
        $perm = true;
        $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
    }

?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        include("load/header.php");
    ?>

    <!-- Leaflet --> 
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/leaflet-omnivore/0.3.4/leaflet-omnivore.min.js'></script>
    <script src='assets/js/leaflet.textpath.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet.geodesic"></script>

    <style>
        .Incon {
            background: none;
            color: #fbff00;
            border: none;
            font-size: 1rem;
            font-family: Inconsolata;
        }
    </style>
</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">
        </div>       
    </section>


    <div class="container-fluid mt-5">
        <center>
            <div class="row mb-5">
                <div class="col-4">
                    <h4>Plot Routes</h4>
                    <p>To utilize <b>multiple</b> routes, space each route individually by line (press ENTER to create a new line).<br>To color-code routes add a semi-colon (;) to the END of the route followed by either a hex (ex. #fff) or the name of a standard color (ex. blue).</p>
                    <textarea class="form-control" name="routeSearch" id="routeSearch" rows="20"></textarea>

                    <br>
                    <button class="btn btn-success" id="plot_r"><i class="fas fa-pencil"></i> Plot</button>
                    <button class="btn btn-info" id="plot_c"><i class="far fa-copy"></i> Copy</button>

                    <hr>

                    <button class="btn btn-sm btn-primary" id="export_ls"><i class="fas fa-file-export"></i> Export LS</button>
                    <button class="btn btn-sm btn-primary" id="export_mp"><i class="fas fa-file-export"></i> Export MP</button>
                </div>
                <div class="col-8 text-left">
                    Filter Airways:
                    <div class="text-left input-group w-50">
                        <input type="text" name="filter" id="filter" class="form-control" placeholder="Separate by Space">
                        <div class="input-group-append">
                            <button class="btn btn-outline-danger" type="button" id="filter_c"><i class="fas fa-times"></i></button>
                        </div>
                    </div>

                    <hr>

                    <div id="placeholder"></div>
                    <div id="graphic" style="height: 750px;"></div>
                </div>
            </div>

        </center>
    </div>

    
<?php include('load/footer.php'); ?>

<!-- Graphical Map Leaflet.js Generation -->
<script>
  $(document).ready(function() {

    let cached_geojson_linestring = []
    let cached_geojson_multipoint = []

    let points = {};

    $.ajax({
        type: 'GET',
        url: 'assets/data/points.csv',
        async: false
    }).done(function(data) {
        const lines = data.split('\n');

        for (const line of lines) {
            const [id, lat, lon] = line.split(',');

            if (id in points) {
                points[id].push([id, +lat, +lon]);

                continue;
            }

            points[id] = [[id, +lat, +lon]];
        }
    });

    /**
     * convert degrees to radians
     *
     * @function degreesToRadians
     * @param degrees {number}
     * @return {number}
     */
    const degreesToRadians = (degrees) => {
        return (degrees / 360) * (Math.PI * 2);
    };

    /**
     * Calculate the distance between two lat/long coordinates in km
     *
     * This is a javascript implementation of the Haversine Formula
     *
     * for more information on the math see:
     * - http://www.movable-type.co.uk/scripts/latlong.html
     * - http://stackoverflow.com/questions/27928/calculate-distance-between-two-latitude-longitude-points-haversine-formula
     *
     * @function distanceToPoint
     * @param startLatitude {number}
     * @param startLongitude {number}
     * @param endLatitude {number}
     * @param endLongitude {number}
     * return {number}
     */
    const distanceToPoint = (startLatitude, startLongitude, endLatitude, endLongitude) => {
        // TODO: add to global constants
        const EARTH_RADIUS_KM = 6371;
        const startLatitudeRadians = degreesToRadians(startLatitude);
        const endLatitudeRadians = degreesToRadians(endLatitude);
        const distanceLatitude = degreesToRadians(startLatitude - endLatitude);
        const distanceLongitude = degreesToRadians(startLongitude - endLongitude);

        // the square of half the chord length between points
        const a = Math.pow(Math.sin(distanceLatitude / 2), 2) +
            (Math.cos(startLatitudeRadians) * Math.cos(endLatitudeRadians) * Math.pow(Math.sin(distanceLongitude / 2), 2));


        const angularDistanceInRadians = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return angularDistanceInRadians * EARTH_RADIUS_KM;
    };

    function confirmReasonableDistance(pointData, previousPointData, nextPointData) {
        let maxReasonableDistance = 4000; // km

        if (previousPointData && nextPointData) {
            const distanceFromPreviousToNextPoint = distanceToPoint(previousPointData[1], previousPointData[2], nextPointData[1], nextPointData[2]);
            maxReasonableDistance = Math.min(maxReasonableDistance, distanceFromPreviousToNextPoint * 1.5);
        }
        
        if (previousPointData) {
            const distanceToPreviousPoint = distanceToPoint(pointData[1], pointData[2], previousPointData[1], previousPointData[2]);

            if (distanceToPreviousPoint > maxReasonableDistance) {
                return undefined;
            }
        }
        
        if (nextPointData) {
            const distanceToNextPoint = distanceToPoint(pointData[1], pointData[2], nextPointData[1], nextPointData[2]);

            if (distanceToNextPoint > maxReasonableDistance) {
                return undefined;
            }
        }

        return pointData;
    }

    function getPointByName(pointName, previousPointData, nextPointData) {
        if (!(pointName in points)) {
            return undefined;
        }

        const pointList = points[pointName];

        if (pointList.length === 1) {
            const selectedPoint = pointList[0];

            return confirmReasonableDistance(selectedPoint, previousPointData, nextPointData);
        }

        // There's multiple results for the specified fix name. And without any
        // context fixes, the best we can do is guess which one you want! So we'll
        // just arbitrarily choose the first one.
        if (!previousPointData && !nextPointData) {
            const selectedPoint = pointList[0];

            return confirmReasonableDistance(selectedPoint, previousPointData, nextPointData);
        }

        let centerPosition = previousPointData;

        if (!previousPointData) {
            centerPosition = nextPointData;
        }

        if (previousPointData && nextPointData) {
            centerPosition = [
                centerPosition[0],
                (previousPointData[1] + nextPointData[1]) / 2,
                (previousPointData[2] + nextPointData[2]) / 2,
            ];
        }

        // shows how far each possible fix is from the area we'd expect it to be
        const errorMap = pointList.map(p => {
            const totalError = Math.abs(centerPosition[1] - p[1]) + Math.abs(centerPosition[2] - p[2]);

            return totalError;
        });

        const indexOfClosestFix = errorMap.indexOf(Math.min(...errorMap));
        const selectedPoint = pointList[indexOfClosestFix];

        return confirmReasonableDistance(selectedPoint, previousPointData, nextPointData);
    }

    function countOccurrencesOfPointName(pointName) {
        if (!(pointName in points)) {
            return 0;
        }

        return points[pointName].length;
    }

    function drawMapCall() {
        // Reset Container (S)
        var container = L.DomUtil.get('graphic');

        if (container != null) {
            container._leaflet_id = '';
        }

        $('#graphic').remove()
        $('<div id="graphic" style="height: 750px;"></div>').insertAfter('#placeholder')

        drawMap(overlays);
    }

  function drawMap(overlays) {

    if (graphic_map) {
        graphic_map.remove()
    }


    // Map Configuration (START)
    var graphic_map = L.map('graphic', { zoomControl: true, scrollWheelZoom: true, dragging: true, doubleClickZoom: true, zoomSnap: 0.25}).setView([39.5, -98.35], 4);

    var CartoDB_Dark = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; <a href="https://carto.com/attributions">CARTO</a> | &copy; <a href="https://mesonet.agron.iastate.edu/">Iowa State University</a> | &copy; <a href="http://web.ics.purdue.edu/~snandaku/">Srinath Nandakumar</a>',
      subdomains: 'abcd'
    }).addTo(graphic_map);

    function sigmetBind(feature, layer) {
      if (feature.properties && feature.properties.data) {
        layer.bindPopup(
            "<b>Center:</b> " + feature.properties.icaoId + "<br>" +
            "<b>Valid From:</b> " + feature.properties.validTimeFrom + "<br>" +
            "<b>Valid To:</b> " + feature.properties.validTimeTo + "<br>" +
            "<b>Top Altitude:</b> " + feature.properties.altitudeHi1 + "<hr>" +
            "<pre>" + feature.properties.rawAirSigmet + "</pre>"
        , {closeButton: false});

        var sigmetSplit = feature.properties.rawAirSigmet.split('\n')
        var sigmetCodeString = sigmetSplit[2].split(' ');

        layer.bindTooltip(sigmetCodeString[2], {direction: 'center', offset: L.point(0, 14), permanent: true, className: '0 Incon'})
        layer.openTooltip()
      }
    }

    var airport_icon = L.divIcon({
        html: '<span style="font-size: 15px; color: white; -webkit-text-fill-color: #000; -webkit-text-stroke-width: 1px; -webkit-text-stroke-color: white">▲</span>',
        className: '0',
        iconAnchor: [7.5, 12],
    });

    var navaid_icon = L.divIcon({
        html: '<span style="font-size: 15px; color: white; -webkit-text-fill-color: #000; -webkit-text-stroke-width: 1px; -webkit-text-stroke-color: white">■</span>',
        className: '0',
        iconAnchor: [7.5, 12],
    });

    var point_icon = L.divIcon({
        html: '<span style="font-size: 8px; color: white; -webkit-text-fill-color: #000; -webkit-text-stroke-width: 1px; -webkit-text-stroke-color: white;"><i class="fas fa-circle"></i></span>',
        className: '0',
        iconAnchor: [7.5, 7.5],
    });

    var f_airport_icon = L.divIcon({
        html: '<span style="font-size: 15px; color: white; -webkit-text-fill-color: #046fff; -webkit-text-stroke-width: 1px; -webkit-text-stroke-color: white">▲</span>',
        className: '0',
        iconAnchor: [7.5, 12],
    });
    
    var f_navaid_icon = L.divIcon({
        html: '<span style="font-size: 15px; color: white; -webkit-text-fill-color: #046fff; -webkit-text-stroke-width: 1px; -webkit-text-stroke-color: white">■</span>',
        className: '0',
        iconAnchor: [7.5, 12],
    });

    var f_point_icon = L.divIcon({
        html: '<span style="font-size: 8px; color: #000; -webkit-text-fill-color: #046fff; -webkit-text-stroke-width: 1px; -webkit-text-stroke-color: #000;"><i class="fas fa-circle"></i></span>',
        className: '0',
        iconAnchor: [7.5, 7.5],
    });  
    // Map Configuration (END)

    // OVERLAYS (S)

    let high_splits = new L.geoJson(null, {style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0}})
    let low_splits = new L.geoJson(null, {style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0}})
    let tracon = new L.geoJson(null, {style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0}})

    $.ajax({
        type: 'GET',
        url: 'assets/geojson/high.json',
        async: false
    }).done(function(data) {
        $(data.features).each(function(key, data) {
            high_splits.addData(data)
        })
    });

    $.ajax({
        type: 'GET',
        url: 'assets/geojson/low.json',
        async: false
    }).done(function(data) {
        $(data.features).each(function(key, data) {
            low_splits.addData(data)
        })
    });

    $.ajax({
        type: 'GET',
        url: 'assets/geojson/tracon.json',
        async: false
    }).done(function(data) {
        $(data.features).each(function(key, data) {
            tracon.addData(data)
        })
    });

            // SIGMETs (START)
            var sigmets = L.geoJson(null, {
                onEachFeature: sigmetBind,
                style: {
                    "fillColor": "#d8da5b",
                    "color": "#fbff00",
                    "weight": 2,
                    "opacity": 0.5,
                    "fillOpacity": 0.1
                }
            });

            $.ajax({
            type: 'GET',
            url: 'api/data/sigmets',
            async: false
            }).done(function(result) {
                // sigmets.addData(JSON.parse(result))
            });
            //SIGMETs (END)

            // WX Cells (START)
            var cells = L.tileLayer(
                'https://web.ics.purdue.edu/~snandaku/atc/processor.php?x={x}&y={y}&z={z}', {
                    tileSize: 256,
                    opacity: 0.7,
                    ts: function() {
                        return Date.now();
                    }
                })
            // WX Cells (END)


    const awy_points = [];
    var filtered_airways = L.layerGroup().addTo(graphic_map)

    $('#filter').val().toUpperCase().split(' ').forEach(awy => {
        airwaysDraw(awy)
    })

    function airwaysDraw(airwayName) {
        if (airwayName === '') {
            return;
        }

        const airwayData = awys.find(a => a[0] === airwayName);

        if (!airwayData) {
            return;
        }

        const [airwayId, routeString] = airwayData;
        const fixes = routeString.split(' ');
        const pointList = [];
        let previousPointData;

        for (let i = 0; i < fixes.length; i++) {
            const pointName = fixes[i];
            let nextPointData;

            if (i < fixes.length - 1) { // if not yet on last point in airway
                nextPointData = getPointByName(fixes[i + 1], previousPointData, fixes[i]);
            }

            const pointData = getPointByName(pointName, previousPointData, nextPointData);
            
            if (!pointData || pointData.length < 3) {
                console.warn(`Invalid or unreliable fix definition for fix "${pointName}"`);
                
                continue;
            }
            
            const [id, lat, lon] = pointData;
            previousPointData = pointData;

            awy_points.push(pointData);
            pointList.push([lat, lon]);
        }

        if (airwayName.includes('Q') || airwayName.includes('T')) {
            filtered_airways.addLayer(new L.geodesic(pointList, {color: '#7588fd'}).setText('       ' + airwayName + '       ', {center: true, repeat: true, attributes: {fill: '#fff', opacity: 0.6}}))
        } else {
            filtered_airways.addLayer(new L.geodesic(pointList, {color: '#bfbfbf'}).setText('       ' + airwayName + '       ', {center: true, repeat: true, attributes: {fill: '#fff', opacity: 0.6}}))
        }
    }

    awy_points.forEach(point => {
        var des = point[0];
        var lat = point[1];
        var lon = point[2];

        if (lat && lon) {
            if (des.length < 4) {
                // NAVAID
                filtered_airways.addLayer(L.marker([lat, lon], {icon: f_navaid_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}));
            } else if (des.length < 5) {
                // AIRPORT
                filtered_airways.addLayer(L.marker([lat, lon], {icon: f_airport_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}));
            } else {
                filtered_airways.addLayer(L.marker([lat, lon], {icon: f_point_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}));
            }
        }
    })

    // Plot all NAVAIDs
    var navaids = L.layerGroup()

    $.ajax({
        type: 'GET',
        url: 'assets/data/navaids.csv',
        async: false
    }).done(function(data) {
        let split = data.split('\n')

        split.forEach(data => {
            let split = data.split(',')

            if (split[1] && split[2]) {
                navaids.addLayer(L.marker([split[1], split[2]], {icon: navaid_icon, opacity: '0.25'}).bindTooltip(split[0], {className: 'Incon bg-light text-dark', permanent: false, direction: 'center'}))
            }
        })
    });

    // // Plot all fixes (commented out-- this takes an obscenely long time to render. few mins minimum.)
    // var allFixesPlottable = L.layerGroup();

    // $.ajax({
    //     type: 'GET',
    //     url: 'assets/data/points.csv',
    //     async: false
    // }).done(function(data) {
    //     const lines = data.split('\n');

    //     for (const line of lines) {
    //         const [id, lat, lon] = line.split(',');

    //         if (!id || !lat || !lon) {
    //             continue;
    //         }
            
    //         allFixesPlottable.addLayer(L.marker([lat, lon], {icon: point_icon, opacity: '0.25'}).bindTooltip(id, {className: 'Incon bg-light text-dark', permanent: false, direction: 'center'}));
    //     }
    // });

    var overlaysArray = [
        ['high_splits', high_splits],
        ['low_splits', low_splits],
        ['tracon', tracon],
        ['navaids', navaids],
        // ['allFixesPlottable', allFixesPlottable],
        ['sigmets', sigmets],
        ['cells', cells]
    ]

    var layers = {
        "High Splits": high_splits,
        "Low Splits": low_splits,
        "TRACON Boundaries<hr>": tracon,
        "All NAVAIDs<hr>": navaids,
        // "All Fixes<hr>": allFixesPlottable,
        "WX Cells": cells,
        "SIGMETs": sigmets
    };  

    overlaysArray.forEach(o => {
        if (overlays.includes(o[0])) {
            o[1].addTo(graphic_map)
        }
    }) 

    graphic_map.on('overlayadd', function(eventlayer) {
        overlaysArray.forEach(o => {
            if (eventlayer.layer == o[1]) {
                overlayAdd(o[0])
            }
        }) 

        artcc.bringToFront()
    })

    graphic_map.on('overlayremove', function(eventlayer) {
        overlaysArray.forEach(o => {
            if (eventlayer.layer == o[1]) {
                overlayRemove(o[0])
            }
        }) 

        artcc.bringToFront()
    })
    
    L.control.layers(null, layers).addTo(graphic_map);
    // OVERLAYS (E)

    // Route (S)
    function ConvertRoute(route) {
        var new_route_string = route;
        var i = 0;

        var split_route = route.split(' ');

        split_route.forEach(point => {
            if (point === 'J79') debugger;
            if (point !== '') {
                // Airways
                if (awys.findIndex(c => c.includes(point)) != -1) {
                    // Found as an AWY
                    let index = awys.findIndex(c => c.includes(point))

                    let first = awys[index][1].split(' ').findIndex(c => c.includes(split_route[i-1]));
                    let second = awys[index][1].split(' ').findIndex(c => c.includes(split_route[i+1]));

                    // if airway segment does not contain fixes other than the entry and exit fixes, just move on
                    if (Math.abs(first - second) < 2) {
                        return;
                    }

                    if (first < second) {
                        // Front to Back
                        var bs = awys[index][1].split(split_route[i-1] + ' ') // Split off First Surrounding

                        if (bs[1]) {
                            var es = bs[1].split(' ' + split_route[i+1]) // Split off Last Surrounding

                            if (es[0]) {
                                new_route_string = new_route_string.replace(point, es[0])
                            }
                        }
                    } else {
                        // Back to Front
                        var array = awys[index][1].split(' ')
                        let reversed = array.reverse().toString().replaceAll(',', ' ')

                        var bs = reversed.split(split_route[i-1] + ' ') // Split off First Surrounding

                        if (bs[1]) {
                            var es = bs[1].split(' ' + split_route[i+1]) // Split off Last Surrounding

                            if (es[0]) {
                                new_route_string = new_route_string.replace(point, es[0])
                            }
                        }
                    }
                }

            }

            i++;
        })

        return new_route_string;
    }

    var route = $('#routeSearch').val().toUpperCase();
    const route_lat_long_for_indiv = [];
    const linestring = new L.layerGroup();
    const multipoint = {
        type: 'FeatureCollection',
        features: []
    };

    route.split('\n').forEach(rte => {
        if (rte.trim() === '') {
            return;
        }

        // clean up duplicate/trailing spaces
        rte = rte.replace(/\s+/g, ' ').trim();

        var route_lat_long = [];
        let previousPointData;

        // Using Standard Search Query
        const routePoints = ConvertRoute(rte).split(' ');
        for (let i = 0; i < routePoints.length; i++) {
            const pointName = routePoints[i];
            let nextPointData;

            if (i < routePoints.length - 1) { // if not yet on last point in route
                let dataForCurrentFix;

                // only use current fix's info if it's the only fix by that name
                if (countOccurrencesOfPointName(pointName) === 1) { // there is only one match for the current fix
                    dataForCurrentFix = getPointByName(routePoints[i]);
                }

                nextPointData = getPointByName(routePoints[i + 1], previousPointData, dataForCurrentFix);
            }

            if (pointName.length == 6 && /\d/.test(pointName) == true && !pointName.includes(';')) {
                // Is a SID/STAR
                let procedureRootName = pointName.slice(0, -1); // remove the number from the end

                if (!(procedureRootName in points)) {
                    continue;
                }

                const rootPointData = getPointByName(procedureRootName, previousPointData, nextPointData);
                
                if (!rootPointData || rootPointData.length < 3) {
                    console.warn(`Invalid or unreliable fix definition for fix "${procedureRootName}"`);
                    
                    continue;
                }
                
                const [id, lat, lon] = rootPointData;
                previousPointData = rootPointData;

                route_lat_long_for_indiv.push(rootPointData);
                route_lat_long.push([lat, lon]);

                continue;
            }

            if (!(pointName in points)) {
                console.warn(`Can't find fix "${pointName}"!`);

                continue;
            }

            const pointData = getPointByName(pointName, previousPointData, nextPointData);

            if (!pointData || pointData.length < 3) {
                console.warn(`Invalid or unreliable fix definition for fix "${pointName}"`);
                
                continue;
            }
            
            const [id, lat, lon] = pointData;
            previousPointData = pointData;

            route_lat_long_for_indiv.push(pointData);
            route_lat_long.push([lat, lon]);
        }

        // Get User-Defined Color/Hex
        if (rte.includes(';')) {
            let rte_split_for_color = rte.split(';');

            new L.geodesic(route_lat_long, {color: rte_split_for_color[1]}).addTo(graphic_map)    ;            
        } else {
            new L.geodesic(route_lat_long, {color: '#C70039'}).addTo(graphic_map);
        }

        linestring.addLayer(new L.geodesic(route_lat_long));
    });

    route_lat_long_for_indiv.forEach(point => {
        var des = point[0];
        var lat = point[1];
        var lon = point[2];

        if (des.length < 4) { // NAVAID
            L.marker([lat, lon], {icon: navaid_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}).addTo(graphic_map);
            // For GEO JSON
            multipoint['features'].push({type: 'Feature', properties: {name: des}, geometry: {type: 'Point', coordinates: [Number(lon), Number(lat)]}});
        } else if (des.length < 5) { // AIRPORT
            L.marker([lat, lon], {icon: airport_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}).addTo(graphic_map);
            // For GEO JSON
            multipoint['features'].push({type: 'Feature', properties: {name: des}, geometry: {type: 'Point', coordinates: [Number(lon), Number(lat)]}});
        } else { // FIX
            L.marker([lat, lon], {icon: point_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}).addTo(graphic_map);
            // For GEO JSON
            multipoint['features'].push({type: 'Feature', properties: {name: des}, geometry: {type: 'Point', coordinates: [Number(lon), Number(lat)]}});
        }
    })
    // Route (E)

    let artcc = new L.geoJson(null, {style: {"color": "#515151", "weight": 1.5, "opacity": 1, "fillOpacity": 0}}).addTo(graphic_map)
    $.ajax({
        type: 'GET',
        url: 'assets/geojson/artcc.json',
        async: false
    }).done(function(data) {
        $(data.features).each(function(key, data) {
            artcc.addData(data)
        })
    });

    // Store GeoJSON for Export
    cached_geojson_linestring = linestring.toGeoJSON();
    cached_geojson_multipoint = multipoint

  }

    drawMap([]);

    $('#plot_r').on('click', function() {
        drawMapCall();
    });

    $('#plot_c').on('click', function() {
        navigator.clipboard.writeText($('#routeSearch').val());
    })

    $('#filter_c').on('click', function() {
        $('#filter').val('')

        drawMapCall()
    });

    $('#export_ls').on('click', function() {
        let element = document.createElement('a')
        element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(JSON.stringify(cached_geojson_linestring)))
        element.setAttribute('download', 'linestring.json')

        element.style.display = 'none';
        document.body.appendChild(element);

        element.click();

        document.body.removeChild(element);
    })

    $('#export_mp').on('click', function() {
        let element = document.createElement('a')
        element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(JSON.stringify(cached_geojson_multipoint)))
        element.setAttribute('download', 'multipoint.json')

        element.style.display = 'none';
        document.body.appendChild(element);

        element.click();

        document.body.removeChild(element);
    })

    let overlays = [];

    function overlayAdd(overlay) {
        overlays.push(overlay);
    }

    function overlayRemove(overlay) {
        overlays = overlays.filter(i => i !== overlay);
    }

  });
</script>

<script src="assets/js/awys.js"></script>

</html>