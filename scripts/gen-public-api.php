<?php
declare(strict_types=1);
/**
 * gen-public-api.php — regenerate PUBLIC_API.md from the public build surface.
 *
 * Extracts public method signatures + docblock summaries straight from source
 * (token_get_all, no autoload/bootstrap). A method is included only if it is
 * interface-declared (interfaces ARE the contract) or its docblock carries an
 * `@api` tag on a class. Premium-gated method families (see $PREMIUM below) are
 * marked inline — they are real, callable methods that return a `premium: true`
 * response until their Chapter-2 extension is installed. Run:
 *
 *   php scripts/gen-public-api.php
 */

$ROOT = dirname(__DIR__);

// Sections of the build surface. `as` folds every class/interface in the
// section under one display heading (e.g. the client interface + its class).
$SECTIONS = [
    ['title' => 'Client — the gNode wire surface',
     'globs' => ['src/gNodeClientInterface.php', 'src/gNodeClient.php'],
     'as'    => 'gNodeClient'],
    ['title' => 'Supporting types',
     'globs' => ['src/Storage/ValKeyStorage.php', 'src/Health/HealthStreamWriter.php',
                 'src/Health/HealthMetrics.php', 'src/Broadcast/BroadcastReader.php',
                 'src/Broadcast/BroadcastMessage.php']],
];

// method-name prefix => Chapter-2 extension that provides it (premium-gated).
$PREMIUM = ['dep' => 'gNode-TOPO', 'registry' => 'gNode-TOPO'];

/** Extract [class => [ [sig, summary, isInterface], ... ]] from one PHP file. */
function extract_public(string $file): array {
    $src = @file_get_contents($file);
    if ($src === false) return [];
    $tokens = token_get_all($src);
    $out = [];
    $class = null; $isInterface = false; $lastDoc = null;
    $pendingPublic = false; $sawVisibility = false;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            [$id, $text] = $t;
            switch ($id) {
                case T_DOC_COMMENT: $lastDoc = $text; break;
                case T_WHITESPACE: break;
                case T_CLASS: case T_INTERFACE: case T_TRAIT:
                    $j = $i + 1;
                    while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
                    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        $isInterface = ($id === T_INTERFACE);
                    }
                    break;
                case T_PUBLIC: $pendingPublic = true; $sawVisibility = true; break;
                case T_PRIVATE: case T_PROTECTED: $sawVisibility = true; break;
                case T_ABSTRACT: case T_FINAL: case T_STATIC: break;
                case T_FUNCTION:
                    $public = $pendingPublic || $isInterface || !$sawVisibility;
                    $sig = read_signature($tokens, $i, $n);
                    $hasApi = $lastDoc !== null && strpos($lastDoc, '@api') !== false;
                    if ($public && ($isInterface || $hasApi) && $sig !== null && $class !== null && $sig['name'][0] !== '_') {
                        $out[$class][] = [$sig['text'], summary_of($lastDoc), $sig['name']];
                    }
                    $lastDoc = null; $pendingPublic = false; $sawVisibility = false;
                    break;
            }
        } else {
            if ($t === ';' || $t === '{' || $t === '}') { $pendingPublic = false; $sawVisibility = false; $lastDoc = ($t === '}') ? null : $lastDoc; }
        }
    }
    return $out;
}

