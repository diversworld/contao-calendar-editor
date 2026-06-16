<?php

namespace Diversworld\CalendarEditorBundle\Dca;

use Contao\BackendUser;
use Contao\CalendarModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Twig\Finder\FinderFactory;
use Symfony\Bundle\SecurityBundle\Security;

class ModuleDca
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Security        $security,
        private readonly FinderFactory $twigFinderFactory,
    )
    {
    }

    #[AsCallback(table: 'tl_module', target: 'fields.caledit_template.options')]
    #[AsCallback(table: 'tl_module', target: 'fields.caledit_clone_template.options')]
    #[AsCallback(table: 'tl_module', target: 'fields.caledit_delete_template.options')]
    public function getEventEditorTemplates(): array
    {
        return $this->getTwigTemplates('event_') + [
                'event_editor' => 'event_editor',
                'event_edit_delete' => 'event_edit_delete',
                'event_edit_duplicate' => 'event_edit_duplicate',
            ];
    }

    #[AsCallback(table: 'tl_module', target: 'fields.caledit_mailTemplate.options')]
    public function getEventMailTemplates(): array
    {
        return $this->getTwigTemplates('mail_event_') + [
                'mail_event_notification' => 'mail_event_notification',
                'mail_event_subject_delete' => 'mail_event_subject_delete',
                'mail_event_subject_edit' => 'mail_event_subject_edit',
            ];
    }

    private function getTwigTemplates(string $prefix): array
    {
        $options = [];
        $pattern = '/^frontend_module\/' . preg_quote($prefix, '/') . '/';

        foreach ($this->twigFinderFactory->create()->identifierRegex($pattern)->extension('html.twig')->asIdentifierList() as $identifier) {
            $name = basename($identifier);
            $options[$name] = $name;
        }

        ksort($options);

        return $options;
    }

    #[AsCallback(table: 'tl_module', target: 'fields.caledit_cssValues.eval.columnsCallback')]
    public function getCSSValues(): array
    {
        return [
            'label' => [
                'label' => &$GLOBALS['TL_LANG']['tl_module']['css_label'],
                'mandatory' => true,
                'inputType' => 'text',
                'eval' => ['style' => 'width:100px']
            ],
            'value' => [
                'label' => &$GLOBALS['TL_LANG']['tl_module']['css_value'],
                'mandatory' => true,
                'inputType' => 'text',
                'eval' => ['rgxp' => 'alpha', 'style' => 'width:70px']
            ]
        ];
    }

    #[AsCallback(table: 'tl_module', target: 'fields.cal_holidayCalendar.options')]
    public function getCalendars(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof BackendUser) {
            return [];
        }

        if (!$this->security->isGranted('ROLE_ADMIN') && !is_array($user->calendars)) {
            return [];
        }

        $arrCalendars = [];
        $objCalendars = $this->framework->getAdapter(CalendarModel::class)->findAll(['order' => 'title']);

        if (null === $objCalendars) {
            return [];
        }

        foreach ($objCalendars as $calendar) {
            if ($this->security->isGranted('ROLE_ADMIN') || $user->hasAccess($calendar->id, 'calendars')) {
                $arrCalendars[$calendar->id] = $calendar->title;
            }
        }

        return $arrCalendars;
    }

    #[AsCallback(table: 'tl_module', target: 'fields.caledit_tinMCEtemplate.options')]
    public function getConfigFiles(): array
    {
        $arrConfigs = [];
        $tinyMCEPath = dirname(__DIR__, 2) . '/contao/tinyMCE/';

        if (is_dir($tinyMCEPath)) {
            $arrFiles = scandir($tinyMCEPath);

            foreach ($arrFiles as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $arrConfigs[] = basename($file, '.php');
                }
            }
        }

        return $arrConfigs;
    }
}
