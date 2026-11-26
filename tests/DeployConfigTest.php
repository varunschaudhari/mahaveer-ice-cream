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
}
