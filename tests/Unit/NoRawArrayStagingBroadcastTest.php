<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staging capabilities require a DTO; raw arrays must never be broadcast to them.
 *
 * ISU's only staging entry point is the STAGE_* capability family, and its
 * responder REQUIRES a \Ksfraser\StagingDto\StagingEntity instance:
 *
 *   if (!$data instanceof \Ksfraser\StagingDto\StagingEntity) {
 *       $data = ['error' => 'stageEntity requires a StagingEntity DTO instance', ...];
 *       return null;
 *   }
 *
 * Square shipped seven raw-array broadcasts that could never satisfy that. Being
 * hook_invoke_all() broadcasts they were also fire-and-forget, so the rejection
 * was discarded and each caller reported success while staging nothing. That is
 * how the refund path silently stopped staging, and how an analytics read was
 * being offered as an 'inventory adjustment'.
 *
 * This is a source-text assertion because the affected call sites (admin pages,
 * cron, webhook handlers) are not all unit-tested. Comments are stripped via the
 * tokenizer so prose explaining a gap is not a false positive.
 *
 * @BABOK Related: UT-SQUARE-004-001
 * @since 1.1.0
 */
class NoRawArrayStagingBroadcastTest extends TestCase
{
    /**
     * Capabilities that take a DTO, not an array.
     */
    private const DTO_CAPABILITIES = ['STAGE_ENTITY', 'STAGING_EXISTS'];

    private static function productionFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * Strip comments so docblocks discussing a gap are not flagged.
     */
    private static function codeWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all((string)file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Drop the comment, keep its newlines so line numbers hold.
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                // Keep every OTHER multi-char token. Names such as
                // 'hook_invoke_all' arrive as T_STRING and must be emitted, or
                // the regex below can never match a function name and this guard
                // silently passes everything.
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * No DTO-taking capability may be invoked with a broadcast.
     *
     * hook_invoke_all() over a DTO capability is always wrong twice over: the
     * argument is an array the responder will reject, and no provider's reply can
     * be read back.
     */
    public function testNoDtoCapabilityIsInvokedViaBroadcast(): void
    {
        $offenders = [];

        foreach (self::productionFiles() as $path) {
            if (!preg_match_all(
                '/hook_invoke_all\(\s*[\'"]([^\'"]+)[\'"]/',
                self::codeWithoutComments($path),
                $m
            )) {
                continue;
            }

            foreach ($m[1] as $capability) {
                if (in_array($capability, self::DTO_CAPABILITIES, true)) {
                    $offenders[] = str_replace(dirname(__DIR__, 2) . '/', '', $path)
                        . ' -> hook_invoke_all(\'' . $capability . '\', ...)';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "DTO capabilities must be invoked with hook_invoke_first, not broadcast:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * No 'stage_*'/'log_*' broadcast may exist at all.
     *
     * Nothing implements these, so each one is a call that looks like it staged
     * data and did not. A legitimate staging call goes through the DTO
     * capability; a legitimate notification uses a name that a responder
     * actually implements.
     */
    public function testNoStageOrLogBroadcastsRemain(): void
    {
        $offenders = [];

        foreach (self::productionFiles() as $path) {
            if (!preg_match_all(
                '/hook_invoke_all\(\s*[\'"]((?:stage_|log_)[^\'"]*)[\'"]/',
                self::codeWithoutComments($path),
                $m
            )) {
                continue;
            }

            foreach ($m[1] as $capability) {
                $offenders[] = str_replace(dirname(__DIR__, 2) . '/', '', $path)
                    . ' -> hook_invoke_all(\'' . $capability . '\') has no responder';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "no module implements these events, so each one is a silent no-op:\n  "
            . implode("\n  ", $offenders)
        );
    }
}