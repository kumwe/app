<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Infrastructure\Computation;

use JsonException;
use Kumwe\App\BusinessDefinition\Application\FormulaEvaluation;
use Kumwe\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\BusinessDefinition\Domain\Expression;
use Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Computation\CapabilitySet;
use Kumwe\Computation\CompiledProgram;
use Kumwe\Computation\ContractIdentity;
use Kumwe\Computation\DocumentBatch;
use Kumwe\Computation\DocumentInput;
use Kumwe\Computation\ExecutionLimits;
use Kumwe\Computation\ExecutionRefused;
use Kumwe\Computation\NativeAdapter;
use Kumwe\Computation\NativeCompatibility;
use Kumwe\Computation\PlanIdentity;
use Kumwe\Computation\ProgramEnvelope;
use Kumwe\Computation\RefusalCode;
use stdClass;

/**
 * Evaluates business-definition expressions through the admitted native `formula-draft/1` program executor.
 *
 * The program is the canonical definition JSON of the expression, so its digest is the whole authority a plan
 * needs; the documents carry only the field handles and owned-line collections the tree reads, with exact
 * decimals as strings and no float anywhere. Compiled plans belong to the shared package adapter and are kept
 * in a small most-recently-used cache, because one request evaluates the same handful of conditions and
 * formulas once per record and per field; a plan pushed out of the cache is released so the runtime's bounded
 * plan pool stays available to long-lived workers. The Engine transports an evaluation refusal as a category
 * without the owner's message, so the three refusals the App can decide before the call — an absent
 * dependency, an absent collection and a float input — keep the corpus wording, and every other refusal is
 * reported with one stable text under the exception type the callers already catch.
 *
 * @phpstan-import-type FormulaDocument from FormulaEvaluation
 *
 * @since  2.0.0
 */
final class NativeFormulaEvaluation implements FormulaEvaluation
{
    /**
     * Semantic profile the App composes for conditions and formulas.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PROFILE = 'formula-draft/1';

    /**
     * Program version the App declares for every expression it compiles.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PROGRAM_VERSION = '1.0.0';

    /**
     * Host generation token; the program digest already distinguishes one expression from another.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GENERATION = 'app-formula-1';

    /**
     * Name of the one document shape this adapter marshals: a `fields` map and a `lines` map of lists.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DOCUMENT_SCHEMA = 'app-formula-document-1';

    /**
     * Largest number of compiled plans held at once; the runtime pool admits 64 per process.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int PLAN_CACHE = 32;

    /**
     * Encoder flags for the opaque document bytes: exact spellings, unescaped text, errors thrown.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * Exact contract the admitted tuple advertises for the profile.
     *
     * @var    ContractIdentity
     * @since  2.0.0
     */
    private readonly ContractIdentity $contract;

    /**
     * Complete capability tuple every plan identity is bound to.
     *
     * @var    CapabilitySet
     * @since  2.0.0
     */
    private readonly CapabilitySet $capabilities;

    /**
     * Digest of the document shape name, carried as the plan's schema identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $schemaDigest;

    /**
     * Digest of the empty compilation options the App applies.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $optionsDigest;

    /**
     * Compiled plans keyed by program digest, least recently used first.
     *
     * @var    array<string, CompiledProgram>
     * @since  2.0.0
     */
    private array $plans = [];

    /**
     * Bind the adapter to the shared native plan owner and the admitted contract.
     *
     * @param   NativeAdapter        $adapter        Shared package adapter that compiles, executes and
     *          releases plans; a plan is bound to the instance that compiled it.
     * @param   NativeCompatibility  $compatibility  Independently recorded tuple naming the contracts.
     * @param   ExecutionLimits      $limits         Finite budgets applied to every compile and execution.
     *
     * @throws  ExecutionRefused  When the admitted tuple advertises no `formula-draft/1` contract.
     *
     * @since   2.0.0
     */
    public function __construct(
        private readonly NativeAdapter $adapter,
        NativeCompatibility $compatibility,
        private readonly ExecutionLimits $limits = new ExecutionLimits(),
    ) {
        $contract = null;
        foreach ($compatibility->capabilities->contracts() as $candidate) {
            if ($candidate->profile === self::PROFILE) {
                $contract = $candidate;
                break;
            }
        }
        if ($contract === null) {
            throw new ExecutionRefused(RefusalCode::IncompatibleCorpus);
        }
        $this->contract = $contract;
        $this->capabilities = $compatibility->capabilities;
        $this->schemaDigest = hash('sha256', self::DOCUMENT_SCHEMA);
        $this->optionsDigest = hash('sha256', '{}');
    }

