<?php

use PHPUnit\Framework\TestCase;

final class ControllerNavigationTemplateTest extends TestCase
{
    private function template(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../WarManager/Templates/' . $name . '.latte.xml');
        self::assertIsString($contents);

        return $contents;
    }

    public function testInteractiveWindowsUseNativeTrackmaniaMenuNavigation(): void
    {
        foreach (['stats', 'players', 'admin'] as $template) {
            $contents = $this->template($template);
            self::assertStringContainsString(
                'EnableMenuNavigation(True, True, True, BackControl, 100);',
                $contents
            );
            self::assertStringContainsString('Page.ScrollToControl(Page.FocusedControl);', $contents);
            self::assertStringContainsString('for LocalUser', $contents);
        }
    }

    public function testPlayerWindowDefinesControllerBackAndTabNavigation(): void
    {
        $contents = $this->template('stats');

        self::assertStringContainsString('id="war-controller-back"', $contents);
        self::assertStringContainsString('EMenuNavAction::PageUp', $contents);
        self::assertStringContainsString('EMenuNavAction::PageDown', $contents);
        self::assertStringContainsString('id="war-map-detail-back"', $contents);
        self::assertStringContainsString('id="war-team-confirm-back"', $contents);
    }

    public function testStandalonePlayersWindowReturnsToPlayerOverview(): void
    {
        $contents = $this->template('players');

        self::assertStringContainsString('action="war.players.back"', $contents);
        self::assertStringContainsString('id="war-players-confirm-back"', $contents);
        self::assertStringContainsString('P_WarPlayersFocusConfirm', $contents);
    }

    public function testAdminEntriesAndDestructiveDialogsAreControllerReachableAndSafe(): void
    {
        $contents = $this->template('admin');

        self::assertStringContainsString('id="war-admin-entry-name"', $contents);
        self::assertStringContainsString('id="war-admin-entry-map-uid"', $contents);
        self::assertStringContainsString('id="war-admin-entry-point-{$point->rank}"', $contents);
        self::assertStringContainsString('id="war-admin-confirm-back"', $contents);
        self::assertStringContainsString("'war-admin-confirm-back'", file_get_contents(
            __DIR__ . '/../WarManager/Classes/WarAdminOverlay.php'
        ));
    }

    public function testModuleDeclaresControllerReleaseVersion(): void
    {
        $module = json_decode(
            (string) file_get_contents(__DIR__ . '/../WarManager/module.json'),
            true
        );

        self::assertSame('0.13.0', $module['version']);
    }
}
