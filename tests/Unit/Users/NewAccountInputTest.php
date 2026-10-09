<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Users;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Users\NewAccountInput;
use ZmierzymyCzas\Support\InputValidationException;

final class NewAccountInputTest extends TestCase
{
    public function testAdminCreateNormalisesEmailAndCoercesNumbers(): void
    {
        $out = NewAccountInput::forAdminCreate([
            'name' => '  Jan  ',
            'email' => '  Jan@Example.TEST ',
            'birthYear' => '1990',
            'eventLimit' => '2',
        ]);

        self::assertSame('Jan', $out['name']);
        self::assertSame('jan@example.test', $out['email']);
        self::assertSame(1990, $out['birthYear']);
        self::assertSame(2, $out['eventLimit']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function badAdminInput(): array
    {
        $ok = ['name' => 'N', 'email' => 'a@b.pl'];

        return [
            'no name' => [['email' => 'a@b.pl'], 'name', 'validation_error'],
            'no email' => [['name' => 'N'], 'email', 'validation_error'],
            'bad email' => [['email' => 'a@'] + $ok, 'email', 'invalid_email'],
            'role' => [['role' => 'king'] + $ok, 'role', 'invalid_role'],
            'status' => [['status' => 'x'] + $ok, 'status', 'invalid_status'],
            'gender' => [['gender' => 'X'] + $ok, 'gender', 'validation_error'],
            'birthYear' => [['birthYear' => 1800] + $ok, 'birthYear', 'validation_error'],
            'eventLimit' => [['eventLimit' => -1] + $ok, 'eventLimit', 'validation_error'],
            'birthDate' => [['birthDate' => '2024-02-30'] + $ok, 'birthDate', 'validation_error'],
            'nip' => [['nip' => str_repeat('1', 33)] + $ok, 'nip', 'validation_error'],
            'sosContacts' => [['sosContacts' => 'x'] + $ok, 'sosContacts', 'validation_error'],
        ];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('badAdminInput')]
    public function testAdminCreateRejects(array $input, string $field, string $code): void
    {
        try {
            NewAccountInput::forAdminCreate($input);
            self::fail('expected InputValidationException');
        } catch (InputValidationException $e) {
            self::assertSame($field, $e->field);
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testRegistrationAppliesThePasswordRule(): void
    {
        $this->expectException(InputValidationException::class);
        try {
            NewAccountInput::forRegistration(['name' => 'N', 'email' => 'a@b.pl', 'password' => 'short7!']);
        } catch (InputValidationException $e) {
            self::assertSame('password_too_short', $e->errorCode);
            throw $e;
        }
    }

    public function testRegistrationReturnsOnlyTheThreeKeys(): void
    {
        $out = NewAccountInput::forRegistration(['name' => 'N', 'email' => 'A@B.pl', 'password' => 'demo1234', 'role' => 'admin']);

        self::assertSame(['name' => 'N', 'email' => 'a@b.pl', 'password' => 'demo1234'], $out);
    }
}
