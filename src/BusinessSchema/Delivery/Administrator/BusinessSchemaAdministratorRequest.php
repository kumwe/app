<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use DateTimeImmutable;
use InvalidArgumentException;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ServerRequestInterface;

final class BusinessSchemaAdministratorRequest
{
    public static function planId(ServerRequestInterface $request): string
    {
        $identifier = $request->getAttribute('id');
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidArgumentException('The schema-plan route identifier is missing.');
        }

        return $identifier;
    }

    /** @param array<string, string> $form */
    public static function optional(array $form, string $field): ?string
    {
        $value = trim($form[$field] ?? '');

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $form */
    public static function date(array $form, string $field): DateTimeImmutable
    {
        $value = trim($form[$field] ?? '');
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s field is required.', $field));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('The %s field is invalid.', $field), 0, $exception);
        }
    }

    public static function redirect(string $planId, string $notice, ?string $evidenceId = null): RedirectResponse
    {
        $query = [
            'plan' => $planId,
            'notice' => $notice,
        ];
        if ($evidenceId !== null) {
            $query['evidence'] = $evidenceId;
        }

        return new RedirectResponse('/administrator/business-schema-plans?' . http_build_query($query), 303);
    }

    private function __construct()
    {
    }
}
