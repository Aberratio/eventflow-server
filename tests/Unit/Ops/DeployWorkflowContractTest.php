<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Ops;

use PHPUnit\Framework\TestCase;

/**
 * Text-level contract over the deploy workflows. It guards the safety design of
 * PP.21 (guard job, no secrets before the guard, delegated remote logic, no push
 * to production) against accidental edits. Behaviour is covered by tests/ops.
 */
final class DeployWorkflowContractTest extends TestCase
{
    private const DISPATCH_TARGET_EXPRESSION =
        "github.event_name == 'workflow_dispatch' && inputs.deploy_target || 'staging'";

    public function testDeployGuardJobRunsWithoutEnvironmentOrSecrets(): void
    {
        $guard = $this->jobBlock($this->read('.github/workflows/deploy.yml'), 'guard');

        self::assertStringContainsString('scripts/ci/deploy-guard.sh', $guard);
        self::assertStringContainsString('GUARD_PRODUCTION_REF: refs/heads/master', $guard);
        self::assertStringNotContainsString('environment:', $guard);
        self::assertStringNotContainsString('secrets.', $guard);
    }

    public function testDeployJobNeedsGuardAndChecksConfigurationFirst(): void
    {
        $deploy = $this->jobBlock($this->read('.github/workflows/deploy.yml'), 'deploy');

        self::assertStringContainsString('needs: guard', $deploy);

        $names = [];
        preg_match_all('/^      - name: (.+)$/m', $deploy, $matches);
        foreach ($matches[1] as $name) {
            $names[] = trim($name);
        }
        $checkoutAt = array_search('Checkout', $names, true);
        self::assertNotFalse($checkoutAt, 'deploy job has no Checkout step');
        self::assertSame('Check deploy configuration', $names[$checkoutAt + 1] ?? null);
        self::assertStringContainsString('require-env.sh', $deploy);
    }

    public function testDeployDelegatesRemoteWorkToScript(): void
    {
        $workflow = $this->read('.github/workflows/deploy.yml');

        self::assertStringContainsString('scripts/deploy/api-remote.sh', $workflow);
        self::assertStringContainsString('ssh_remote preflight', $workflow);
        self::assertStringContainsString('ssh_remote install', $workflow);
        self::assertStringNotContainsString('unzip -o', $workflow);
        self::assertStringNotContainsString("<<'REMOTE'", $workflow);
    }

    public function testRollbackWorkflowIsDispatchOnlyAndGuarded(): void
    {
        $workflow = $this->read('.github/workflows/rollback-api.yml');

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertDoesNotMatchRegularExpression('/^\s*(push|pull_request|schedule|workflow_run):/m', $workflow);
        self::assertStringContainsString('GUARD_OPERATION: rollback', $workflow);
        self::assertStringContainsString('group: api-${{ inputs.deploy_target }}', $workflow);
        self::assertStringContainsString('needs: guard', $workflow);
        self::assertStringNotContainsString('migrate', $workflow);
    }

    public function testNoWorkflowCanReachProductionWithoutDispatch(): void
    {
        $files = glob($this->root() . '/.github/workflows/*.yml');
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $yaml = $this->normalise((string) file_get_contents($file));
            // Only workflows that bind a GitHub Environment can read deploy secrets.
            if (preg_match('/^    environment:/m', $yaml) !== 1) {
                continue;
            }
            $hasPushTrigger = preg_match('/^  (push|schedule|pull_request):/m', $yaml) === 1;
            if (!$hasPushTrigger) {
                continue;
            }
            self::assertStringContainsString(
                self::DISPATCH_TARGET_EXPRESSION,
                $yaml,
                basename($file) . ' binds an environment and has an automatic trigger: the target must be '
                . 'staging unless the event is workflow_dispatch'
            );
            self::assertStringNotContainsString(
                'deploy_target: production',
                $yaml,
                basename($file) . ' must never hard-code the production target'
            );
        }
    }

    public function testDependabotCoversComposerAndActions(): void
    {
        $yaml = $this->read('.github/dependabot.yml');

        self::assertStringContainsString('package-ecosystem: composer', $yaml);
        self::assertStringContainsString('package-ecosystem: github-actions', $yaml);
        self::assertStringContainsString('timezone: Europe/Warsaw', $yaml);
    }

    public function testCiRunsOpsChecks(): void
    {
        $yaml = $this->read('.github/workflows/test.yml');

        self::assertStringContainsString('rhysd/actionlint:', $yaml);
        self::assertStringContainsString('tests/ops/run.sh', $yaml);
        self::assertStringContainsString('tests/ops/backup-restore.it.sh', $yaml);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = $this->root() . '/' . $relative;
        self::assertFileExists($path);

        return $this->normalise((string) file_get_contents($path));
    }

    private function normalise(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    /** Lines from "  <job>:" up to the next two-space-indented key or the end of the file. */
    private function jobBlock(string $yaml, string $job): string
    {
        $lines = explode("\n", $yaml);
        $block = [];
        $inside = false;
        foreach ($lines as $line) {
            if (!$inside) {
                if ($line === '  ' . $job . ':') {
                    $inside = true;
                    $block[] = $line;
                }
                continue;
            }
            if (preg_match('/^  [a-z][\w-]*:$/', $line) === 1) {
                break;
            }
            $block[] = $line;
        }
        self::assertNotEmpty($block, "job '$job' not found");

        return implode("\n", $block);
    }
}
