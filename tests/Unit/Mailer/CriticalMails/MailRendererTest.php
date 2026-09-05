<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Mailer\CriticalMails;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailRenderer;

final class MailRendererTest extends TestCase
{
    public function testRenderSubstitutesKnownVariables(): void
    {
        $renderer = new MailRenderer();

        $html = $renderer->render('<p>Cześć {{name}}, zapisano Cię na {{eventName}}.</p>', [
            'name' => 'Ala',
            'eventName' => 'Bieg Testowy',
        ]);

        self::assertSame('<p>Cześć Ala, zapisano Cię na Bieg Testowy.</p>', $html);
    }

    public function testRenderLeavesUnknownPlaceholdersUntouchedRatherThanBlankingThem(): void
    {
        $renderer = new MailRenderer();

        $html = $renderer->render('<p>{{knownVar}} / {{missingVar}}</p>', ['knownVar' => 'X']);

        self::assertSame('<p>X / {{missingVar}}</p>', $html);
    }

    public function testRenderEscapesVariableValues(): void
    {
        $renderer = new MailRenderer();

        $html = $renderer->render('<p>{{name}}</p>', ['name' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAssertOnlyKnownVariablesPassesForDeclaredVariables(): void
    {
        $renderer = new MailRenderer();

        $renderer->assertOnlyKnownVariables('Hi {{name}}', '<p>{{name}} — {{eventName}}</p>', ['name', 'eventName']);
        $this->addToAssertionCount(1);
    }

    public function testAssertOnlyKnownVariablesRejectsUndeclaredVariables(): void
    {
        $renderer = new MailRenderer();

        $this->expectException(\InvalidArgumentException::class);
        $renderer->assertOnlyKnownVariables('Hi {{name}}', '<p>{{totallyUnknown}}</p>', ['name']);
    }

    public function testExtractVariablesReturnsUniqueNamesInOrderOfFirstAppearance(): void
    {
        self::assertSame(
            ['a', 'b'],
            MailRenderer::extractVariables('{{a}} {{b}} {{a}}')
        );
    }
}
