<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Mailer\CriticalMails;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;

/**
 * Pure data assertions on the 17-katalog-maili §1/§2/§5 catalog: all 15 mail
 * types are present, the `technical` queue is scoped to authentication only,
 * and the overridable/systemic split matches §5 exactly (1-3 and 12 systemic;
 * everything else overridable).
 */
final class MailCatalogTest extends TestCase
{
    public function testCatalogHasTheFifteenCatalogMailsPlusTheTwoEventTeamMails(): void
    {
        self::assertCount(17, MailCatalog::all());
    }

    public function testOnlyAuthenticationMailsUseTheTechnicalQueue(): void
    {
        $technical = [
            MailCatalog::EMAIL_VERIFICATION,
            MailCatalog::PASSWORD_RESET,
            MailCatalog::ACCOUNT_LOCKED,
            MailCatalog::ACCOUNT_SETUP,
        ];

        foreach (MailCatalog::all() as $mailType => $definition) {
            if (in_array($mailType, $technical, true)) {
                self::assertSame('technical', $definition['priority'], $mailType . ' should use the technical queue');
            } else {
                self::assertSame('normal', $definition['priority'], $mailType . ' should use the standard queue');
            }
        }
    }

    public function testSystemicMailTypesAreNotOverridable(): void
    {
        $systemic = [
            MailCatalog::EMAIL_VERIFICATION,
            MailCatalog::PASSWORD_RESET,
            MailCatalog::ACCOUNT_LOCKED,
            MailCatalog::ACCOUNT_SETUP,
            MailCatalog::SHOP_ORDER_STATUS,
            MailCatalog::EVENT_TEAM_INVITATION,
            MailCatalog::EVENT_TEAM_REVOKED,
        ];

        foreach (MailCatalog::all() as $mailType => $definition) {
            self::assertSame(
                !in_array($mailType, $systemic, true),
                $definition['overridable'],
                $mailType . ' overridable flag should match 17 §5'
            );
        }
    }

    public function testEveryDefaultTemplateOnlyReferencesItsOwnDeclaredVariables(): void
    {
        foreach (MailCatalog::all() as $mailType => $definition) {
            $used = array_merge(
                \ZmierzymyCzas\Domain\Mailer\CriticalMails\MailRenderer::extractVariables($definition['defaultSubject']),
                \ZmierzymyCzas\Domain\Mailer\CriticalMails\MailRenderer::extractVariables($definition['defaultBodyHtml'])
            );
            foreach ($used as $variable) {
                self::assertContains($variable, $definition['variables'], "{$mailType}'s default template uses undeclared variable {$variable}");
            }
        }
    }

    public function testGetThrowsForUnknownMailType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailCatalog::get('not_a_real_mail_type');
    }
}
