<?php

declare(strict_types=1);

namespace Kumwe\App\Tools;

use LogicException;
use RuntimeException;

/**
 * Establishes a retained machine-contract artifact without ever replacing different bytes.
 *
 * Exclusive creation closes the check-then-write race: a concurrent process that establishes the path first
 * is compared with the expected bytes, and only an identical artifact is accepted. A successor generation
 * must therefore use a new path rather than relying on a writer flag that can mutate a retained contract.
 *
 * @since  2.0.0
 */
final readonly class RetainedMachineContractWriter
{
    /**
     * Create a missing artifact or confirm that an existing artifact is byte-identical.
     *
     * @param   string  $path      Generation-specific artifact path.
     * @param   string  $expected  Complete deterministic contract bytes.
     *
     * @return  bool  True when this call created the artifact; false when identical bytes already existed.
     *
     * @throws  LogicException    When the retained path already contains different bytes.
     * @throws  RuntimeException  When the artifact cannot be read, created or written completely.
     *
     * @since   2.0.0
     */
    public static function establish(string $path, string $expected): bool
    {
        if (is_file($path)) {
            return self::confirm($path, $expected);
        }

        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            if (is_file($path)) {
                return self::confirm($path, $expected);
            }

            throw new RuntimeException('The retained machine-contract artifact could not be created.');
        }

        $offset = 0;
        $length = strlen($expected);
        try {
            while ($offset < $length) {
                $written = fwrite($stream, substr($expected, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException(
                        'The retained machine-contract artifact could not be written completely.',
                    );
                }
                $offset += $written;
            }
            if (!fflush($stream)) {
                throw new RuntimeException('The retained machine-contract artifact could not be flushed.');
            }
        } catch (RuntimeException $exception) {
            fclose($stream);
            @unlink($path);
            throw $exception;
        }

        fclose($stream);

        return true;
    }

    /**
     * Confirm existing retained bytes without changing the artifact.
     *
     * @param   string  $path      Existing generation-specific artifact path.
     * @param   string  $expected  Complete deterministic contract bytes.
     *
     * @return  false  Existing bytes are identical, so no write occurred.
     *
     * @throws  LogicException    When the retained artifact differs from the generated contract.
     * @throws  RuntimeException  When the retained artifact cannot be read.
     *
     * @since   2.0.0
     */
    private static function confirm(string $path, string $expected): false
    {
        $actual = file_get_contents($path);
        if (!is_string($actual)) {
            throw new RuntimeException('The retained machine-contract artifact could not be read.');
        }
        if (!hash_equals($expected, $actual)) {
            throw new LogicException('A retained machine-contract artifact already exists with different bytes.');
        }

        return false;
    }
}
