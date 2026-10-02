<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Contract;

use RuntimeException;

/**
 * Loads the generation-three CLI contract the live console dispatches against.
 *
 * Generation three adds protected password-file input to schema purge planning and approval, and
 * checkpoint-file input to audit verification. The earlier contracts remain retained unchanged;
 * their published token-only invocation cannot authorize
 * a high-impact stage. The JSON is deployed beside this loader so project archives retain the contract.
 *
 * @since  2.0.0
 */
final class CliV3MachineContract
{
    /**
     * Deployment-relative contract artifact name.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ARTIFACT = 'cli-v3.json';

    /**
     * Prevent construction of a stateless contract loader.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }

    /**
     * Return the validated, process-cached generation-three contract.
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
