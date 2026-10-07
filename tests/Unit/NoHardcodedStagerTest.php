<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the capability-dispatch contract at the source level.
 *
 * Square is a *source system*: it stages data and lets Import Staging decide
 * what to create in FA. That only holds if Square never names a specific
 * stager. `hook_invoke('ksf_FA_ImportStagingProcessing', ...)` would hardcode
 * Square's choice of stager, so a replacement stager could not take over
 * without editing Square -- and Square would silently keep writing to the old
 * one.
 *
 * This is a source-text assertion on purpose: it catches a regression in code
 * paths no unit test exercises (admin pages, cron, webhook handlers).
 *
 * @BABOK Related: UT-SQUARE-004-001
 * @since 1.1.0
 */
class NoHardcodedStagerTest extends TestCase
{
    private const FORBIDDEN = ['ksf_FA_ImportStagingProcessing', 'ksf_FA_ImportStagingProcessing_UI'];

    private static function sourceFiles(): array
    {
        $root   = dirname(__DIR__, 2);
        $src    = $root . '/src';
        $its    = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $its[] = $file->getPathname();
            }
        }

        // hooks.php lives at the module root, outside src/.
        if (is_file($root . '/hooks.php')) {
            $its[] = $root . '/hooks.php';
        }

        return $its;
    }

    /**
     * Strip comments so a docblock *explaining* the migration (or documenting
     * the legacy shape) is not mistaken for a live dispatch. Only executable
     * tokens are searched.
     *
     * @param string $path
     * @return string
     */
    private static function codeWithoutComments(string $path): string
    {
        $tokens = token_get_all((string)file_get_contents($path));
        $out    = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep newlines so token positions/line numbers survive.
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                }
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * No production file may name a specific stager as the target of a hook.
     */
    public function testNoProductionFileTargetsANamedStagerModule(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $path) {
            $src = self::codeWithoutComments($path);

            // Only a real dispatch call matters; prose in a docblock may still
            // mention the old module name while explaining the migration.
            if (preg_match_all('/hook_invoke\w*\s*\(\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
                foreach ($m[1] as $target) {
                    if (in_array($target, self::FORBIDDEN, true)) {
                        $offenders[] = str_replace(dirname(__DIR__, 2) . '/', '', $path)
                            . ' -> hook_invoke(' . $target . ')';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Square must dispatch staging by capability, not by module name:\n  "
            . implode("\n  ", $offenders)
        );
    }
}
