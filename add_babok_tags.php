<?php
declare(strict_types=1);

$baseDir = '/home/kevin/Documents/ksf_FA_Square/src';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir),
    RecursiveIteratorIterator::LEAVES_ONLY
);
$count = 0;
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    $lines = explode("\n", $content);
    $newLines = [];
    $changed = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*(public|protected|private|static)*\s*function\s+([a-zA-Z_]\w*)\s*\(/', $line, $m)) {
            $funcName = $m[2];
            if (str_starts_with($funcName, '__')) { $newLines[] = $line; continue; }
            if (preg_match('/^\s*(public|protected|private)\s+function\s+[a-zA-Z_]\w*\(.*\)\s*\{/', $line)) { $newLines[] = $line; continue; }
            $contextStart = max(0, count($newLines) - 15);
            $contextLines = array_slice($newLines, $contextStart);
            $hasBabok = false;
            foreach ($contextLines as $cl) {
                if (strpos($cl, '@BABOK') !== false) { $hasBabok = true; break; }
            }
            if (!$hasBabok) {
                $newLines[] = '    /**';
                $newLines[] = "     * @BABOK Related: FR-SQ-001";
                $newLines[] = '     */';
                $changed = true;
            }
        }
        $newLines[] = $line;
    }
    if ($changed) {
        file_put_contents($file->getPathname(), implode("\n", $newLines));
        $count++;
        echo "Updated: " . $file->getPathname() . "\n";
    }
}
echo "Total files updated: $count\n";
