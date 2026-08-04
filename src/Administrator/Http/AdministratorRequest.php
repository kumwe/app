<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Psr\Http\Message\ServerRequestInterface;

final class AdministratorRequest
{
    /** @return array<string, string> */
    public static function form(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            parse_str((string) $request->getBody(), $parsed);
        }

        $form = [];

        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $form[$key] = $value;
            }
        }

        return $form;
    }

    /** @param array<string, string> $form */
    public static function required(array $form, string $field): string
    {
        $value = trim($form[$field] ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s field is required.', $field));
        }

        return $value;
    }

    /** @param array<string, string> $form */
    public static function positiveInteger(array $form, string $field): int
    {
        $value = $form[$field] ?? '';

        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be a positive integer.', $field));
        }

        return (int) $value;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public static function contentData(array $form): array
    {
        $json = trim($form['data'] ?? '');

        if ($json === '') {
            return [];
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Content data must be a valid JSON object.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Content data must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, string> $form */
    public static function publicationWindow(array $form): PublicationWindow
    {
        $startsAt = trim($form['publish_at'] ?? '');
        $endsAt = trim($form['unpublish_at'] ?? '');

        return new PublicationWindow(
            $startsAt === '' ? null : new DateTimeImmutable($startsAt),
            $endsAt === '' ? null : new DateTimeImmutable($endsAt),
        );
    }

    public static function routeId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('The content route identifier is missing.');
        }

        return $id;
    }

    public static function session(ServerRequestInterface $request): AdministratorSession
    {
        $session = $request->getAttribute(AdministratorSession::REQUEST_ATTRIBUTE);

        if (!$session instanceof AdministratorSession) {
            throw new InvalidArgumentException('An administrator session is required.');
        }

        return $session;
    }

    /** @return array<string, true> */
    public static function capabilityMap(ServerRequestInterface $request): array
    {
        $map = [];
        foreach (self::session($request)->principal->capabilities() as $capability) {
            $map[$capability->value()] = true;
        }

        return $map;
    }
}
