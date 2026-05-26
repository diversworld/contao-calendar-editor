<?php

namespace Diversworld\CalendarEditorBundle\Dca;

use Contao\BackendUser;
use Contao\CalendarModel;
use Contao\Controller;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Symfony\Bundle\SecurityBundle\Security;

class ModuleDca
{
    public function __construct(
        private readonly ContaoFramework  $framework,
        private readonly Security         $security,
        private readonly CheckAuthService $checkAuthService,
        private readonly string           $projectDir,
    )
    {
    }

    #[AsCallback(table: 'tl_module', target: 'fields.caledit_template.options')]
    #[AsCallback(table: 'tl_module', target: 'fields.caledit_clone_template.options')]
    #[AsCallback(table: 'tl_module', target: 'fields.caledit_delete_template.options')]
    public function getEventEditTemplates(): array
    {
        return $this->getTwigTemplates('eventEdit_');
    }

    #[AsCallback(table: 'tl_module', target: 'fields.caledit_mailTemplate.options')]
    public function getEventMailTemplates(): array
    {
        return $this->getTwigTemplates('mail_event_');
    }

    #[AsCallback(table: 'tl_module', target: 'fields.cal_template.options')]
    public function getCalendarTemplates(): array
    {
        return $this->getTwigTemplates('cal_');
    }

    private function getTwigTemplates(string $prefix): array
    {
        $templates = $this->framework->getAdapter(Controller::class)->getTemplateGroup($prefix);
        $templateDir = dirname(__DIR__, 2) . '/contao/templates/frontend_module';

        if (is_dir($templateDir)) {
            $files = scandir($templateDir);
            foreach ($files as $file) {
                if (str_starts_with($file, $prefix) && str_ends_with($file, '.html.twig')) {
                    $name = substr($file, 0, -10);
                    $key = 'frontend_module/' . $name;
                    $templates[$key] = $name;
                }
            }
        }

        return $templates;
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
        $tinyMCEPath = $this->projectDir . '/vendor/diversworld/contao-calendar-editor/contao/tinyMCE/';

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
