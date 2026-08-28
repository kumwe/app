<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Extension\Toolchain\PackageSigner;
use Throwable;

/**
 * Creates a detached Ed25519 sidecar from a protected signing key.
 *
 * @since  2.0.0
 */
final readonly class SignExtensionCommand implements Command
{
    /**
     * Bind the command to protected package signing.
     *
     * @param  PackageSigner  $signer  Protected package signing service.
     *
     * @since  2.0.0
     */
    public function __construct(private PackageSigner $signer)
    {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Always `extension:sign`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:sign';
    }

    /**
     * Describe protected Ed25519 signing for the command list.
     *
     * @return  string  One-line command summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_sign.description';
    }

    /**
     * Verify the package, sign its digest, and publish a detached public sidecar.
     *
     * @param   list<string>  $arguments  Package path followed by key ID, key-file, and output options.
     * @param   Output        $output     Sidecar summary and failure sink.
     *
     * @return  int  Zero after atomic publication, one after any refused or failed step.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $archive = $arguments[0] ?? '';
            if ($archive === '' || str_starts_with($archive, '--')) {
                throw new InvalidArgumentException('Usage: extension:sign /absolute/package.zip --key-id=ID '
                    . '--secret-key-file=/absolute/key --output=/absolute/signature.json');
            }
            $options = CommandInput::options(array_slice($arguments, 1));
            $document = $this->signer->sign(
                $archive,
                CommandInput::required($options, 'key-id'),
                CommandInput::required($options, 'secret-key-file'),
            );
            $path = CommandInput::required($options, 'output');
            $this->signer->write($document, $path);
            $output->line(CommandInput::render(['output' => $path, ...$document->toArray()]));

            return 0;
        } catch (Throwable $failure) {
            $output->error($failure->getMessage());

            return 1;
        }
    }
}
