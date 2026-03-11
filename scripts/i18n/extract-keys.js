#!/usr/bin/env node
/**
 * Extract flat key-value pairs from a nested locale JSON file.
 * Usage: node extract-keys.js <input.json> <output.json>
 */
const fs = require('fs');
const [,, inputFile, outputFile] = process.argv;

if (!inputFile || !outputFile) {
    console.error('Usage: node extract-keys.js <input.json> <output.json>');
    process.exit(1);
}

const base = JSON.parse(fs.readFileSync(inputFile, 'utf8'));
const flat = {};

function flatten(obj, prefix) {
    for (const key of Object.keys(obj)) {
        if (key === '_comment') continue;
        const fullKey = prefix ? `${prefix}.${key}` : key;
        if (typeof obj[key] === 'object' && obj[key] !== null) {
            flatten(obj[key], fullKey);
        } else {
            flat[fullKey] = obj[key];
        }
    }
}

flatten(base, '');
fs.writeFileSync(outputFile, JSON.stringify(flat, null, 2) + '\n', 'utf8');
console.log(`Extracted ${Object.keys(flat).length} keys to ${outputFile}`);