function read_signature(array $tokens, int $fi, int $n): ?array {
    $name = null;
    for ($k = $fi + 1; $k < $n; $k++) {
        $tk = $tokens[$k];
        if (is_array($tk) && $tk[0] === T_STRING) { $name = $tk[1]; break; }
        if (!is_array($tk) && $tk === '(') break;
    }
    if ($name === null) return null;
    $buf = ''; $depth = 0; $started = false;
    for ($k = $fi + 1; $k < $n; $k++) {
        $tk = $tokens[$k]; $txt = is_array($tk) ? $tk[1] : $tk;
        if (!$started) { if ($txt === '(') { $started = true; $depth = 1; $buf = $name . '('; } continue; }
        if (is_array($tk) && $tk[0] === T_WHITESPACE) $txt = ' ';
        if ($txt === '(') $depth++;
        if ($txt === ')') { $depth--; if ($depth === 0) { $buf .= ')';
            for ($m = $k + 1; $m < $n; $m++) { $mt = $tokens[$m]; $mx = is_array($mt) ? $mt[1] : $mt;
                if ($mx === '{' || $mx === ';') break; if (is_array($mt) && $mt[0] === T_WHITESPACE) $mx = ' '; $buf .= $mx; }
            break; } }
        $buf .= $txt;
    }
    return ['name' => $name, 'text' => preg_replace('/\s+/', ' ', trim($buf))];
}

function summary_of(?string $doc): string {
    if ($doc === null) return '';
    foreach (preg_split('/\r?\n/', $doc) as $ln) {
        $ln = trim($ln); $ln = preg_replace('#^/\*+#', '', $ln); $ln = preg_replace('#\*+/$#', '', $ln);
        $ln = ltrim($ln, "* \t");
        if ($ln === '' || $ln[0] === '@' || strpos($ln, '{@inheritdoc}') !== false) continue;
        return rtrim($ln, ' .');
    }
    return '';
}

function premium_label(string $name, array $premium): string {
    foreach ($premium as $prefix => $ext) if (strpos($name, $prefix) === 0) return $ext;
    return '';
}

// ---- build ----------------------------------------------------------------
$md = [];
$md[] = "# gNode-Client — Public API";
$md[] = "";
$md[] = "> **Generated — do not edit by hand.** Regenerate with `php scripts/gen-public-api.php`.";
$md[] = "> The complete callable contract of `gNodeClient` plus the supporting public types.";
$md[] = "> Methods marked _premium_ are real and callable, but return a `premium: true`";
$md[] = "> response until their Chapter-2 extension is installed. [`CONTRACT.md`](CONTRACT.md)";
$md[] = "> is the authoritative prose; where the two differ, the code (and this index) win.";
$md[] = "";

foreach ($SECTIONS as $sec) {
    $files = [];
    foreach ($sec['globs'] as $g) foreach (glob("$ROOT/$g") ?: [] as $f) $files[$f] = true;
    $files = array_keys($files); sort($files);

    $acc = [];  // display => ['rel'=>, 'methods'=>[]]
    foreach ($files as $f) {
        $rel = ltrim(str_replace($ROOT, '', $f), '/');
        foreach (extract_public($f) as $class => $methods) {
            if (!$methods) continue;
            $disp = $sec['as'] ?? $class;
            if (!isset($acc[$disp])) $acc[$disp] = ['rel' => $rel, 'methods' => []];
            foreach ($methods as $x) $acc[$disp]['methods'][] = $x;
        }
    }
    if (!$acc) continue;
    $md[] = "## {$sec['title']}";
    foreach ($acc as $disp => $info) {
        $md[] = ""; $md[] = "### `$disp`"; $md[] = "<sub>`{$info['rel']}`</sub>"; $md[] = "";
        // de-dup by method name (interface decl vs class impl); keep first
        $seen = [];
        foreach ($info['methods'] as [$sig, $sum, $name]) {
            if (isset($seen[$name])) continue; $seen[$name] = true;
            $tag = premium_label($name, $PREMIUM);
            $line = "- `$sig`";
            if ($sum !== '') $line .= " — $sum";
            if ($tag !== '') $line .= "  · _premium ($tag)_";
            $md[] = $line;
        }
    }
    $md[] = "";
}

$doc = implode("\n", $md) . "\n";
file_put_contents("$ROOT/PUBLIC_API.md", $doc);
fwrite(STDERR, "wrote PUBLIC_API.md (" . substr_count($doc, "\n- `") . " public members)\n");
