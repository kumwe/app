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
                $this->settings->updateAll($context, [
                    'site_name' => CommandInput::required($options, 'site-name'),
                    'homepage_content_id' => CommandInput::required($options, 'homepage-content'),
                    'default_locale' => CommandInput::required($options, 'locale'),
                    'timezone' => CommandInput::required($options, 'timezone'),
                    'search_indexing_enabled' => ($options['search-indexing-enabled'] ?? '1') === '1',
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
