<?php

namespace Diversworld\CalendarEditorBundle\Dca;

use Contao\Backend;
use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Bundle\SecurityBundle\Security;

class ModuleDca extends Backend
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Security        $security,
        private readonly string          $projectDir,
    )
    {
        parent::__construct();
    }

    public function getEventEditTemplates(): array
    {
        return $this->getTemplateGroup('eventEdit_');
    }

    public function getEventMailTemplates(): array
    {
        return $this->getTemplateGroup('mail_event_');
    }

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
        $objCalendars = $this->Database->execute("SELECT id, title FROM tl_calendar ORDER BY title");

        while ($objCalendars->next()) {
            if ($user->hasAccess($objCalendars->id, 'calendars')) {
                $arrCalendars[$objCalendars->id] = $objCalendars->title;
            }
        }

        return $arrCalendars;
    }

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
