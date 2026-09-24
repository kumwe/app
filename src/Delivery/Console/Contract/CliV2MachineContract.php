<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Contract;

use RuntimeException;

/**
 * Loads the generation-two CLI contract the live console dispatches against.
 *
 * Generation two is generation one plus the `studio-authoring` command; every earlier command, action,
 * option and exit is byte-identical, and generation one stays retained beside it. The JSON lives beside
 * this class so Composer project archives keep the executable contract even though repository
 * documentation is export-ignored.
 *
 * @since  2.0.0
 */
final class CliV2MachineContract
{
    /**
     * Deployment-relative contract artifact name.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ARTIFACT = 'cli-v2.json';

    /**
     * Prevent construction of a stateless contract loader.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }

    /**
     * Return the validated, process-cached generation-two contract.
     *
     * @return  CliMachineContract  Executable retained contract.
     *
     * @since   2.0.0
     */
    public static function contract(): CliMachineContract
    {
        /** @var CliMachineContract|null $contract */
        static $contract = null;
        if ($contract === null) {
            $contract = CliMachineContract::fromJson(self::json());
        }

        return $contract;
    }

    /**
     * Read the exact deployed JSON bytes used by runtime and compatibility tooling.
     *
     * @return  string  UTF-8 JSON ending in one line feed.
     *
     * @throws  RuntimeException  When the packaged artifact is absent or unreadable.
     *
     * @since   2.0.0
     */
    public static function json(): string
    {
        $json = file_get_contents(__DIR__ . '/' . self::ARTIFACT);
        if (!is_string($json)) {
            throw new RuntimeException('The deployed CLI machine contract is unavailable.');
        }

        return $json;
    }
}
