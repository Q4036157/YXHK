<?php

declare(strict_types=1);

namespace Mautic\UserBundle\Model;

use Mautic\UserBundle\Event\PasswordStrengthValidateEvent;
use Mautic\UserBundle\UserEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use ZxcvbnPhp\Zxcvbn as PasswordStrengthEstimator;

final readonly class PasswordStrengthEstimatorModel
{
    public const MINIMUM_PASSWORD_STRENGTH_ALLOWED = 3;

    private const MINIMUM_NUMERIC_PASSWORD_LENGTH = 6;

    private const MINIMUM_UNIQUE_NUMERIC_DIGITS = 4;

    private const SEQUENTIAL_DIGIT_RUN_LENGTH = 4;

    private const DICTIONARY = [
        'mautic',
        'user',
        'lead',
        'bundle',
        'campaign',
        'company',
    ];

    private PasswordStrengthEstimator $passwordStrengthEstimator;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
        $this->passwordStrengthEstimator = new PasswordStrengthEstimator();
    }

    /**
     * @param string[] $dictionary
     */
    public function validate(?string $password, int $score = self::MINIMUM_PASSWORD_STRENGTH_ALLOWED, array $dictionary = self::DICTIONARY): bool
    {
        $numericPasswordValidation = $this->validateNumericPassword($password);
        $isValid                   = $numericPasswordValidation ?? (
            $score <= $this->passwordStrengthEstimator->passwordStrength($password, $this->sanitizeDictionary($dictionary))['score']
        );

        $passwordStrengthValidateEvent = new PasswordStrengthValidateEvent($isValid, $password);
        $this->dispatcher->dispatch($passwordStrengthValidateEvent, UserEvents::USER_PASSWORD_STRENGTH_VALIDATION);

        return $passwordStrengthValidateEvent->isValid;
    }

    private function validateNumericPassword(?string $password): ?bool
    {
        if (null === $password || !ctype_digit($password)) {
            return null;
        }

        if (strlen($password) < self::MINIMUM_NUMERIC_PASSWORD_LENGTH
            || count(array_unique(str_split($password))) < self::MINIMUM_UNIQUE_NUMERIC_DIGITS
            || $this->containsSequentialDigitRun($password)) {
            return false;
        }

        return true;
    }

    private function containsSequentialDigitRun(string $password): bool
    {
        $lastStart = strlen($password) - self::SEQUENTIAL_DIGIT_RUN_LENGTH;
        for ($start = 0; $start <= $lastStart; ++$start) {
            $run       = substr($password, $start, self::SEQUENTIAL_DIGIT_RUN_LENGTH);
            $ascending = true;
            $descending = true;

            for ($offset = 1; $offset < self::SEQUENTIAL_DIGIT_RUN_LENGTH; ++$offset) {
                $previous = (int) $run[$offset - 1];
                $current  = (int) $run[$offset];
                $ascending  = $ascending && ($current === ($previous + 1) % 10);
                $descending = $descending && ($current === ($previous + 9) % 10);
            }

            if ($ascending || $descending) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $dictionary
     *
     * @return string[]
     */
    private function sanitizeDictionary(array $dictionary): array
    {
        return array_unique(array_filter($dictionary));
    }
}
