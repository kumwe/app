<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Throwable;

/**
 * Console entry point for the administrator Wording screen as `kumwe wording`.
 *
 * `overrides` lists the stored overrides of one administered layer, `catalogue` searches the shipped wording an
 * override starts from, `save` stores one override and `withdraw` removes one. Every rule is
 * `MessageOverrideService`'s: `localization.overrides.manage`, the administered-layer and carried-locale checks,
 * identifier grammar, ICU validation, the transaction and the audit event, so the console refuses exactly what
 * the screen and `/api/v1/wording` refuse. The organization layer is the one the credential's membership
 * selects. Results are pretty JSON on stdout; any refusal is one line on stderr and exit status 1.
 *
 * @since  2.0.0
 */
final readonly class WordingCommand implements Command
{
    /**
     * Wire the command to the wording service and the console's token authorization route.
     *
     * @param  MessageOverrideService  $overrides      Authorized, validated, audited wording writer.
     * @param  ConsoleAuthorizer       $authorization  Resolves `--site` and `--token-file` into a context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MessageOverrideService $overrides,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to reach the wording actions.
     *
     * @return  string  Always `wording`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'wording';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  Catalogue identifier of the one-sentence summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.wording.description';
    }

    /**
     * Run one wording action and print its JSON result, or one error line.
     *
     * @param   list<string>  $arguments  Action first (default `overrides`), then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result or the failure line.
     *
     * @return  int  0 when the action completed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'overrides';
            if (!in_array($action, ['overrides', 'catalogue', 'save', 'withdraw'], true)) {
                throw new InvalidArgumentException('Unsupported wording action.');
            }
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'localization.overrides.manage');
            $result = match ($action) {
                'overrides' => [
                    'layer' => self::layer($options)->value,
                    'locale' => $options['locale'] ?? null,
                    'items' => array_map(
                        static fn (MessageOverrideRecord $record): array => $record->toArray(),
                        $this->overrides->overrides($context, self::layer($options), $options['locale'] ?? null),
                    ),
                ],
                'catalogue' => [
                    'locale' => CommandInput::required($options, 'locale'),
                    'items' => $this->overrides->searchCatalogue(
                        $context,
                        CommandInput::required($options, 'locale'),
                        $options['query'] ?? '',
                        isset($options['limit']) ? self::limit($options) : 50,
                    ),
                ],
                'save' => $this->overrides->override(
                    $context,
                    self::layer($options),
                    CommandInput::required($options, 'locale'),
                    CommandInput::required($options, 'identifier'),
                    CommandInput::required($options, 'pattern'),
                )->toArray(),
                'withdraw' => ['withdrawn' => $this->overrides->withdraw(
                    $context,
                    self::layer($options),
                    CommandInput::required($options, 'locale'),
                    CommandInput::required($options, 'identifier'),
                )],
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one operator-readable line and exit status 1.
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Resolve the administered layer, `site` unless `--layer=organization` is given.
     *
     * @param   array<string, string>  $options  Parsed options.
     *
     * @return  MessageCatalogueLayer  Site or organization layer.
     *
     * @throws  InvalidArgumentException  When the value names no administered layer.
     *
     * @since   2.0.0
     */
    private static function layer(array $options): MessageCatalogueLayer
    {
        $layer = MessageCatalogueLayer::tryFrom($options['layer'] ?? 'site');
        if ($layer !== MessageCatalogueLayer::Site && $layer !== MessageCatalogueLayer::Organization) {
            throw new InvalidArgumentException('An administered wording layer is required.');
        }

        return $layer;
    }

    /**
     * Read the catalogue search bound, from one to two hundred.
     *
     * @param   array<string, string>  $options  Parsed options carrying `limit`.
     *
     * @return  int  Bounded limit.
     *
     * @throws  InvalidArgumentException  When the bound is outside one to two hundred.
     *
     * @since   2.0.0
     */
    private static function limit(array $options): int
    {
        $limit = CommandInput::positiveInteger($options, 'limit');
        if ($limit > 200) {
            throw new InvalidArgumentException('The wording search limit must be between 1 and 200.');
        }

        return $limit;
    }
}