    /**
     * Evaluate one expression against one document.
     *
     * @param   Expression                                       $expression  Parsed tree to evaluate.
     * @param   array<string, scalar|null>                       $fields      Values keyed by field handle.
     * @param   array<string, list<array<string, scalar|null>>>  $lines       Owned-line collections keyed by
     *          relationship handle.
     *
     * @return  mixed  Null, a boolean, an integer or a string, as the node's declared type spells it.
     *
     * @throws  InvalidBusinessDefinition  When the document is refused before or during native evaluation.
     *
     * @since   2.0.0
     */
    public function evaluate(Expression $expression, array $fields, array $lines = []): mixed
    {
        return $this->evaluateAll($expression, [['fields' => $fields, 'lines' => $lines]])[0];
    }

    /**
     * Evaluate one expression against several documents in bounded native batches.
     *
     * @param   Expression             $expression  Parsed tree to evaluate once for the whole set.
     * @param   list<FormulaDocument>  $documents   Documents in result order.
     *
     * @return  list<mixed>  One result per document, in the same order.
     *
     * @throws  InvalidBusinessDefinition  When any document is refused before or during native evaluation.
     *
     * @since   2.0.0
     */
    public function evaluateAll(Expression $expression, array $documents): array
    {
        if ($documents === []) {
            return [];
        }
        $inputs = [];
        foreach ($documents as $index => $document) {
            $inputs[] = new DocumentInput(
                'document-' . $index,
                $this->contract,
                $this->document($expression, $document['fields'], $document['lines']),
            );
        }
        $plan = $this->plan($expression);
        $values = [];
        foreach (array_chunk($inputs, max(1, $this->limits->maxDocuments)) as $chunk) {
            try {
                $batch = new DocumentBatch($chunk, $this->limits);
                $results = $this->adapter->execute($plan, $batch, $this->limits)->results();
            } catch (ExecutionRefused $refused) {
                throw $this->refusal($refused);
            }
            foreach ($results as $result) {
                $values[] = $this->value($result->bytes);
            }
        }

        return $values;
    }

    /**
     * Compile the expression, or reuse the plan compiled for the same program bytes earlier.
     *
     * @param   Expression  $expression  Tree whose canonical bytes are the program.
     *
     * @return  CompiledProgram  Plan owned by the shared adapter.
     *
     * @throws  InvalidBusinessDefinition  When the native compiler refuses the program or its budget.
     *
     * @since   2.0.0
     */
    private function plan(Expression $expression): CompiledProgram
    {
        $program = new ProgramEnvelope(
            $this->contract,
            self::PROGRAM_VERSION,
            CanonicalDefinitionJson::encode($expression->toArray()),
        );
        $digest = $program->digest();
        $cached = $this->plans[$digest] ?? null;
        if ($cached !== null) {
            unset($this->plans[$digest]);
            $this->plans[$digest] = $cached;

            return $cached;
        }
        $identity = new PlanIdentity(
            $this->contract,
            self::PROGRAM_VERSION,
            $digest,
            self::GENERATION,
            $digest,
            $this->schemaDigest,
            $this->optionsDigest,
            $this->capabilities,
        );
        try {
            $plan = $this->adapter->compile($program, $identity, $this->limits);
        } catch (ExecutionRefused $refused) {
            throw $this->refusal($refused);
        }
        $oldest = array_key_first($this->plans);
        if (count($this->plans) >= self::PLAN_CACHE && $oldest !== null) {
            $this->adapter->release($this->plans[$oldest]);
            unset($this->plans[$oldest]);
        }
        $this->plans[$digest] = $plan;

        return $plan;
    }

