<?php
declare(strict_types=1);
/**
 * seed-api-tags.php — one-time bootstrap: mark the build-with API with @api.
 *
 * The gNodeClientInterface is auto-included by gen-public-api.php (an interface
 * IS the contract), so only the documented class-only extras and the supporting
 * public types are tagged here — curated from CONTRACT.md §1. gen then emits
 * interface methods + these @api methods.
 *
 *   php scripts/seed-api-tags.php          # DRY: report
 *   php scripts/seed-api-tags.php --apply  # write the tags
 */

$ROOT  = dirname(__DIR__);
$APPLY = in_array('--apply', $argv, true);

// relative file => curated method names (from CONTRACT.md §1 + §4 public types)
$MAP = [
    'src/gNodeClient.php' => ['forSite','fromEnvironment','queueCommsMessage','queueContactForm','getCommsStream','postToGeodineum','getComputeStream','getHealthStream','getBroadcastStream','ensureConsumerGroups','getStreamStatus'],
    'src/Storage/ValKeyStorage.php' => ['fcall','xAdd'],
    'src/Health/HealthStreamWriter.php' => ['publishMetrics'],
    'src/Health/HealthMetrics.php' => ['toCompressedFormat','toArray','isHealthy','calculateScore','validate','isStale'],
    'src/Broadcast/BroadcastReader.php' => ['read','write','getPosition','setPosition','resetToNewMessages','trim','getStatistics'],
    'src/Broadcast/BroadcastMessage.php' => ['getField','getMessage','hasField','matchesType','isStale','toArray','toJson','getAgeSeconds'],
];

$tagged = 0; $already = 0; $missing = [];
foreach ($MAP as $rel => $names) {
    $file = "$ROOT/$rel";
    $set = array_flip($names);
    if (!is_file($file)) { $missing[$rel] = ['(no file)']; continue; }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $found = [];
    for ($i = 0; $i < count($lines); $i++) {
        if (!preg_match('/^\s*(?:public\s+|final\s+|abstract\s+|static\s+)*function\s+([A-Za-z_]\w*)\s*\(/', $lines[$i], $m)) continue;
        if (preg_match('/\b(private|protected)\s+function/', $lines[$i])) continue;
        $name = $m[1];
        if (!isset($set[$name])) continue;
        $found[$name] = true;
        $j = $i - 1;
        while ($j >= 0 && preg_match('/^\s*(#\[|\/\/|$)/', $lines[$j])) $j--;
        if ($j >= 0 && preg_match('/\*\/\s*$/', $lines[$j])) {
            $s = $j; while ($s >= 0 && strpos($lines[$s], '/**') === false) $s--;
            $has = false; for ($k = max(0,$s); $k <= $j; $k++) if (strpos($lines[$k], '@api') !== false) { $has = true; break; }
            if ($has) { $already++; continue; }
            $prefix = preg_match('/^(\s*)\*/', $lines[$j], $pm) ? $pm[1] . '* @api' : ' * @api';
            array_splice($lines, $j, 0, [$prefix]); $i++; $tagged++;
        } else {
            $indent = preg_match('/^(\s*)/', $lines[$i], $pm) ? $pm[1] : '    ';
            array_splice($lines, $i, 0, [$indent . '/** @api */']); $i++; $tagged++;
        }
    }
    if ($APPLY && $found) file_put_contents($file, implode("\n", $lines) . "\n");
    $miss = array_diff($names, array_keys($found));
    if ($miss) $missing[$rel] = array_values($miss);
}

fwrite(STDERR, ($APPLY ? "APPLIED" : "DRY-RUN") . ": tagged $tagged, $already already had @api\n");
if ($missing) {
    fwrite(STDERR, "\nCurated but not found as a method (check naming / location):\n");
    foreach ($missing as $rel => $ms) fwrite(STDERR, sprintf("  %-40s %s\n", $rel, implode(', ', $ms)));
}
