<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Authentication;

use InvalidArgumentException;

/**
 * The audience and purpose pair that confines an access token to one delivery surface.
 *
 * Kumwe issues bearer tokens for three separate surfaces — the REST API, the console, and the MCP
 * server — and this value object is the single place that records which purpose belongs to which
 * audience. Both ends of a token's life go through it: `DoctrineAdministratorIdentityGateway` normalizes
 * the pair before storing a new token, and `DoctrineAccessTokenVerifier` normalizes the presented pair
 * before it will match a stored row. That is what stops a token minted for the REST API from being
 * replayed against the console or the MCP endpoint, and it keeps the accepted spellings from being
 * restated at either end.
 *
 * @since  2.0.0
 */
final readonly class AccessTokenContext
{
    /**
     * Purposes each audience accepts, keyed by audience; any pair outside this table is refused.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const ALLOWED = [
        'kumwe-http' => ['api'],
        'kumwe-cli' => ['management'],
        'kumwe-mcp' => ['mcp'],
    ];

    /**
     * Hold a pair that has already been established as supported.
     *
     * Private on purpose: every instance comes from `fromStrings()` or one of the three named
     * constructors, so a value of this type is always a combination the installation honours.
     *
     * @param  string  $audience  Surface the token is issued to, such as `kumwe-http`.
     * @param  string  $purpose   Purpose the token is limited to within that surface, such as `api`.
     *
     * @since  2.0.0
     */
    private function __construct(public string $audience, public string $purpose)
    {
    }

    /**
     * Normalize an audience and purpose supplied as free text, and check the pair is supported.
     *
     * Both values are trimmed and lowercased before the check, so a caller may pass them straight from
     * configuration, a route option, or a command line. Read the accepted spelling back off the
     * returned instance rather than reusing the input, since that is the form stored and matched.
     *
     * @param   string  $audience  Candidate surface, as presented by configuration or a request.
     * @param   string  $purpose   Candidate purpose to check against that surface.
     *
     * @return  self  The normalized pair.
     *
     * @throws  InvalidArgumentException  When the audience is unknown, or the purpose is not one it accepts.
     *
     * @since   2.0.0
     */
    public static function fromStrings(string $audience, string $purpose): self
    {
        $audience = strtolower(trim($audience));
        $purpose = strtolower(trim($purpose));
        if (!in_array($purpose, self::ALLOWED[$audience] ?? [], true)) {
            throw new InvalidArgumentException('The access-token audience and purpose combination is not supported.');
        }
        return new self($audience, $purpose);
    }

    /**
     * The pair carried by a token that guards the public REST API.
     *
     * @return  self  Audience `kumwe-http` with purpose `api`.
     *
     * @since   2.0.0
     */
    public static function http(): self
    {
        return new self('kumwe-http', 'api');
    }

    /**
     * The pair carried by a token that guards console management commands.
     *
     * @return  self  Audience `kumwe-cli` with purpose `management`.
     *
     * @since   2.0.0
     */
    public static function cli(): self
    {
        return new self('kumwe-cli', 'management');
    }

    /**
     * The pair carried by a token that guards the MCP server.
     *
     * @return  self  Audience `kumwe-mcp` with purpose `mcp`.
     *
     * @since   2.0.0
     */
    public static function mcp(): self
    {
        return new self('kumwe-mcp', 'mcp');
    }
}
