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
use Contao\System;
use Contao\CoreBundle\Twig\FragmentTemplate;
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

    public function generate(): string
    {
        if (System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest(System::getContainer()->get('request_stack')->getCurrentRequest())) {
            $objTemplate = new \Contao\BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### ' . $GLOBALS['TL_LANG']['FMD']['EventHiddenList'][0] . ' ###';
            $objTemplate->title = $this->model->headline;
            $objTemplate->id = $this->model->id;
            $objTemplate->link = $this->model->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->model->id;

            return $objTemplate->parse();
        }

        return '';
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $headline = StringUtil::deserialize($model->headline);
        $template->headline = is_array($headline) ? $headline['value'] : $model->headline;
        $template->hl = $model->hl ?: 'h1';

        $calendars = StringUtil::deserialize($model->cal_calendar);

        if (!is_array($calendars) || count($calendars) < 1) {
            return new Response('');
        }

        return $template->getResponse();
    }
}
