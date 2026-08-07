<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Site\Application\SiteSettings;
use Throwable;

final readonly class ManageSettingsCommand implements Command
{
    public function __construct(private SiteSettings $settings, private ConsoleAuthorizer $authorization)
    {
    }

    public function name(): string
    {
        return 'settings';
    }

    public function description(): string
    {
        return 'Read or update site configuration.';
    }

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
                foreach ([
                    'presentation-logo' => 'logo',
                    'presentation-footer' => 'footer_text',
                    'presentation-primary-menu' => 'primary_menu',
                    'presentation-active-scheme' => 'active_scheme',
                    'presentation-button-style' => 'button_style',
                    'presentation-button-shape' => 'button_shape',
                    'presentation-header-style' => 'header_style',
                ] as $option => $key) {
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
