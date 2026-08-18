<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Site\Application\SiteSettings;
use Throwable;

/**
 * Console entry point for reading and rewriting the site settings document as `kumwe settings`.
 *
 * Only the browser-managed half of a site's configuration lives here — the name, the mounted homepage,
 * locale, timezone, search indexing and the presentation block; database, cache and secret settings
 * belong to the deployment environment. Because `SiteSettings::updateAll()` replaces what it is given,
 * this command reads the current presentation object first and layers only the options the operator
 * actually passed over it, so editing the logo cannot silently reset the colour schemes or the footer.
 * Both actions end by printing the stored document, which is how the operator confirms the merge.
 *
 * @since  2.0.0
 */
final readonly class ManageSettingsCommand implements Command
{
    /**
     * Wire the command to the settings port and to the console's token authorization route.
     *
     * @param  SiteSettings       $settings       Port both actions read the document from and write it back to.
     * @param  ConsoleAuthorizer  $authorization  Resolves `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(private SiteSettings $settings, private ConsoleAuthorizer $authorization)
    {
    }

    /**
     * Name the operator types to read or change site settings.
     *
     * @return  string  Always `settings`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'settings';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary of the two actions the command accepts.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.settings.description';
    }

    /**
     * Print the managed settings document, having first applied an `update` when one was asked for.
     *
     * The first argument is the action and defaults to `get`; only `get` and `update` are accepted.
     * `update` is a full write of the top-level keys, so `--site-name`, `--homepage-content`, `--locale`
     * and `--timezone` are all required even when only one of them is changing, and
     * `--search-indexing-enabled` falls back to enabled when omitted. Presentation is the exception:
     * `--presentation-json` replaces the whole stored object, and the individual `--presentation-*`
     * options — including `--presentation-schemes-json` — then overwrite one key each on top of it.
     * Both actions require `settings.manage`, and every failure becomes one message and exit status 1.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options: `--site` and
     *          `--token-file` always, plus the update options when updating.
     * @param   Output        $output     Sink for the JSON settings document, or for the failure message.
     *
     * @return  int  0 when the document was printed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'get';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'settings.manage');
            if ($action === 'update') {
                $current = $this->settings->managed($context);
                $presentation = array_key_exists('presentation-json', $options)
                    ? CommandInput::jsonObject($options, 'presentation-json')
                    : $current['presentation'];
                if (!is_array($presentation) || array_is_list($presentation)) {
                    throw new \InvalidArgumentException('Stored presentation settings must be a JSON object.');
                }
                foreach (
                    [
                    'presentation-logo' => 'logo',
                    'presentation-footer' => 'footer_text',
                    'presentation-primary-menu' => 'primary_menu',
                    'presentation-active-scheme' => 'active_scheme',
                    'presentation-button-style' => 'button_style',
                    'presentation-button-shape' => 'button_shape',
                    'presentation-header-style' => 'header_style',
                    ] as $option => $key
                ) {
                    if (array_key_exists($option, $options)) {
                        $presentation[$key] = $options[$option];
                    }
                }
                if (array_key_exists('presentation-schemes-json', $options)) {
                    $presentation['schemes'] = CommandInput::jsonObjectList(
                        $options,
                        'presentation-schemes-json',
                    );
                }
                $this->settings->updateAll($context, [
                    'site_name' => CommandInput::required($options, 'site-name'),
                    'homepage_content_id' => CommandInput::required($options, 'homepage-content'),
                    'default_locale' => CommandInput::required($options, 'locale'),
                    'timezone' => CommandInput::required($options, 'timezone'),
                    'search_indexing_enabled' => ($options['search-indexing-enabled'] ?? '1') === '1',
                    'presentation' => $presentation,
                ]);
            } elseif ($action !== 'get') {
                throw new \InvalidArgumentException('Settings action must be get or update.');
            }
            $output->line(CommandInput::render($this->settings->managed($context)));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }
}
