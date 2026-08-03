<?php

namespace App\Tests\Dto;

use App\Dto\UserSettingsInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserSettingsInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testAllNullIsValidBecauseEveryFieldIsOptional(): void
    {
        // A single-toggle PATCH sends one key; everything else stays null.
        self::assertCount(0, $this->validator->validate(new UserSettingsInput()));
    }

    public function testShippedLocalesAreAccepted(): void
    {
        foreach (['en', 'de', 'es', 'fr', 'uk'] as $code) {
            $input = new UserSettingsInput();
            $input->locale = $code;

            self::assertCount(0, $this->validator->validate($input), "{$code} should be accepted.");
        }
    }

    public function testALocaleWeShipNoCatalogForIsRejected(): void
    {
        $input = new UserSettingsInput();
        $input->locale = 'pl';

        $violations = $this->validator->validate($input);

        self::assertCount(1, $violations);
        self::assertSame('locale', $violations[0]->getPropertyPath());
    }

    public function testARegionalTagIsRejectedByValidationBecauseTheApiTakesBareCodes(): void
    {
        $input = new UserSettingsInput();
        $input->locale = 'uk-UA';

        self::assertCount(1, $this->validator->validate($input));
    }
}
