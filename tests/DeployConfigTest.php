<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/** .cpanel.yml deploys exactly what the servers need and never secrets or dev files (BUILD_PLAN M9). */
final class DeployConfigTest extends TestCase
{
    private static function tasks(): array
    {
        $yml = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/.cpanel.yml'));
        preg_match_all('/^\s+- (.+)$/m', $yml, $m);
        return $m[1];
    }

    public function testDeploysTheAppFoldersAndNothingElse(): void
    {
        $sources = [];
        foreach (self::tasks() as $task) {
            if (preg_match('#rsync .* ([a-z]+)/ \$(APPDIR|WEBDIR)/#', $task, $m)) {
                $sources[] = $m[1];
            }
        }
        sort($sources);
        self::assertSame(['app', 'cron', 'database', 'lang', 'order', 'vendor', 'website'], $sources);
    }

    public function testNeverOverwritesConfigAndCleansTheOrderFolder(): void
    {
        $tasks = implode("\n", self::tasks());
        self::assertMatchesRegularExpression("#rsync -a --delete --exclude 'config.php' app/#", $tasks, 'config.php stays on each server');
        self::assertMatchesRegularExpression('#rsync -a --delete .*order/ \$APPDIR/order/#', $tasks, 'removed pages disappear');
        foreach (['.well-known', '.htaccess', '.user.ini', 'php.ini'] as $kept) {
            self::assertStringContainsString("--exclude '{$kept}'", $tasks, "cPanel's {$kept} is left alone");
        }
        self::assertDoesNotMatchRegularExpression('#--delete[^\n]*website/#', $tasks, 'public_html is never cleaned (cPanel and Google files live there)');
        foreach (['storage/uploads', 'storage/logs', 'storage/backups', 'storage/sessions', 'storage/cron'] as $dir) {
            self::assertStringContainsString('$APPDIR/' . $dir, $tasks);
        }
        self::assertStringContainsString('approot.php', $tasks);
    }

    /**
     * Each task must stay one plain YAML string: ": " or " #" inside an unquoted line turns it
     * into a key/value pair or cuts it at a comment, and cPanel then does not deploy at all.
     */
    public function testEveryTaskIsAPlainYamlCommand(): void
    {
        foreach (self::tasks() as $task) {
            self::assertStringNotContainsString(': ', $task, "YAML would read this as key: value – {$task}");
            self::assertStringNotContainsString(' #', $task, "YAML would cut this at a comment – {$task}");
            self::assertDoesNotMatchRegularExpression('/^[\'"{\[&*!|>%@`]/', $task, "starts with a YAML special character – {$task}");
        }
    }

    public function testSettingsFileIsReadSafely(): void
    {
        $tasks = self::tasks();
        // Windows line ends / spaces in mahavir_deploy.env must not become part of a folder name …
        self::assertStringContainsString("tr -d '[:space:]'", $tasks[0]);
        self::assertStringContainsString("tr -d '[:space:]'", $tasks[1]);
        // … and nothing is copied before both folders are known to exist.
        self::assertMatchesRegularExpression('#test -d "\$APPDIR" -a -d "\$WEBDIR" \|\| .*exit 1#', $tasks[2]);
        self::assertStringNotContainsString('rsync', implode("\n", array_slice($tasks, 0, 3)));
    }
}
