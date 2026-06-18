<?php
/**
 * Run from Magento root: php find_invalid_xml.php
 * Finds XML files that may cause DOMXPath "Invalid expression" during config merge.
 */
$baseDir = getcwd();
for ($d = __DIR__; $d !== '/' && strlen($d) > 1; $d = dirname($d)) {
    if (is_file($d . '/app/bootstrap.php') || is_file($d . '/bin/magento')) {
        $baseDir = $d;
        break;
    }
}
if (!is_file($baseDir . '/app/bootstrap.php')) {
    $baseDir = getcwd();
}
echo "Magento root: $baseDir\n";
$dirs = [
    $baseDir . '/app/code',
    $baseDir . '/vendor/magento',
];
$extensions = ['xml'];
$issues = [];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $path => $fi) {
        if (!in_array(strtolower($fi->getExtension()), $extensions, true)) continue;
        $rel = str_replace($baseDir . '/', '', $path);
        $content = @file_get_contents($path);
        if ($content === false) {
            $issues[] = "$rel - cannot read";
            continue;
        }
        if (preg_match('/<\s*(\w+)[^>]*\s(id|name)\s*=\s*["\']\s*["\']/', $content)) {
            $issues[] = "$rel - empty id/name attribute";
        }
        $xml = @simplexml_load_string($content);
        if ($xml === false) {
            $issues[] = "$rel - invalid XML";
        }
    }
}

if (empty($issues)) {
    echo "No obvious XML issues found in app/code or vendor/magento.\n";
    echo "Error may be from merge order or a different path. Try disabling Osiyatech_ShoppingCart in app/etc/config.php and run cache:flush again.\n";
} else {
    echo "Possible issues:\n";
    foreach ($issues as $i) echo "  - $i\n";
}
