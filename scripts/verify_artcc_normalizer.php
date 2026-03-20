<?php
/**
 * Verify ArtccNormalizer works correctly.
 * Run: php scripts/verify_artcc_normalizer.php
 */
require_once __DIR__ . '/../lib/ArtccNormalizer.php';
use PERTI\Lib\ArtccNormalizer;

$tests = [
    ['KZNY', 'ZNY'],
    ['KZFW', 'ZFW'],
    ['KZLA', 'ZLA'],
    ['ZBW', 'ZBW'],
    ['ZNY', 'ZNY'],
    ['CZE', 'CZEG'],
    ['CZU', 'CZUL'],
    ['CZV', 'CZVR'],
    ['CZW', 'CZWG'],
    ['CZY', 'CZYZ'],
    ['CZEG', 'CZEG'],
    ['PAZA', 'ZAN'],
    ['KZAK', 'ZAK'],
    ['PGZU', 'ZUA'],
    ['KJFK', 'KJFK'],
    ['KORD', 'KORD'],
    ['', ''],
    ['  ZNY  ', 'ZNY'],
];

$csv_tests = [
    ['ZNY,KZFW,CZE,UNKN,VARIOUS', 'ZNY,ZFW,CZEG'],
    ['', ''],
    ['UNKN', ''],
    ['ZBW,ZNY', 'ZBW,ZNY'],
];

echo "=== Single code tests ===\n";
$pass = 0; $fail = 0;
foreach ($tests as [$input, $expected]) {
    $result = ArtccNormalizer::normalize($input);
    $ok = $result === $expected;
    if ($ok) { $pass++; } else { $fail++; }
    printf("  %s: normalize('%s') = '%s' (expected '%s')\n",
        $ok ? 'PASS' : '** FAIL **', $input, $result, $expected);
}

echo "\n=== CSV tests ===\n";
foreach ($csv_tests as [$input, $expected]) {
    $result = ArtccNormalizer::normalizeCsv($input);
    $ok = $result === $expected;
    if ($ok) { $pass++; } else { $fail++; }
    printf("  %s: normalizeCsv('%s') = '%s' (expected '%s')\n",
        $ok ? 'PASS' : '** FAIL **', $input, $result, $expected);
}

echo "\n=== Idempotency test ===\n";
foreach (['ZNY', 'CZEG', 'ZAN', 'ZBW'] as $code) {
    $r1 = ArtccNormalizer::normalize($code);
    $r2 = ArtccNormalizer::normalize($r1);
    $ok = $r1 === $r2;
    if ($ok) { $pass++; } else { $fail++; }
    printf("  %s: normalize(normalize('%s')) == normalize('%s')\n",
        $ok ? 'PASS' : '** FAIL **', $code, $code);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
