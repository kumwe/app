<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;

/**
 * The design vocabulary a package declares: its tokens, its recipes, and the size roles a theme remaps.
 *
 * A block never carries a colour, a font or a pixel width; it names an entry from a declared vocabulary,
 * and the active theme decides what that name resolves to. Tokens are the atomic names, recipes are
 * named combinations of them, and size roles are the dimensional slots — the roles a theme remaps when
 * it scales a layout — so declaring them is what lets a contributed block be restyled by a theme the
 * package has never met. The names are scoped by the vocabulary's own namespaced identifier, and each
 * list is bounded, deduplicated by refusal and sorted, so the manifest is a closed readable claim.
 *
 * An empty vocabulary is refused: a declaration that names nothing would occupy an identifier while
 * promising nothing a theme could remap.
 *
 * @since  2.0.0
 */
final readonly class CompositionDesignVocabularyDeclaration implements ContributionDefinition
{
    /**
     * Tokens one vocabulary may declare.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_TOKENS = 64;

    /**
     * Recipes one vocabulary may declare.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_RECIPES = 32;

    /**
     * Size roles one vocabulary may declare.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_SIZE_ROLES = 16;

    /**
     * Declared atomic design token names, sorted.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $tokens;

    /**
     * Declared recipe names, sorted.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $recipes;

    /**
     * Declared size role names a theme remaps, sorted.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $sizeRoles;

    /**
     * Declare one vocabulary: its tokens, its recipes, and its size roles.
     *
     * @param   string        $vocabularyId  Namespaced identifier inside the declaring package's namespace.
     * @param   list<string>  $tokens        Atomic design token names scoped to this vocabulary.
     * @param   list<string>  $recipes       Named combinations of tokens scoped to this vocabulary.
     * @param   list<string>  $sizeRoles     Dimensional role names a theme remaps.
     * @param   int           $version       Declared vocabulary revision, one or higher.
     *
     * @throws  InvalidArgumentException  When the identifier is not namespaced, every list is empty, a list
     *          is over its bound, a name is malformed or repeated, or the version is not positive.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $vocabularyId,
        array $tokens,
        array $recipes,
        array $sizeRoles,
        private int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $vocabularyId) !== 1) {
            throw new InvalidArgumentException('A composition design vocabulary identifier must be namespaced.');
        }
        if ($tokens === [] && $recipes === [] && $sizeRoles === []) {
            throw new InvalidArgumentException('A composition design vocabulary must declare at least one name.');
        }
        $this->tokens = $this->names($tokens, 'token', self::MAXIMUM_TOKENS);
        $this->recipes = $this->names($recipes, 'recipe', self::MAXIMUM_RECIPES);
        $this->sizeRoles = $this->names($sizeRoles, 'size role', self::MAXIMUM_SIZE_ROLES);
        if ($version < 1) {
            throw new InvalidArgumentException(
                'A composition design vocabulary version must be a positive integer.',
            );
        }
    }

    /**
     * The identifier this vocabulary is registered and its names are scoped under.
     *
     * @return  string  Namespaced vocabulary identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->vocabularyId;
    }

    /**
     * The declared revision of this vocabulary.
     *
     * @return  int  One or higher.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Serialize the declaration for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array{
     *              vocabulary_id: string,
     *              tokens: list<string>,
     *              recipes: list<string>,
     *              size_roles: list<string>,
     *              version: int
     *          }  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'vocabulary_id' => $this->vocabularyId,
            'tokens' => $this->tokens,
            'recipes' => $this->recipes,
            'size_roles' => $this->sizeRoles,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated design vocabulary declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['recipes', 'size_roles', 'tokens', 'version', 'vocabulary_id'];
        $declared = array_keys($data);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition design vocabulary declaration must carry exactly its members.',
            );
        }
        $vocabularyId = $data['vocabulary_id'];
        $version = $data['version'];
        if (!is_string($vocabularyId) || !is_int($version)) {
            throw new InvalidArgumentException(
                'A composition design vocabulary declaration member has the wrong type.',
            );
        }

        return new self(
            $vocabularyId,
            self::strings($data['tokens']),
            self::strings($data['recipes']),
            self::strings($data['size_roles']),
            $version,
        );
    }

    /**
     * Validate, bound, and sort one list of vocabulary names.
     *
     * @param   list<string>  $names  Declared names of one kind.
     * @param   string        $kind   Kind named in the failure message.
     * @param   int           $bound  Most names this kind may declare.
     *
     * @return  list<string>  The names in sorted order.
     *
     * @throws  InvalidArgumentException  When the list is over its bound, a name is malformed, or a name
     *          is repeated.
     *
     * @since   2.0.0
     */
    private function names(array $names, string $kind, int $bound): array
    {
        if (count($names) > $bound) {
            throw new InvalidArgumentException(sprintf(
                'A composition design vocabulary may declare at most %d %s names.',
                $bound,
                $kind,
            ));
        }
        foreach ($names as $name) {
            if (preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*$/D', $name) !== 1 || strlen($name) > 64) {
                throw new InvalidArgumentException(sprintf(
                    'A composition design vocabulary %s name must be a bounded lowercase identifier.',
                    $kind,
                ));
            }
        }
        if ($names !== array_unique($names)) {
            throw new InvalidArgumentException(sprintf(
                'A composition design vocabulary repeats a %s name.',
                $kind,
            ));
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Require one decoded manifest member to be a list of strings.
     *
     * @param   mixed  $value  Decoded member value.
     *
     * @return  list<string>  The list exactly as declared.
     *
     * @throws  InvalidArgumentException  When the value is not a list or holds a non-string.
     *
     * @since   2.0.0
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(
                'A composition design vocabulary declaration member has the wrong type.',
            );
        }
        $result = [];
        foreach ($value as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('A composition design vocabulary name must be a string.');
            }
            $result[] = $name;
        }

        return $result;
    }
}
