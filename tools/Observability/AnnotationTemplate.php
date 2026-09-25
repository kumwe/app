<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Reads and renders the one annotation template form the rule file allows: `{{ $labels.name }}`.
 *
 * Prometheus annotations are Go templates, which can call functions, format `$value` and branch. The shipped
 * rules restrict themselves to label interpolation so that an annotation says the same thing in the page, in the
 * runbook and in a drill's expectation, and so that a template can never fail at evaluation time on a label the
 * alert does not carry. Anything else between `{{` and `}}` is refused.
 *
 * @since  2.0.0
 */
final class AnnotationTemplate
{
    /**
     * List the labels a template interpolates.
     *
     * @param   string  $template  Annotation text.
     * @param   string  $subject   Alert and annotation, for violations.
     *
     * @return  list<string>  Label names in order of appearance.
     *
     * @throws  RuleViolation  When the template uses anything but `{{ $labels.name }}`.
     *
     * @since   2.0.0
     */
    public static function labels(string $template, string $subject): array
    {
        preg_match_all('/\{\{(.*?)\}\}/s', $template, $actions);
        $labels = [];
        foreach ($actions[1] as $action) {
            if (preg_match('/^\s*\$labels\.([a-zA-Z_][a-zA-Z0-9_]*)\s*$/D', $action, $match) !== 1) {
                throw RuleViolation::at($subject, sprintf('the template action `{{%s}}` is not `{{ $labels.name }}`', $action));
            }
            $labels[] = $match[1];
        }
        if (substr_count($template, '{{') !== count($actions[1])) {
            throw RuleViolation::at($subject, 'a template action is never closed');
        }

        return $labels;
    }

    /**
     * Render a template with the alert's labels, as Prometheus would.
     *
     * @param   string                 $template  Annotation text.
     * @param   array<string, string>  $labels    Labels of the firing alert.
     *
     * @return  string  The rendered annotation; a missing label renders empty, as in Prometheus.
     *
     * @since   2.0.0
     */
    public static function render(string $template, array $labels): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*\$labels\.([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            static fn (array $match): string => $labels[$match[1]] ?? '',
            $template,
        );
    }
}