    /**
     * Marshal the values one evaluation reads into the opaque document bytes.
     *
     * Only the handles and collections the tree names cross the boundary, an absent one is refused with the
     * corpus wording, and every collection is a list of objects so an empty line still counts.
     *
     * @param   Expression                                       $expression  Tree naming the dependencies.
     * @param   array<string, scalar|null>                       $fields      Values keyed by field handle.
     * @param   array<string, list<array<string, scalar|null>>>  $lines       Owned-line collections.
     *
     * @return  string  JSON object with a `fields` map and a `lines` map of lists.
     *
     * @throws  InvalidBusinessDefinition  When a dependency or collection is absent, a value is a float, or
     *          a string cannot be encoded.
     *
     * @since   2.0.0
     */
    private function document(Expression $expression, array $fields, array $lines): string
    {
        $document = ['fields' => new stdClass(), 'lines' => new stdClass()];
        foreach ($expression->dependencies() as $dependency) {
            if (!array_key_exists($dependency, $fields)) {
                throw new InvalidBusinessDefinition('A formula dependency is unavailable.');
            }
            $document['fields']->{$dependency} = self::scalar($fields[$dependency]);
        }
        foreach ($expression->lineDependencies() as $collection => $handles) {
            if (!array_key_exists($collection, $lines)) {
                throw new InvalidBusinessDefinition('An owned-line collection an invariant reduces was not supplied.');
            }
            $items = [];
            foreach ($lines[$collection] as $line) {
                $item = new stdClass();
                foreach ($handles as $handle) {
                    if (array_key_exists($handle, $line)) {
                        $item->{$handle} = self::scalar($line[$handle]);
                    }
                }
                $items[] = $item;
            }
            $document['lines']->{$collection} = $items;
        }
        try {
            return json_encode($document, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidBusinessDefinition('A formula input cannot be encoded for evaluation.', 0, $exception);
        }
    }

    /**
     * Admit one supplied value to the document, refusing the float spelling the profile forbids.
     *
     * @param   scalar|null  $value  Value as the caller gathered it.
     *
     * @return  bool|int|string|null  The value unchanged.
     *
     * @throws  InvalidBusinessDefinition  When the value is a float.
     *
     * @since   2.0.0
     */
    private static function scalar(mixed $value): bool|int|string|null
    {
        if (is_float($value)) {
            throw new InvalidBusinessDefinition('Formula inputs cannot contain PHP floats.');
        }

        return $value;
    }

    /**
     * Decode the opaque result bytes into the one value the profile returns.
     *
     * @param   string  $bytes  Engine-authored `{"findings":[],"value":...}` bytes.
     *
     * @return  mixed  Null, a boolean, an integer or a string.
     *
     * @throws  ExecutionRefused  When the bytes are not the profile's result shape or carry a float.
     *
     * @since   2.0.0
     */
    private function value(string $bytes): mixed
    {
        try {
            $decoded = json_decode($bytes, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ExecutionRefused(RefusalCode::InternalFailure);
        }
        if (
            !is_array($decoded)
            || ($decoded['findings'] ?? null) !== []
            || !array_key_exists('value', $decoded)
        ) {
            throw new ExecutionRefused(RefusalCode::InternalFailure);
        }
        $value = $decoded['value'];
        if ($value !== null && !is_bool($value) && !is_int($value) && !is_string($value)) {
            throw new ExecutionRefused(RefusalCode::InternalFailure);
        }

        return $value;
    }

    /**
     * Map a native refusal to the exception the evaluation callers catch, keeping infrastructure faults raw.
     *
     * @param   ExecutionRefused  $refused  Package refusal with its stable category.
     *
     * @return  ExecutionRefused|InvalidBusinessDefinition  The definition-shaped refusal, or the fault itself.
     *
     * @since   2.0.0
     */
    private function refusal(ExecutionRefused $refused): ExecutionRefused|InvalidBusinessDefinition
    {
        return match ($refused->reason) {
            RefusalCode::InvalidProgram => new InvalidBusinessDefinition(
                'A condition or formula was refused by the native compiler.',
                0,
                $refused,
            ),
            RefusalCode::InvalidInput => new InvalidBusinessDefinition(
                'A condition or formula could not be evaluated against the supplied values.',
                0,
                $refused,
            ),
            RefusalCode::ExhaustedLimit => new InvalidBusinessDefinition(
                'A condition or formula exceeded its native execution budget.',
                0,
                $refused,
            ),
            default => $refused,
        };
    }
}
