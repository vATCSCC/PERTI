#!/usr/bin/env node
const puppeteer = require('puppeteer');

async function scrapeStatsim(airports, fromDate, toDate) {
    const fromISO = fromDate.replace(' ', 'T');
    const toISO = toDate.replace(' ', 'T');
    const url = `https://statsim.net/events/custom/${encodeURIComponent(fromISO)}/${encodeURIComponent(toISO)}/${airports}`;

    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            executablePath: '/usr/bin/chromium',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        });

        const page = await browser.newPage();
        await page.setViewport({ width: 1920, height: 1080 });
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });

        const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
        await delay(5000);

        const pageData = await page.evaluate(() => {
            const results = {
                airports: [],
                totalMovements: 0,
                url: window.location.href,
                scrapedAt: new Date().toISOString(),
            };

            const pageText = document.body.innerText;

            const totalMatch = pageText.match(/Total movements\s+(\d+)/i);
            if (totalMatch) {results.totalMovements = parseInt(totalMatch[1]);}

            const airportPattern = /([A-Za-z\s]+(?:Airport|Sunport|Field|International))\s*\(([A-Z]{4})\)/g;
            const depArrPattern = /Departures:\s*(\d+)\s*Arrivals:\s*(\d+)/g;

            const airportMatches = [];
            let match;
            while ((match = airportPattern.exec(pageText)) !== null) {
                const name = match[1].replace(/[\n\r]/g, ' ').replace(/\s+/g, ' ').trim().replace(/^[A-Z]{2}\s+/, '');
                airportMatches.push({ name: name + ' (' + match[2] + ')', icao: match[2], position: match.index });
            }

            const depArrMatches = [];
            while ((match = depArrPattern.exec(pageText)) !== null) {
                depArrMatches.push({ departures: parseInt(match[1]), arrivals: parseInt(match[2]), position: match.index });
            }

            // Extract hourly data - format: {x:timestamp,y:count}
            const chartData = {};
            document.querySelectorAll('script:not([src])').forEach(script => {
                const content = script.textContent;
                const dataPattern = /(depart|arrive)Data([A-Z]{4})\s*=\s*\[([\s\S]*?)\];/g;
                let dataMatch;
                while ((dataMatch = dataPattern.exec(content)) !== null) {
                    const type = dataMatch[1], icao = dataMatch[2], dataStr = dataMatch[3];
                    if (!chartData[icao]) {chartData[icao] = { departures: [], arrivals: [] };}

                    const pointPattern = /\{x:\s*(\d+)\s*,\s*y:\s*(\d+)\}/g;
                    let pointMatch;
                    while ((pointMatch = pointPattern.exec(dataStr)) !== null) {
                        const timestamp = parseInt(pointMatch[1]), count = parseInt(pointMatch[2]);
                        chartData[icao][type === 'depart' ? 'departures' : 'arrivals'].push({ timestamp, count });
                    }
                }
            });

            // Build results
            airportMatches.forEach((airport, idx) => {
                const nextAirportPos = airportMatches[idx + 1]?.position || Infinity;
                const depArr = depArrMatches.find(da => da.position > airport.position && da.position < nextAirportPos);
                const hourlyInfo = chartData[airport.icao] || { departures: [], arrivals: [] };

                // Sort by timestamp and build hourly array
                const allTimestamps = new Set([
                    ...hourlyInfo.departures.map(d => d.timestamp),
                    ...hourlyInfo.arrivals.map(d => d.timestamp),
                ]);

                const hourly = Array.from(allTimestamps).sort((a,b) => a - b).map(ts => {
                    const date = new Date(ts);
                    const dep = hourlyInfo.departures.find(d => d.timestamp === ts);
                    const arr = hourlyInfo.arrivals.find(d => d.timestamp === ts);
                    return {
                        timestamp: ts,
                        time: date.getUTCHours().toString().padStart(2, '0') + ':00',
                        date: date.toISOString().split('T')[0],
                        departures: dep?.count || 0,
                        arrivals: arr?.count || 0,
                    };
                });

                results.airports.push({
                    icao: airport.icao,
                    name: airport.name,
                    departures: depArr?.departures || 0,
                    arrivals: depArr?.arrivals || 0,
                    hourly,
                });
            });

            results.totals = {
                departures: results.airports.reduce((sum, a) => sum + a.departures, 0),
                arrivals: results.airports.reduce((sum, a) => sum + a.arrivals, 0),
            };

            return results;
        });

        pageData.requestedAirports = airports.split(',').map(a => a.trim().toUpperCase());
        pageData.timeRange = { from: fromDate, to: toDate };
        pageData.statsim_url = url;
        pageData.success = true;
        return pageData;
    } catch (error) {
        return { error: true, message: error.message, url };
    } finally {
        if (browser) {await browser.close();}
    }
}

const args = process.argv.slice(2);
if (args.length < 3) {
    console.error(JSON.stringify({ error: true, message: 'Usage: node statsim_scraper.js <airports> <from> <to>' }));
    process.exit(1);
}
scrapeStatsim(args[0], args[1], args[2]).then(data => { console.log(JSON.stringify(data, null, 2)); process.exit(0); }).catch(err => { console.error(JSON.stringify({ error: true, message: err.message })); process.exit(1); });
