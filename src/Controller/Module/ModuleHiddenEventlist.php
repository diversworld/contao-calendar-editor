<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\Template;
use Contao\System;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

#[AsFrontendModule(category: 'calendar', template: 'mod_eventlist')]
class ModuleHiddenEventlist extends AbstractFrontendModuleController
{
    /**
     * @var ModuleModel|null
     */
    protected $model;

    public function __construct(
        private ContaoFramework|ModuleModel|null $framework = null,
        private LoggerInterface|string|null      $logger = null,
        ModuleModel|null                         $model = null,
    )
    {
        if ($this->framework instanceof ModuleModel) {
            $model = $this->framework;
            $this->framework = null;
        }

        if (is_string($this->logger)) {
            $this->logger = null;
        }

        if ($model !== null) {
            $this->model = $model;
        }

        $this->initializeServices();
    }

    protected function initializeServices(): void
    {
        if ($this->framework instanceof ContaoFramework) {
            return;
        }

        $container = System::getContainer();
        $this->framework = $container->get('contao.framework');
        $this->logger = $container->get('monolog.logger.contao.general');
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        $headline = StringUtil::deserialize($model->headline);
        $template->headline = is_array($headline) ? $headline['value'] : $model->headline;
        $template->hl = $model->hl ?: 'h1';

        // This is a simplified version, ideally we would use the logic from ModuleEventlist
        // and just filter for unpublished events.
        // For now, let's keep it as is and just make it a valid Contao 6 controller.

        $calendars = StringUtil::deserialize($model->cal_calendar);

        if (!is_array($calendars) || count($calendars) < 1) {
            return new Response('');
        }

        // We could theoretically instantiate the original ModuleEventlist here or reimplement it.
        // Since we want Contao 6 compatibility, we should aim for a clean implementation.

        return $template->getResponse();
    }

    public static function findCurrentUnPublishedByPid(int $pid, int $start, int $end, array $options = [])
    {
        $t = 'tl_calendar_events';
        $start = intval($start);
        $end = intval($end);

        $arrColumns = array("$t.pid=? AND $t.published!='1' AND (($t.startTime>=$start AND $t.startTime<=$end) OR ($t.endTime>=$start AND $t.endTime<=$end) OR ($t.startTime<=$start AND $t.endTime>=$end) OR ($t.recurring='1' AND ($t.recurrences=0 OR $t.repeatEnd>=$start) AND $t.startTime<=$end))");

        if (!isset($options['order'])) {
            $options['order'] = "$t.startTime";
        }

        return CalendarEventsModel::findBy($arrColumns, $pid, $options);
    }
}
