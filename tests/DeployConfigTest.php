<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/** .cpanel.yml deploys exactly what the servers need and never secrets or dev files (BUILD_PLAN M9). */
final class DeployConfigTest extends TestCase
{
    private const APP_FOLDERS = ['app', 'cron', 'database', 'lang', 'order', 'vendor'];

    private static function tasks(): array
    {
        $yml = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/.cpanel.yml'));
        preg_match_all('/^\s+- (.+)$/m', $yml, $m);
        return $m[1];
    }

    public function testDeploysTheAppFoldersAndNothingElse(): void
    {
        $copied = [];
        foreach (self::tasks() as $task) {
            if (preg_match('#^cp -R ([a-z]+)/\. "\$(APPDIR|WEBDIR)/#', $task, $m)
                || preg_match('#^find ([a-z]+) -mindepth 1 -maxdepth 1 .*-exec cp -R \{\} "\$APPDIR/\1/" \\\\;$#', $task, $m)) {
                $copied[] = $m[1];
            }
        }
        sort($copied);
        self::assertSame([...self::APP_FOLDERS, 'website'], $copied, 'never tests/, docs/, tools/');
        self::assertStringNotContainsString('rsync', implode("\n", self::tasks()), 'rsync is missing in some hosts\' sandboxes');
    }

    public function testKeepsWhatEachServerOwnsAndCleansTheRest(): void
    {
        $tasks = self::tasks();
        $all = implode("\n", $tasks);

        $appClean = (string) current(array_filter($tasks, static fn (string $t): bool => str_starts_with($t, 'find "$APPDIR/app"')));
        self::assertStringContainsString('! -name config.php', $appClean, 'config.php stays on each server');
        $appCopy = (string) current(array_filter($tasks, static fn (string $t): bool => str_starts_with($t, 'find app ')));
        self::assertStringContainsString('! -name config.php', $appCopy, 'a config.php in the source is never copied over the one on the server');

        $orderClean = (string) current(array_filter($tasks, static fn (string $t): bool => str_starts_with($t, 'find "$APPDIR/order"')));
        foreach (['.well-known', '.htaccess', 'cgi-bin', '.user.ini', 'php.ini', 'error_log'] as $kept) {
            self::assertStringContainsString("! -name {$kept} ", $orderClean, "cPanel's {$kept} is left alone");
        }

        self::assertStringNotContainsString('find "$WEBDIR', $all, 'public_html is never cleaned (cPanel and Google files live there)');
        foreach (['storage/uploads', 'storage/logs', 'storage/backups', 'storage/sessions', 'storage/cron'] as $dir) {
            self::assertStringContainsString('"$APPDIR/' . $dir . '"', $all);
        }
        self::assertStringContainsString('approot.php', $all);
    }

    /** Every delete stays inside one named app folder: never $APPDIR itself, $WEBDIR or anything else. */
    public function testDeletesOnlyInsideTheAppFolders(): void
    {
        foreach (self::tasks() as $task) {
            if (!str_contains($task, 'rm ')) {
                continue;
            }
            self::assertMatchesRegularExpression('#^find ("\$APPDIR/[a-z]+" )+-mindepth 1 -maxdepth 1 .*-exec rm -rf \{\} \+$#', $task);
            preg_match_all('#"\$APPDIR/([a-z]+)"#', $task, $m);
            self::assertSame([], array_diff($m[1], self::APP_FOLDERS), $task);
        }
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
        // … and nothing is deleted or copied before both folders are known to exist.
        self::assertMatchesRegularExpression('#test -d "\$APPDIR" -a -d "\$WEBDIR" \|\| .*exit 1#', $tasks[2]);
        foreach (array_slice($tasks, 0, 3) as $task) {
            self::assertDoesNotMatchRegularExpression('#\b(rm|cp|find)\b#', $task);
        }
    }
}
