#!/usr/bin/env node
/**
 * Build a nested locale JSON from en-US.json structure + flat translation map.
 * Usage: node build-locale.js <base.json> <flat-translations.json> <output.json>
 *
 * The flat-translations.json should be { "section.key.subkey": "translated value", ... }
 * Any keys not found in the translation map will use the base (en-US) value.
 */
const fs = require('fs');
const [,, baseFile, translationFile, outputFile] = process.argv;

if (!baseFile || !translationFile || !outputFile) {
    console.error('Usage: node build-locale.js <base.json> <flat-translations.json> <output.json>');
    process.exit(1);
}

const base = JSON.parse(fs.readFileSync(baseFile, 'utf8'));
const translations = JSON.parse(fs.readFileSync(translationFile, 'utf8'));

let applied = 0;
let missing = 0;

function applyTranslations(obj, prefix) {
    const result = {};
    for (const key of Object.keys(obj)) {
        if (key === '_comment') continue;
        const fullKey = prefix ? `${prefix}.${key}` : key;
        if (typeof obj[key] === 'object' && obj[key] !== null) {
            result[key] = applyTranslations(obj[key], fullKey);
        } else {
            if (translations[fullKey] !== undefined) {
                result[key] = translations[fullKey];
                applied++;
            } else {
                result[key] = obj[key];
                missing++;
            }
        }
    }
    return result;
}

const output = applyTranslations(base, '');
fs.writeFileSync(outputFile, JSON.stringify(output, null, 2) + '\n', 'utf8');
console.log(`Generated ${outputFile}: ${applied} translated, ${missing} using base`);
